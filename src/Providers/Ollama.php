<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\ProviderCapabilities;
use Pagent\Providers\Concerns\ResolvesProviderConfig;
use Pagent\Response;
use Pagent\Streaming\OllamaStreamParser;
use Pagent\Streaming\StreamResponse;

use function array_key_exists;
use function array_unshift;
use function getenv;
use function in_array;
use function is_array;
use function rtrim;

final class Ollama implements IdentifiedProvider, StreamingProvider
{
    use ResolvesProviderConfig;

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

    private int $streamTimeout;

    private int $connectTimeout;

    private int $idleTimeout;

    private bool $retainChunks;

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->baseUrl = $config['base_url']
            ?? $_ENV['OLLAMA_HOST']
            ?? getenv('OLLAMA_HOST')
            ?: 'http://localhost:11434';

        $this->timeout = $this->nonNegativeIntegerOption($config, 'timeout', 120);
        $this->streamTimeout = $this->nonNegativeIntegerOption(
            $config,
            'stream_timeout',
            array_key_exists('timeout', $config) ? $this->timeout : 0,
        );
        $this->connectTimeout = $this->nonNegativeIntegerOption($config, 'connect_timeout', 10);
        $this->idleTimeout = $this->nonNegativeIntegerOption($config, 'idle_timeout', 30);
        $this->retainChunks = $this->booleanOption($config, 'retain_chunks', true);

        // Remove trailing slash from base URL
        $this->baseUrl = rtrim($this->baseUrl, '/');

        $this->httpClient = $httpClient ?? new CurlTransport($this->providerId());
    }

    public function prompt(string $message, array $options = []): Response
    {
        $body = $this->buildBody($message, $options, stream: false);

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
            $this->throwApiError($response, 'Ollama');
        }

        $data = $response->json();

        // Check if the response has an error field (even with 200 status)
        if (isset($data['error'])) {
            throw $this->apiException($data, $response->status, 'Ollama');
        }

        $responseMessage = $data['message'] ?? [];
        $toolCalls = [];

        // Extract tool calls if present
        foreach ($responseMessage['tool_calls'] ?? [] as $toolCall) {
            $toolCalls[] = $this->normalizeToolCall(
                $toolCall['id'] ?? null,
                $toolCall['function']['name'] ?? null,
                $toolCall['function']['arguments'] ?? null,
                'Ollama',
            );
        }

        // Calculate total tokens from Ollama's token counts
        $promptTokens = $data['prompt_eval_count'] ?? 0;
        $completionTokens = $data['eval_count'] ?? 0;
        $totalTokens = $promptTokens + $completionTokens;

        return new Response(
            content: $responseMessage['content'] ?? '',
            model: $data['model'] ?? $body['model'],
            tokens: $totalTokens,
            provider: 'ollama',
            usage: [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ],
            finish_reason: ($data['done'] ?? false) === true ? 'stop' : null,
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
            url: $this->baseUrl.'/api/chat',
            headers: [
                'Content-Type' => 'application/json',
            ],
            json: $body,
            options: $this->streamingTransportOptions(
                $options,
                $this->streamTimeout,
                $this->connectTimeout,
                $this->idleTimeout,
            ),
        );

        $this->ensureStreamSuccessful($transport, 'Ollama');

        $parser = new OllamaStreamParser;
        $model = is_string($body['model']) ? $body['model'] : 'unknown';

        return new StreamResponse(
            stream: $parser->parse($transport->chunks(), $model),
            provider: 'ollama',
            model: $model,
            releaser: static function () use ($transport): void {
                $transport->close();
            },
            retainChunks: $this->booleanOption($options, 'retain_chunks', $this->retainChunks),
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildBody(string $message, array $options, bool $stream): array
    {
        $messages = is_array($options['messages'] ?? null) ? $options['messages'] : [];

        // Add system message if provided (Ollama handles system messages like OpenAI)
        if (isset($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        // If no messages provided, use the prompt
        if (empty($messages)) {
            $messages = [['role' => 'user', 'content' => $message]];
        }

        $body = [
            'model' => $options['model'] ?? 'qwen3:8b',
            'messages' => $messages,
            'stream' => $stream,
        ];

        $this->applyModelOptions($body, $options);

        // Add tools if provided
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional Ollama-specific options
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'temperature', 'max_tokens', 'tools', 'options', 'stream', ...self::MODEL_OPTION_KEYS], true)
                && ! $this->isStreamControlOption($key)) {
                $body[$key] = $value;
            }
        }

        return $body;
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
