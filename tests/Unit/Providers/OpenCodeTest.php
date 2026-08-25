<?php

declare(strict_types=1);

use Pagent\Http\HttpClientInterface;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
use Pagent\Providers\OpenCode;

beforeEach(function (): void {
    $this->http = new class implements HttpClientInterface
    {
        public string $method = '';

        public string $url = '';

        public array $headers = [];

        public array $json = [];

        public array $options = [];

        public int $status = 200;

        public int $streamStatus = 200;

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->method = $method;
            $this->url = $url;
            $this->headers = $headers;
            $this->json = is_array($json) ? $json : [];
            $this->options = $options;

            $body = $this->status >= 400
                ? ['error' => ['message' => 'Gateway unavailable']]
                : [
                    'model' => $this->json['model'] ?? null,
                    'choices' => [[
                        'message' => ['content' => 'Hello from Ox Alpha'],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => [
                        'prompt_tokens' => 4,
                        'completion_tokens' => 5,
                        'total_tokens' => 9,
                    ],
                ];

            return new HttpResponse(
                status: $this->status,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($body),
                info: []
            );
        }

        public function streamJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): StreamTransport {
            $this->method = $method;
            $this->url = $url;
            $this->headers = $headers;
            $this->json = is_array($json) ? $json : [];
            $this->options = $options;

            $stream = fopen('php://memory', 'r+');
            fwrite($stream, "data: {\"choices\":[{\"delta\":{\"content\":\"smoke-ok\"},\"index\":0}]}\n\n");
            fwrite($stream, "data: [DONE]\n\n");
            rewind($stream);

            return new StreamTransport(
                resource: $stream,
                status: $this->streamStatus,
                headers: ['Content-Type' => 'text/event-stream'],
            );
        }
    };
});

it('requires an api key', function (): void {
    expect(fn () => new OpenCode(['api_key' => '']))
        ->toThrow(RuntimeException::class, 'OpenCode API key not configured');
});

it('rejects unknown gateways', function (): void {
    expect(fn () => new OpenCode(['api_key' => 'test-key', 'gateway' => 'other']))
        ->toThrow(InvalidArgumentException::class, 'Unknown OpenCode gateway: other');
});

it('uses Ox Alpha on the Zen gateway', function (): void {
    $provider = new OpenCode(['api_key' => 'test-key'], $this->http);

    $response = $provider->prompt('Hello');

    expect($this->http->method)->toBe('POST')
        ->and($this->http->url)->toBe('https://opencode.ai/zen/v1/chat/completions')
        ->and($this->http->headers['Authorization'])->toBe('Bearer test-key')
        ->and($this->http->json)->toMatchArray([
            'model' => 'x-preview-f-free',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])
        ->and($response->content)->toBe('Hello from Ox Alpha')
        ->and($response->model)->toBe('x-preview-f-free')
        ->and($response->provider)->toBe('opencode')
        ->and($response->tokens)->toBe(9);
});

it('uses Ox Alpha on the Go gateway', function (): void {
    $provider = new OpenCode([
        'api_key' => 'test-key',
        'gateway' => 'go',
    ], $this->http);

    $response = $provider->prompt('Hello');

    expect($this->http->url)->toBe('https://opencode.ai/zen/go/v1/chat/completions')
        ->and($this->http->json['model'])->toBe('ox-alpha-free')
        ->and($response->model)->toBe('ox-alpha-free');
});

it('passes chat completion options and supports a custom base url', function (): void {
    $provider = new OpenCode([
        'api_key' => 'test-key',
        'base_url' => 'https://gateway.example/v1/',
        'timeout' => 45,
    ], $this->http);

    $provider->prompt('ignored', [
        'model' => 'ox-alpha-free',
        'system' => 'You are concise.',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 0.2,
        'max_tokens' => 100,
    ]);

    expect($this->http->url)->toBe('https://gateway.example/v1/chat/completions')
        ->and($this->http->json['model'])->toBe('ox-alpha-free')
        ->and($this->http->json['messages'][0])->toBe(['role' => 'system', 'content' => 'You are concise.'])
        ->and($this->http->json['temperature'])->toBe(0.2)
        ->and($this->http->json['max_tokens'])->toBe(100)
        ->and($this->http->options)->toBe(['timeout' => 45]);
});

it('streams Ox Alpha responses through the selected gateway', function (): void {
    $provider = new OpenCode([
        'api_key' => 'test-key',
        'gateway' => 'go',
    ], $this->http);

    $response = $provider->streamPrompt('Hello');

    expect($this->http->url)->toBe('https://opencode.ai/zen/go/v1/chat/completions')
        ->and($this->http->json['model'])->toBe('ox-alpha-free')
        ->and($this->http->json['stream'])->toBeTrue()
        ->and($response->getProvider())->toBe('opencode')
        ->and($response->getModel())->toBe('ox-alpha-free')
        ->and($response->collect())->toBe('smoke-ok');
});

it('reports OpenCode API errors', function (): void {
    $this->http->status = 503;
    $provider = new OpenCode(['api_key' => 'test-key'], $this->http);

    expect(fn () => $provider->prompt('Hello'))
        ->toThrow(RuntimeException::class, 'OpenCode API error: Gateway unavailable');
});
