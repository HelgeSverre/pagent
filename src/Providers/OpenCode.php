<?php

declare(strict_types=1);

namespace Pagent\Providers;

use InvalidArgumentException;
use LogicException;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\ProviderCapabilities;
use Pagent\Streaming\AnthropicStreamParser;
use Pagent\Streaming\OpenAIResponsesStreamParser;
use Pagent\Streaming\OpenAIStreamParser;
use Pagent\Streaming\StreamResponse;
use Pagent\Tool\ToolCallArgumentNormalizer;
use RuntimeException;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_unshift;
use function getenv;
use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function str_replace;

/**
 * OpenCode Zen and Go provider.
 *
 * Select a default protocol with "protocol", model-specific overrides through
 * "model_protocols", or override the protocol per prompt.
 */
final class OpenCode implements IdentifiedProvider, StreamingProvider
{
    private const PROTOCOL_CHAT_COMPLETIONS = 'chat-completions';

    private const PROTOCOL_RESPONSES = 'responses';

    private const PROTOCOL_MESSAGES = 'messages';

    private string $apiKey;

    private string $baseUrl;

    private string $gateway;

    private int $timeout;

    private string $protocol;

    /** @var array<string, string> */
    private array $modelProtocols;

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->apiKey = $config['api_key'] ?? $_ENV['OPENCODE_API_KEY'] ?? getenv('OPENCODE_API_KEY') ?: '';
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenCode API key not configured');
        }

        $this->gateway = $config['gateway'] ?? 'zen';
        if (! in_array($this->gateway, ['zen', 'go'], true)) {
            throw new InvalidArgumentException("Unknown OpenCode gateway: {$this->gateway}");
        }

        $defaultBaseUrl = $this->gateway === 'go'
            ? 'https://opencode.ai/zen/go/v1'
            : 'https://opencode.ai/zen/v1';

        $this->baseUrl = rtrim($config['base_url'] ?? $defaultBaseUrl, '/');
        $this->timeout = $config['timeout'] ?? 30;
        $this->protocol = $this->normalizeProtocol($config['protocol'] ?? self::PROTOCOL_CHAT_COMPLETIONS);
        $this->modelProtocols = $this->normalizeModelProtocols($config['model_protocols'] ?? []);
        $this->httpClient = $httpClient ?? new CurlTransport;
    }

    public function prompt(string $message, array $options = []): object
    {
        $request = $this->buildRequest($message, $options);
        $model = $this->requestModel($request);

        $response = $this->httpClient->requestJson(
            method: 'POST',
            url: $this->urlFor($request['protocol']),
            headers: $this->headers(),
            json: $request['body'],
            options: ['timeout' => $this->timeout],
        );

        if (! $response->isSuccessful()) {
            throw new RuntimeException('OpenCode API error: '.$this->errorMessage($response->json()));
        }

        return $this->normalizeResponse($response->json(), $model, $request['protocol']);
    }

    /**
     * Stream a prompt using the selected OpenCode protocol.
     */
    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $request = $this->buildRequest($message, $options, stream: true);
        $model = $this->requestModel($request);

        $transport = $this->httpClient->streamJson(
            method: 'POST',
            url: $this->urlFor($request['protocol']),
            headers: $this->headers(),
            json: $request['body'],
            options: ['timeout' => 0],
        );

        if (! ($transport->status() >= 200 && $transport->status() < 300)) {
            $data = json_decode($transport->getContent(), true);
            throw new RuntimeException('OpenCode API error: '.$this->errorMessage(is_array($data) ? $data : []));
        }

        $parser = match ($request['protocol']) {
            self::PROTOCOL_CHAT_COMPLETIONS => new OpenAIStreamParser,
            self::PROTOCOL_RESPONSES => new OpenAIResponsesStreamParser,
            self::PROTOCOL_MESSAGES => new AnthropicStreamParser,
            default => throw new LogicException('Unsupported OpenCode protocol'),
        };

        return new StreamResponse(
            stream: $parser->parse($transport->chunks(), $model),
            provider: 'opencode',
            model: $model,
            canceller: static function () use ($transport): void {
                $transport->close();
            },
        );
    }

    /**
     * @return array{protocol: string, body: array<string, mixed>}
     */
    private function buildRequest(string $message, array $options, bool $stream = false): array
    {
        $model = (string) ($options['model'] ?? $this->defaultModel());
        $protocol = $this->resolveProtocol($model, $options);
        $messages = $this->messages($message, $options);

        $body = match ($protocol) {
            self::PROTOCOL_CHAT_COMPLETIONS => ['model' => $model, 'messages' => $messages] + $this->passthroughOptions($options),
            self::PROTOCOL_RESPONSES => $this->responsesBody($model, $messages, $options),
            self::PROTOCOL_MESSAGES => $this->messagesBody($model, $messages, $options),
            default => throw new LogicException('Unsupported OpenCode protocol'),
        };

        if ($stream) {
            $body['stream'] = true;
        }

        return ['protocol' => $protocol, 'body' => $body];
    }

    /** @return array<int, array<string, mixed>> */
    private function messages(string $message, array $options): array
    {
        $messages = $options['messages'] ?? [];
        if (! is_array($messages)) {
            throw new InvalidArgumentException('OpenCode messages must be an array');
        }

        if ($messages === []) {
            $messages = [['role' => 'user', 'content' => $message]];
        }

        if (isset($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    private function responsesBody(string $model, array $messages, array $options): array
    {
        $body = ['model' => $model, 'input' => $this->responsesInput($messages)];
        foreach ($this->passthroughOptions($options) as $key => $value) {
            if ($key === 'max_tokens') {
                $body['max_output_tokens'] = $value;
            } elseif ($key === 'tools') {
                $body['tools'] = $this->responsesTools($value);
            } else {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function messagesBody(string $model, array $messages, array $options): array
    {
        $system = [];
        $anthropicMessages = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';
            if ($role === 'system' || $role === 'developer') {
                $system[] = $this->stringContent($content);

                continue;
            }

            if ($role === 'tool') {
                $toolResult = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message['tool_call_id'] ?? '',
                    'content' => $this->stringContent($content),
                ];
                $lastIndex = array_key_last($anthropicMessages);
                if ($lastIndex !== null && $anthropicMessages[$lastIndex]['role'] === 'user' && is_array($anthropicMessages[$lastIndex]['content'])) {
                    $anthropicMessages[$lastIndex]['content'][] = $toolResult;
                } else {
                    $anthropicMessages[] = ['role' => 'user', 'content' => [$toolResult]];
                }

                continue;
            }

            if (! in_array($role, ['user', 'assistant'], true)) {
                throw new InvalidArgumentException("The messages protocol does not support the {$role} role directly");
            }

            if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                $blocks = $content === '' ? [] : [['type' => 'text', 'text' => $this->stringContent($content)]];
                foreach ($message['tool_calls'] as $toolCall) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall['id'] ?? '',
                        'name' => $toolCall['function']['name'] ?? '',
                        'input' => $this->toolArguments($toolCall['function']['arguments'] ?? []),
                    ];
                }
                $anthropicMessages[] = ['role' => 'assistant', 'content' => $blocks];
            } else {
                $anthropicMessages[] = ['role' => $role, 'content' => $content];
            }
        }

        if ($anthropicMessages === []) {
            throw new InvalidArgumentException('The messages protocol requires at least one user or assistant message');
        }

        $body = [
            'model' => $model,
            'messages' => $anthropicMessages,
            'max_tokens' => $options['max_tokens'] ?? 1024,
        ];
        if ($system !== []) {
            $body['system'] = implode("\n\n", $system);
        }

        foreach ($this->passthroughOptions($options) as $key => $value) {
            if ($key === 'tools') {
                $body['tools'] = $this->messagesTools($value);
            } elseif ($key !== 'max_tokens') {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    /** @return array<int, array<string, mixed>> */
    private function responsesInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            if ($role === 'tool') {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $message['tool_call_id'] ?? '',
                    'output' => $this->stringContent($message['content'] ?? ''),
                ];

                continue;
            }
            if ($role === 'system') {
                $role = 'developer';
            }
            if (! in_array($role, ['developer', 'user', 'assistant'], true)) {
                throw new InvalidArgumentException("The responses protocol does not support the {$role} role directly");
            }

            if (($message['content'] ?? '') !== '' || empty($message['tool_calls'])) {
                $input[] = [
                    'role' => $role,
                    'content' => [[
                        'type' => $role === 'assistant' ? 'output_text' : 'input_text',
                        'text' => $this->stringContent($message['content'] ?? ''),
                    ]],
                ];
            }

            if ($role === 'assistant') {
                foreach ($message['tool_calls'] ?? [] as $toolCall) {
                    $function = $toolCall['function'] ?? [];
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => $toolCall['id'] ?? '',
                        'name' => $function['name'] ?? '',
                        'arguments' => $this->toolArgumentsJson($function['arguments'] ?? []),
                    ];
                }
            }
        }

        return $input;
    }

    /** @return array<int, array<string, mixed>> */
    private function responsesTools(mixed $tools): array
    {
        return $this->convertTools($tools, static fn (array $function): array => [
            'type' => 'function',
            'name' => $function['name'] ?? '',
            'description' => $function['description'] ?? '',
            'parameters' => $function['parameters'] ?? ['type' => 'object', 'properties' => []],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function messagesTools(mixed $tools): array
    {
        return $this->convertTools($tools, static fn (array $function): array => [
            'name' => $function['name'] ?? '',
            'description' => $function['description'] ?? '',
            'input_schema' => $function['parameters'] ?? ['type' => 'object', 'properties' => []],
        ]);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $convert
     * @return array<int, array<string, mixed>>
     */
    private function convertTools(mixed $tools, callable $convert): array
    {
        if (! is_array($tools)) {
            throw new InvalidArgumentException('OpenCode tools must be an array');
        }

        return array_map(function (mixed $tool) use ($convert): array {
            if (! is_array($tool)) {
                throw new InvalidArgumentException('Each OpenCode tool must be an array');
            }

            return is_array($tool['function'] ?? null) ? $convert($tool['function']) : $tool;
        }, $tools);
    }

    /** @return array<string, mixed> */
    private function passthroughOptions(array $options): array
    {
        return array_filter(
            $options,
            static fn (mixed $value, string $key): bool => ! in_array($key, ['messages', 'system', 'model', 'protocol'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function normalizeResponse(array $data, string $model, string $protocol): object
    {
        return match ($protocol) {
            self::PROTOCOL_CHAT_COMPLETIONS => $this->normalizeChatCompletionsResponse($data, $model),
            self::PROTOCOL_RESPONSES => $this->normalizeResponsesResponse($data, $model),
            self::PROTOCOL_MESSAGES => $this->normalizeMessagesResponse($data, $model),
            default => throw new LogicException('Unsupported OpenCode protocol'),
        };
    }

    private function normalizeChatCompletionsResponse(array $data, string $model): object
    {
        $message = $data['choices'][0]['message'] ?? [];
        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            $name = is_string($toolCall['function']['name'] ?? null)
                ? $toolCall['function']['name']
                : 'unknown';
            $toolCalls[] = [
                'id' => $toolCall['id'] ?? '',
                'name' => $name,
                'arguments' => ToolCallArgumentNormalizer::normalize(
                    $toolCall['function']['arguments'] ?? null,
                    "OpenCode tool '{$name}'",
                ),
            ];
        }

        return $this->response(
            $message['content'] ?? '',
            $data['model'] ?? $model,
            $data['usage'] ?? null,
            $data['choices'][0]['finish_reason'] ?? null,
            $toolCalls,
        );
    }

    private function normalizeResponsesResponse(array $data, string $model): object
    {
        $content = ! isset($data['output']) || $data['output'] === []
            ? ($data['output_text'] ?? '')
            : '';
        $toolCalls = [];
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? null) === 'output_text') {
                        $content .= $part['text'] ?? '';
                    }
                }
            }
            if (($item['type'] ?? null) === 'function_call') {
                $name = is_string($item['name'] ?? null) ? $item['name'] : 'unknown';
                $toolCalls[] = [
                    'id' => $item['call_id'] ?? $item['id'] ?? '',
                    'name' => $name,
                    'arguments' => ToolCallArgumentNormalizer::normalize(
                        $item['arguments'] ?? null,
                        "OpenCode tool '{$name}'",
                    ),
                ];
            }
        }

        return $this->response(
            $content,
            $data['model'] ?? $model,
            $data['usage'] ?? null,
            $data['status'] ?? ($data['incomplete_details']['reason'] ?? null),
            $toolCalls,
        );
    }

    private function normalizeMessagesResponse(array $data, string $model): object
    {
        $content = '';
        $toolCalls = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $content .= $block['text'] ?? '';
            }
            if (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? '',
                    'name' => $block['name'] ?? '',
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        return $this->response(
            $content,
            $data['model'] ?? $model,
            $data['usage'] ?? null,
            $data['stop_reason'] ?? null,
            $toolCalls,
        );
    }

    /** @param array<string, mixed>|null $usage */
    private function response(string $content, string $model, ?array $usage, ?string $finishReason, array $toolCalls): object
    {
        $tokens = $usage['total_tokens'] ?? (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0));

        return (object) [
            'content' => $content,
            'model' => $model,
            'tokens' => $tokens,
            'provider' => 'opencode',
            'usage' => $usage,
            'finish_reason' => $finishReason,
            'tool_calls' => $toolCalls,
        ];
    }

    private function resolveProtocol(string $model, array $options): string
    {
        return isset($options['protocol'])
            ? $this->normalizeProtocol($options['protocol'])
            : ($this->modelProtocols[$model] ?? $this->protocol);
    }

    private function urlFor(string $protocol): string
    {
        return $this->baseUrl.match ($protocol) {
            self::PROTOCOL_CHAT_COMPLETIONS => '/chat/completions',
            self::PROTOCOL_RESPONSES => '/responses',
            self::PROTOCOL_MESSAGES => '/messages',
            default => throw new LogicException('Unsupported OpenCode protocol'),
        };
    }

    private function normalizeProtocol(mixed $protocol): string
    {
        if (! is_string($protocol)) {
            throw new InvalidArgumentException('OpenCode protocol must be a string');
        }

        $protocol = str_replace('_', '-', $protocol);
        if (! in_array($protocol, [self::PROTOCOL_CHAT_COMPLETIONS, self::PROTOCOL_RESPONSES, self::PROTOCOL_MESSAGES], true)) {
            throw new InvalidArgumentException("Unknown OpenCode protocol: {$protocol}");
        }

        return $protocol;
    }

    /** @return array<string, string> */
    private function normalizeModelProtocols(mixed $modelProtocols): array
    {
        if (! is_array($modelProtocols)) {
            throw new InvalidArgumentException('OpenCode model_protocols must be an array');
        }

        $normalized = [];
        foreach ($modelProtocols as $model => $protocol) {
            if (! is_string($model)) {
                throw new InvalidArgumentException('OpenCode model_protocols keys must be model names');
            }
            $normalized[$model] = $this->normalizeProtocol($protocol);
        }

        return $normalized;
    }

    private function stringContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;

                    continue;
                }
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $parts[] = $part['text'];

                    continue;
                }

                throw new InvalidArgumentException('OpenCode content parts must be strings or text parts');
            }

            return implode('', $parts);
        }

        throw new InvalidArgumentException('OpenCode message content must be a string or content-part array');
    }

    /** @return array<string, mixed> */
    private function toolArguments(mixed $arguments): array
    {
        return ToolCallArgumentNormalizer::normalize($arguments, 'OpenCode tool history');
    }

    private function toolArgumentsJson(mixed $arguments): string
    {
        if (is_string($arguments)) {
            return $arguments;
        }

        return json_encode($this->toolArguments($arguments), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{protocol: string, body: array<string, mixed>}  $request
     */
    private function requestModel(array $request): string
    {
        $model = $request['body']['model'] ?? null;
        if (! is_string($model)) {
            throw new LogicException('OpenCode requests must include a string model');
        }

        return $model;
    }

    private function errorMessage(array $data): string
    {
        $error = $data['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }
        if (is_string($error)) {
            return $error;
        }

        return 'Unknown error';
    }

    private function defaultModel(): string
    {
        return $this->gateway === 'go' ? 'ox-alpha-free' : 'x-preview-f-free';
    }

    public function providerId(): string
    {
        return 'opencode';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsStreaming: true,
            supportsTools: true,
            supportsSystemMessages: true,
            supportsStructuredOutput: true,
            protocol: 'opencode-multi-protocol',
            toolProtocol: 'openai',
        );
    }

    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->apiKey,
        ];
    }
}
