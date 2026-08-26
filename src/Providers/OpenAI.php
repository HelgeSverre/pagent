<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Exceptions\InvalidArgumentException;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\ProviderCapabilities;
use Pagent\Providers\Concerns\ResolvesProviderConfig;
use Pagent\Response;
use Pagent\Streaming\OpenAIStreamParser;
use Pagent\Streaming\StreamResponse;

use function array_merge;
use function array_unshift;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;

final class OpenAI implements IdentifiedProvider, StreamingProvider
{
    use ResolvesProviderConfig;

    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->apiKey = $this->resolveApiKey($config, 'OPENAI_API_KEY', 'OpenAI');
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->timeout = $config['timeout'] ?? 30;
        $this->httpClient = $httpClient ?? new CurlTransport($this->providerId());
    }

    public function prompt(string $message, array $options = []): Response
    {
        $body = $this->buildBody($message, $options, stream: false);

        $response = $this->httpClient->requestJson(
            method: 'POST',
            url: $this->baseUrl.'/chat/completions',
            headers: $this->headers(),
            json: $body,
            options: ['timeout' => $this->timeout]
        );

        if (! $response->isSuccessful()) {
            $this->throwApiError($response, 'OpenAI');
        }

        $data = $response->json();

        $choiceMessage = $data['choices'][0]['message'] ?? [];
        $toolCalls = [];

        // Extract tool calls if present
        foreach ($choiceMessage['tool_calls'] ?? [] as $toolCall) {
            $toolCalls[] = $this->normalizeToolCall(
                $toolCall['id'] ?? null,
                $toolCall['function']['name'] ?? null,
                $toolCall['function']['arguments'] ?? null,
                'OpenAI',
            );
        }

        return new Response(
            // OpenAI legitimately returns null content for assistant messages
            // that contain only tool calls.
            content: is_string($choiceMessage['content'] ?? null) ? $choiceMessage['content'] : '',
            model: is_string($data['model'] ?? null)
                ? $data['model']
                : (is_string($body['model']) ? $body['model'] : 'unknown'),
            tokens: is_numeric($data['usage']['total_tokens'] ?? null)
                ? (int) $data['usage']['total_tokens']
                : 0,
            provider: 'openai',
            usage: $data['usage'] ?? null,
            finish_reason: $data['choices'][0]['finish_reason'] ?? null,
            tool_calls: $toolCalls,
            raw: $data,
        );
    }

    /**
     * Stream a prompt to the LLM and get a streaming response
     */
    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $body = $this->buildBody($message, $options, stream: true);

        $transport = $this->httpClient->streamJson(
            method: 'POST',
            url: $this->baseUrl.'/chat/completions',
            headers: $this->headers(),
            json: $body,
            options: ['timeout' => 0]
        );

        $this->ensureStreamSuccessful($transport, 'OpenAI');

        $parser = new OpenAIStreamParser;
        $model = is_string($body['model']) ? $body['model'] : 'unknown';

        return new StreamResponse(
            stream: $parser->parse($transport->chunks(), $model),
            provider: 'openai',
            model: $model,
            canceller: static function () use ($transport): void {
                $transport->close();
            },
        );
    }

    public function providerId(): string
    {
        return 'openai';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsStreaming: true,
            supportsTools: true,
            supportsSystemMessages: true,
            supportsStructuredOutput: true,
            protocol: 'openai-chat-completions',
            toolProtocol: 'openai',
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildBody(string $message, array $options, bool $stream): array
    {
        $messages = is_array($options['messages'] ?? null) ? $options['messages'] : [];

        // Add system message if provided
        if (isset($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        // If no messages provided, use the prompt
        if (empty($messages)) {
            $messages = [['role' => 'user', 'content' => $message]];
        }

        $body = [
            'model' => $options['model'] ?? 'gpt-3.5-turbo',
            'messages' => $messages,
        ];

        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional OpenAI-specific options (e.g., response_format, seed, etc.)
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'tools', 'stream', 'stream_options'], true)) {
                $body[$key] = $value;
            }
        }

        if ($stream) {
            $body['stream'] = true;

            $streamOptions = $options['stream_options'] ?? [];
            if (! is_array($streamOptions)) {
                throw new InvalidArgumentException('OpenAI stream_options must be an array');
            }
            $body['stream_options'] = array_merge(['include_usage' => true], $streamOptions);
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->apiKey,
        ];
    }
}
