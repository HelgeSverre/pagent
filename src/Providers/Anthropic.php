<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\ProviderCapabilities;
use Pagent\Streaming\AnthropicStreamParser;
use Pagent\Streaming\StreamResponse;
use Pagent\Tool\ToolCallArgumentNormalizer;
use RuntimeException;

use function getenv;
use function json_decode;

final class Anthropic implements IdentifiedProvider, StreamingProvider
{
    private string $apiKey;

    private string $baseUrl = 'https://api.anthropic.com/v1';

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->apiKey = $config['api_key'] ?? $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
        if (empty($this->apiKey)) {
            throw new RuntimeException('Anthropic API key not configured');
        }

        $this->httpClient = $httpClient ?? new CurlTransport;
    }

    public function prompt(string $message, array $options = []): object
    {
        $messages = $options['messages'] ?? [['role' => 'user', 'content' => $message]];
        $system = $options['system'] ?? null;

        // Build request body
        $body = [
            'model' => $options['model'] ?? 'claude-sonnet-4-6',
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 1024,
        ];

        if ($system) {
            $body['system'] = $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        // Add tools if provided
        if (isset($options['tools']) && ! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Make API call using HttpClient
        $response = $this->httpClient->requestJson(
            method: 'POST',
            url: $this->baseUrl.'/messages',
            headers: [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            json: $body,
            options: ['timeout' => 30]
        );

        if (! $response->isSuccessful()) {
            $data = $response->json();
            $type = $data['error']['type'] ?? 'Unknown type';
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new RuntimeException("Anthropic API error: {$type} {$error}");
        }

        $data = $response->json();

        // Extract content and tool calls
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $name = is_string($block['name'] ?? null) ? $block['name'] : 'unknown';
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $name,
                    'arguments' => ToolCallArgumentNormalizer::normalize(
                        $block['input'] ?? null,
                        "Anthropic tool '{$name}'",
                    ),
                ];
            }
        }

        return (object) [
            'content' => $content,
            'model' => $data['model'] ?? $body['model'],
            'tokens' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
            'provider' => 'anthropic',
            'usage' => $data['usage'] ?? null,
            'stop_reason' => $data['stop_reason'] ?? null,
            'tool_calls' => $toolCalls,
            'raw_content' => $data['content'] ?? [],
        ];
    }

    /**
     * Stream a prompt to the LLM and get a streaming response
     */
    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $messages = $options['messages'] ?? [['role' => 'user', 'content' => $message]];
        $system = $options['system'] ?? null;

        // Build request body
        $body = [
            'model' => $options['model'] ?? 'claude-sonnet-4-6',
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'stream' => true, // Enable streaming
        ];

        if ($system) {
            $body['system'] = $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        // Add tools if provided
        if (isset($options['tools']) && ! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Make streaming API call using HttpClient
        $transport = $this->httpClient->streamJson(
            method: 'POST',
            url: $this->baseUrl.'/messages',
            headers: [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            json: $body,
            options: ['timeout' => 0]
        );

        if (! ($transport->status() >= 200 && $transport->status() < 300)) {
            $content = $transport->getContent();
            $data = json_decode($content, true);
            $type = $data['error']['type'] ?? 'Unknown type';
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new RuntimeException("Anthropic API error: {$type} {$error}");
        }

        $parser = new AnthropicStreamParser;
        $model = $body['model'];

        return new StreamResponse(
            stream: $parser->parse($transport->chunks(), $model),
            provider: 'anthropic',
            model: $model,
            canceller: static function () use ($transport): void {
                $transport->close();
            },
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
}
