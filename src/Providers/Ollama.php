<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\Provider;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\Streaming\OllamaStreamParser;
use Pagent\Streaming\StreamResponse;
use RuntimeException;

use function array_unshift;
use function getenv;
use function json_decode;

final class Ollama implements Provider
{
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

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        // Ollama uses options.num_predict instead of max_tokens
        if (isset($options['max_tokens'])) {
            $body['options']['num_predict'] = $options['max_tokens'];
        }

        // Add tools if provided
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional Ollama-specific options
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'temperature', 'max_tokens', 'tools'], true)) {
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
                // Ollama may return arguments as array or string - handle both
                $arguments = $toolCall['function']['arguments'] ?? [];
                if (is_string($arguments)) {
                    $arguments = json_decode($arguments, true) ?? [];
                }

                $toolCalls[] = [
                    'id' => $toolCall['id'] ?? uniqid('call_'),
                    'name' => $toolCall['function']['name'],
                    'arguments' => $arguments,
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

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        // Ollama uses options.num_predict instead of max_tokens
        if (isset($options['max_tokens'])) {
            $body['options']['num_predict'] = $options['max_tokens'];
        }

        // Add tools if provided
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        // Pass through additional Ollama-specific options
        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model', 'temperature', 'max_tokens', 'tools'], true)) {
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

        $stream = $transport->resource();

        // Create generator that parses the stream
        $parser = new OllamaStreamParser;
        $model = $body['model'];
        $generator = $parser->parse($stream, $model);

        // Wrap in StreamResponse
        return new StreamResponse(
            stream: $generator,
            provider: 'ollama',
            model: $model,
        );
    }
}
