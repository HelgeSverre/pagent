<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Generator;
use Pagent\Contracts\Provider;
use Pagent\Streaming\OllamaStreamParser;
use Pagent\Streaming\StreamResponse;
use RuntimeException;

use function array_unshift;
use function curl_close;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function fopen;
use function getenv;
use function json_decode;
use function json_encode;

final class Ollama implements Provider
{
    private string $baseUrl;

    private int $timeout;

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url']
            ?? $_ENV['OLLAMA_HOST']
            ?? getenv('OLLAMA_HOST')
            ?: 'http://localhost:11434';

        $this->timeout = $config['timeout'] ?? 120;

        // Remove trailing slash from base URL
        $this->baseUrl = rtrim($this->baseUrl, '/');
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

        // Make API call
        $ch = curl_init($this->baseUrl.'/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Ollama API request failed. Is the Ollama server running at '.$this->baseUrl.'?');
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $error = $data['error'] ?? 'Unknown error';

            throw new RuntimeException("Ollama API error: {$error}");
        }

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

        // Create a memory stream for cURL to write to
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Failed to create stream buffer');
        }

        // Make streaming API call
        $ch = curl_init($this->baseUrl.'/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 0, // No timeout for streaming
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($stream) {
                fwrite($stream, $data);

                return strlen($data);
            },
        ]);

        // Execute request
        $execResult = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($execResult === false) {
            curl_close($ch);
            fclose($stream);
            throw new RuntimeException('Ollama streaming API request failed. Is the Ollama server running at '.$this->baseUrl.'?');
        }

        if ($httpCode !== 200) {
            curl_close($ch);
            rewind($stream);
            $response = stream_get_contents($stream);
            fclose($stream);

            $data = json_decode($response, true);
            $error = $data['error'] ?? 'Unknown error';

            throw new RuntimeException("Ollama API error: {$error}");
        }

        curl_close($ch);

        // Rewind stream to beginning for parsing
        rewind($stream);

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
