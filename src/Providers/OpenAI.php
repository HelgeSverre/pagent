<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\Provider;
use RuntimeException;

use function array_unshift;
use function curl_close;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function getenv;
use function json_decode;
use function json_encode;

final class OpenAI implements Provider
{
    private string $apiKey;

    private string $baseUrl = 'https://api.openai.com/v1';

    public function __construct(array $config = [])
    {
        $this->apiKey = $config['api_key'] ?? $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenAI API key not configured');
        }
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

        // TODO: replace with Guzzle or Symfony HttpClient later
        // Make API call
        $ch = curl_init($this->baseUrl.'/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 30,
            // TODO: ignore ssl verification for now, but should be handled properly in production
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('OpenAI API request failed');
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $error = $data['error']['message'] ?? 'Unknown error';

            throw new RuntimeException("OpenAI API error: {$error}");
        }

        $message = $data['choices'][0]['message'] ?? [];
        $toolCalls = [];

        // Extract tool calls if present
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                $toolCalls[] = [
                    'id' => $toolCall['id'],
                    'name' => $toolCall['function']['name'],
                    'arguments' => json_decode($toolCall['function']['arguments'], true),
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
}
