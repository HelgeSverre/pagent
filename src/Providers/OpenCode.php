<?php

declare(strict_types=1);

namespace Pagent\Providers;

use InvalidArgumentException;
use Pagent\Contracts\Provider;
use Pagent\Http\CurlTransport;
use Pagent\Http\HttpClientInterface;
use Pagent\Streaming\OpenAIStreamParser;
use Pagent\Streaming\StreamResponse;
use RuntimeException;

use function array_unshift;
use function getenv;
use function json_decode;

/**
 * OpenCode Zen and Go provider for models served through chat/completions.
 */
final class OpenCode implements Provider
{
    private string $apiKey;

    private string $baseUrl;

    private string $gateway;

    private int $timeout;

    private HttpClientInterface $httpClient;

    public function __construct(array $config = [], ?HttpClientInterface $httpClient = null)
    {
        $this->apiKey = $config['api_key'] ?? $_ENV['OPENCODE_API_KEY'] ?? getenv('OPENCODE_API_KEY') ?: '';
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenCode API key not configured');
        }

        $this->gateway = $config['gateway'] ?? 'zen';
        if (! in_array($this->gateway, ['zen', 'go'], true)) {
            throw new InvalidArgumentException("Unknown OpenCode gateway: {$this->gateway}");
        }

        $defaultBaseUrl = $this->gateway === 'go'
            ? 'https://opencode.ai/zen/go/v1'
            : 'https://opencode.ai/zen/v1';

        $this->baseUrl = rtrim($config['base_url'] ?? $defaultBaseUrl, '/');
        $this->timeout = $config['timeout'] ?? 30;
        $this->httpClient = $httpClient ?? new CurlTransport;
    }

    public function prompt(string $message, array $options = []): object
    {
        $messages = $this->messages($message, $options);
        $body = $this->requestBody($messages, $options);

        $response = $this->httpClient->requestJson(
            method: 'POST',
            url: $this->baseUrl.'/chat/completions',
            headers: $this->headers(),
            json: $body,
            options: ['timeout' => $this->timeout]
        );

        if (! $response->isSuccessful()) {
            $data = $response->json();
            $error = $data['error']['message'] ?? 'Unknown error';
            throw new RuntimeException("OpenCode API error: {$error}");
        }

        $data = $response->json();
        $responseMessage = $data['choices'][0]['message'] ?? [];
        $toolCalls = [];

        foreach ($responseMessage['tool_calls'] ?? [] as $toolCall) {
            $arguments = $toolCall['function']['arguments'] ?? [];
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            $toolCalls[] = [
                'id' => $toolCall['id'],
                'name' => $toolCall['function']['name'],
                'arguments' => $arguments,
            ];
        }

        return (object) [
            'content' => $responseMessage['content'] ?? '',
            'model' => $data['model'] ?? $body['model'],
            'tokens' => $data['usage']['total_tokens'] ?? 0,
            'provider' => 'opencode',
            'usage' => $data['usage'] ?? null,
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Stream a prompt to OpenCode's OpenAI-compatible endpoint.
     */
    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $messages = $this->messages($message, $options);
        $body = $this->requestBody($messages, $options);
        $body['stream'] = true;

        $transport = $this->httpClient->streamJson(
            method: 'POST',
            url: $this->baseUrl.'/chat/completions',
            headers: $this->headers(),
            json: $body,
            options: ['timeout' => 0]
        );

        if (! ($transport->status() >= 200 && $transport->status() < 300)) {
            $data = json_decode($transport->getContent(), true);
            $errorData = is_array($data) ? ($data['error'] ?? null) : null;
            $error = is_array($errorData) && is_string($errorData['message'] ?? null)
                ? $errorData['message']
                : 'Unknown error';
            throw new RuntimeException("OpenCode API error: {$error}");
        }

        $model = $body['model'];
        $parser = new OpenAIStreamParser;

        return new StreamResponse(
            stream: $parser->parse($transport->resource(), $model),
            provider: 'opencode',
            model: $model,
        );
    }

    private function messages(string $message, array $options): array
    {
        $messages = $options['messages'] ?? [];

        if (isset($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        if (empty($messages)) {
            $messages = [['role' => 'user', 'content' => $message]];
        }

        return $messages;
    }

    private function requestBody(array $messages, array $options): array
    {
        $body = [
            'model' => $options['model'] ?? $this->defaultModel(),
            'messages' => $messages,
        ];

        foreach ($options as $key => $value) {
            if (! in_array($key, ['messages', 'system', 'model'], true)) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    private function defaultModel(): string
    {
        return $this->gateway === 'go' ? 'ox-alpha-free' : 'x-preview-f-free';
    }

    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->apiKey,
        ];
    }
}
