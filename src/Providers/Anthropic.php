<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\Provider;
use RuntimeException;

final class Anthropic implements Provider
{
    private string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct(array $config = [])
    {
        $this->apiKey = $config['api_key'] ?? $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
        if (empty($this->apiKey)) {
            throw new RuntimeException('Anthropic API key not configured');
        }
    }

    public function prompt(string $message, array $options = []): object
    {
        $messages = $options['messages'] ?? [['role' => 'user', 'content' => $message]];
        $system = $options['system'] ?? null;

        // Build request body
        $body = [
            'model' => $options['model'] ?? 'claude-sonnet-4-20250514',
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



        // TODO: replace with official php sdk client or http client from illuminate.
        // Make API call
        $ch = curl_init($this->baseUrl . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $response) {
            throw new RuntimeException('Anthropic API request failed');
        }

        $data = json_decode($response, true);

        if (200 !== $httpCode) {
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new RuntimeException("Anthropic API error: {$error}");
        }

        // Extract content and tool calls
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ('text' === $block['type']) {
                $content .= $block['text'];
            } elseif ('tool_use' === $block['type']) {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'arguments' => $block['input'],
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
}
