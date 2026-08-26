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
use Pagent\Streaming\AnthropicStreamParser;
use Pagent\Streaming\StreamResponse;

use function array_key_exists;
use function in_array;
use function rtrim;

final class Anthropic implements IdentifiedProvider, StreamingProvider
{
    use ResolvesProviderConfig;

    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    private int $streamTimeout;

    private int $connectTimeout;

    private int $idleTimeout;

    private bool $retainChunks;

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->apiKey = $this->resolveApiKey($config, 'ANTHROPIC_API_KEY', 'Anthropic');
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.anthropic.com/v1', '/');
        $this->timeout = $this->nonNegativeIntegerOption($config, 'timeout', 30);
        $this->streamTimeout = $this->nonNegativeIntegerOption(
            $config,
            'stream_timeout',
            array_key_exists('timeout', $config) ? $this->timeout : 0,
        );
        $this->connectTimeout = $this->nonNegativeIntegerOption($config, 'connect_timeout', 10);
        $this->idleTimeout = $this->nonNegativeIntegerOption($config, 'idle_timeout', 30);
        $this->retainChunks = $this->booleanOption($config, 'retain_chunks', true);
        $this->httpClient = $httpClient ?? new CurlTransport($this->providerId());
    }

    public function prompt(string $message, array $options = []): Response
    {
        $body = $this->buildBody($message, $options, stream: false);

        $response = $this->httpClient->requestJson(
            method: 'POST',
            url: $this->baseUrl.'/messages',
            headers: $this->headers(),
            json: $body,
            options: ['timeout' => $this->timeout]
        );

        if (! $response->isSuccessful()) {
            $this->throwApiError($response, 'Anthropic');
        }

        $data = $response->json();

        // Extract content and tool calls
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = $this->normalizeToolCall(
                    $block['id'] ?? null,
                    $block['name'] ?? null,
                    $block['input'] ?? null,
                    'Anthropic',
                );
            }
        }

        return new Response(
            content: $content,
            model: $data['model'] ?? $body['model'],
            tokens: ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
            provider: 'anthropic',
            usage: $data['usage'] ?? null,
            stop_reason: $data['stop_reason'] ?? null,
            tool_calls: $toolCalls,
            raw_content: $data['content'] ?? [],
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
            url: $this->baseUrl.'/messages',
            headers: $this->headers(),
            json: $body,
            options: $this->streamingTransportOptions(
                $options,
                $this->streamTimeout,
                $this->connectTimeout,
                $this->idleTimeout,
            ),
        );

        $this->ensureStreamSuccessful($transport, 'Anthropic');

        $parser = new AnthropicStreamParser;
        $model = is_string($body['model']) ? $body['model'] : 'unknown';

        return new StreamResponse(
            stream: $parser->parse($transport->chunks(), $model),
            provider: 'anthropic',
            model: $model,
            releaser: static function () use ($transport): void {
                $transport->close();
            },
            retainChunks: $this->booleanOption($options, 'retain_chunks', $this->retainChunks),
        );
    }

    public function providerId(): string
    {
        return 'anthropic';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsStreaming: true,
            supportsTools: true,
            supportsSystemMessages: true,
            protocol: 'anthropic-messages',
            toolProtocol: 'anthropic',
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildBody(string $message, array $options, bool $stream): array
    {
        $body = [
            'model' => $options['model'] ?? 'claude-sonnet-4-6',
            'messages' => $options['messages'] ?? [['role' => 'user', 'content' => $message]],
            'max_tokens' => $options['max_tokens'] ?? 1024,
        ];

        if (isset($options['system'])) {
            $body['system'] = $options['system'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional Anthropic-specific options (e.g. top_p, top_k, stop_sequences)
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'max_tokens', 'tools', 'stream'], true)
                && ! $this->isStreamControlOption($key)) {
                $body[$key] = $value;
            }
        }

        if ($stream) {
            $body['stream'] = true;
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
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];
    }
}
