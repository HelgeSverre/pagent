<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\ProviderCapabilities;
use Pagent\Streaming\OllamaStreamParser;
use Pagent\Streaming\StreamResponse;
use Pagent\Tool\ToolCallArgumentNormalizer;
use RuntimeException;

use function array_unshift;
use function getenv;
use function json_decode;

final class Ollama implements IdentifiedProvider, StreamingProvider
{
    /** @var list<string> */
    private const MODEL_OPTION_KEYS = [
        'seed', 'num_predict', 'top_k', 'top_p', 'min_p', 'typical_p',
        'repeat_last_n', 'repeat_penalty', 'presence_penalty',
        'frequency_penalty', 'mirostat', 'mirostat_tau', 'mirostat_eta',
        'penalize_newline', 'stop', 'numa', 'num_ctx', 'num_batch',
        'num_gpu', 'main_gpu', 'low_vram', 'vocab_only', 'use_mmap',
        'use_mlock', 'num_thread',
    ];

    private string $baseUrl;

    private int $timeout;

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->baseUrl = $config['base_url']
            ?? $_ENV['OLLAMA_HOST']
            ?? getenv('OLLAMA_HOST')
            ?: 'http://localhost:11434';

        $this->timeout = $config['timeout'] ?? 120;

        // Remove trailing slash from base URL
        $this->baseUrl = rtrim($this->baseUrl, '/');

        $this->httpClient = $httpClient ?? new CurlTransport;
    }

    public function prompt(string $message, array $options = []): object
    {
        $messages = $options['messages'] ?? [];

        // Add system message if provided (Ollama handles system messages like OpenAI)
        if (isset($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        // If no messages provided, use the prompt
        if (empty($messages)) {
            $messages = [['role' => 'user', 'content' => $message]];
        }

        // Build request body
        $body = [
            'model' => $options['model'] ?? 'qwen3:8b',
            'messages' => $messages,
            'stream' => false,
        ];

        $this->applyModelOptions($body, $options);

        // Add tools if provided
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional Ollama-specific options
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'temperature', 'max_tokens', 'tools', 'options', ...self::MODEL_OPTION_KEYS], true)) {
                $body[$key] = $value;
            }
        }

        // Make API call using HttpClient
        $response = $this->httpClient->requestJson(
            method: 'POST',
            url: $this->baseUrl.'/api/chat',
            headers: [
                'Content-Type' => 'application/json',
            ],
            json: $body,
            options: ['timeout' => $this->timeout]
        );

        if (! $response->isSuccessful()) {
            $data = $response->json();
            $error = $data['error'] ?? 'Unknown error';
            throw new RuntimeException("Ollama API error: {$error}");
        }

        $data = $response->json();

        // Check if the response has an error field (even with 200 status)
        if (isset($data['error'])) {
            throw new RuntimeException("Ollama API error: {$data['error']}");
        }

        $message = $data['message'] ?? [];
        $toolCalls = [];

        // Extract tool calls if present
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                $name = is_string($toolCall['function']['name'] ?? null)
                    ? $toolCall['function']['name']
                    : 'unknown';

                $toolCalls[] = [
                    'id' => $toolCall['id'] ?? uniqid('call_'),
                    'name' => $name,
                    'arguments' => ToolCallArgumentNormalizer::normalize(
                        $toolCall['function']['arguments'] ?? null,
                        "Ollama tool '{$name}'",
                    ),
                ];
            }
        }

        // Calculate total tokens from Ollama's token counts
        $promptTokens = $data['prompt_eval_count'] ?? 0;
        $completionTokens = $data['eval_count'] ?? 0;
        $totalTokens = $promptTokens + $completionTokens;

        return (object) [
            'content' => $message['content'] ?? '',
            'model' => $data['model'] ?? $body['model'],
            'tokens' => $totalTokens,
            'provider' => 'ollama',
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ],
            'finish_reason' => $data['done'] ? 'stop' : null,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Stream a prompt to the LLM and get a streaming response
     */
    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $messages = $options['messages'] ?? [];

        // Add system message if provided
        if (isset($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        // If no messages provided, use the prompt
        if (empty($messages)) {
            $messages = [['role' => 'user', 'content' => $message]];
        }

        // Build request body
        $body = [
            'model' => $options['model'] ?? 'qwen3:8b',
            'messages' => $messages,
            'stream' => true, // Enable streaming
        ];

        $this->applyModelOptions($body, $options);

        // Add tools if provided
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional Ollama-specific options
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'temperature', 'max_tokens', 'tools', 'options', ...self::MODEL_OPTION_KEYS], true)) {
                $body[$key] = $value;
            }
        }

        // Make streaming API call using HttpClient
        $transport = $this->httpClient->streamJson(
            method: 'POST',
            url: $this->baseUrl.'/api/chat',
            headers: [
                'Content-Type' => 'application/json',
            ],
            json: $body,
            options: ['timeout' => 0]
        );

        if (! ($transport->status() >= 200 && $transport->status() < 300)) {
            $content = $transport->getContent();
            $data = json_decode($content, true);
            $error = $data['error'] ?? 'Unknown error';
            throw new RuntimeException("Ollama API error: {$error}");
        }

        $parser = new OllamaStreamParser;
        $model = $body['model'];

        return new StreamResponse(
            stream: $parser->parse($transport->chunks(), $model),
            provider: 'ollama',
            model: $model,
            canceller: static function () use ($transport): void {
                $transport->close();
            },
        );
    }

    public function providerId(): string
    {
        return 'ollama';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsStreaming: true,
            supportsTools: true,
            supportsSystemMessages: true,
            supportsStructuredOutput: true,
            protocol: 'ollama-chat',
            toolProtocol: 'openai',
        );
    }

    /**
     * Map Pagent's common generation options and Ollama's shorthand model
     * options to the nested `options` object expected by /api/chat.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $options
     */
    private function applyModelOptions(array &$body, array $options): void
    {
        $modelOptions = is_array($options['options'] ?? null) ? $options['options'] : [];

        foreach (self::MODEL_OPTION_KEYS as $key) {
            if (array_key_exists($key, $options)) {
                $modelOptions[$key] = $options[$key];
            }
        }

        if (array_key_exists('temperature', $options)) {
            $modelOptions['temperature'] = $options['temperature'];
        }
        if (array_key_exists('max_tokens', $options)) {
            $modelOptions['num_predict'] = $options['max_tokens'];
        }

        if ($modelOptions !== []) {
            $body['options'] = $modelOptions;
        }
    }
}
