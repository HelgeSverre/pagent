<?php

declare(strict_types=1);

namespace Pagent\Providers;

use InvalidArgumentException;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\ProviderCapabilities;
use Pagent\Streaming\OpenAIStreamParser;
use Pagent\Streaming\StreamResponse;
use Pagent\Tool\ToolCallArgumentNormalizer;
use RuntimeException;

use function array_unshift;
use function getenv;
use function json_decode;

final class OpenAI implements IdentifiedProvider, StreamingProvider
{
    private string $apiKey;

    private string $baseUrl = 'https://api.openai.com/v1';

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->apiKey = $config['api_key'] ?? $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenAI API key not configured');
        }

        $this->httpClient = $httpClient ?? new CurlTransport;
    }

    public function prompt(string $message, array $options = []): object
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
            'model' => $options['model'] ?? 'gpt-3.5-turbo',
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        // Add tools if provided
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional OpenAI-specific options (e.g., response_format, seed, etc.)
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'temperature', 'max_tokens', 'tools'], true)) {
                $body[$key] = $value;
            }
        }

        // Make API call using HttpClient
        $response = $this->httpClient->requestJson(
            method: 'POST',
            url: $this->baseUrl.'/chat/completions',
            headers: [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ],
            json: $body,
            options: ['timeout' => 30]
        );

        if (! $response->isSuccessful()) {
            $data = $response->json();
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new RuntimeException("OpenAI API error: {$error}");
        }

        $data = $response->json();

        $message = $data['choices'][0]['message'] ?? [];
        $toolCalls = [];

        // Extract tool calls if present
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                $name = is_string($toolCall['function']['name'] ?? null)
                    ? $toolCall['function']['name']
                    : 'unknown';
                $toolCalls[] = [
                    'id' => $toolCall['id'],
                    'name' => $name,
                    'arguments' => ToolCallArgumentNormalizer::normalize(
                        $toolCall['function']['arguments'] ?? null,
                        "OpenAI tool '{$name}'",
                    ),
                ];
            }
        }

        return (object) [
            'content' => $message['content'] ?? '',
            'model' => $data['model'] ?? $body['model'],
            'tokens' => $data['usage']['total_tokens'] ?? 0,
            'provider' => 'openai',
            'usage' => $data['usage'] ?? null,
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
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
            'model' => $options['model'] ?? 'gpt-3.5-turbo',
            'messages' => $messages,
            'stream' => true, // Enable streaming
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        // Add tools if provided
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional OpenAI-specific options
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'temperature', 'max_tokens', 'tools'], true)) {
                $body[$key] = $value;
            }
        }

        $streamOptions = $options['stream_options'] ?? [];
        if (! is_array($streamOptions)) {
            throw new InvalidArgumentException('OpenAI stream_options must be an array');
        }
        $body['stream_options'] = array_merge(['include_usage' => true], $streamOptions);

        // Make streaming API call using HttpClient
        $transport = $this->httpClient->streamJson(
            method: 'POST',
            url: $this->baseUrl.'/chat/completions',
            headers: [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ],
            json: $body,
            options: ['timeout' => 0]
        );

        if (! ($transport->status() >= 200 && $transport->status() < 300)) {
            $content = $transport->getContent();
            $data = json_decode($content, true);
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new RuntimeException("OpenAI API error: {$error}");
        }

        $parser = new OpenAIStreamParser;
        $model = $body['model'];

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
}
