<?php

declare(strict_types=1);

use Pagent\Http\HttpClientInterface;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
use Pagent\Providers\Anthropic;

it('requires api key', function (): void {
    expect(fn () => new Anthropic(['api_key' => '']))
        ->toThrow(RuntimeException::class, 'Anthropic API key not configured');
});

it('accepts api key in config', function (): void {
    $provider = new Anthropic(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(Anthropic::class);
});

// ========================================
// ERROR RESPONSE HANDLING
// ========================================

test('it throws on 401 unauthorized', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            return new HttpResponse(
                status: 401,
                headers: [],
                body: json_encode(['error' => ['message' => 'Invalid API key']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Anthropic(['api_key' => 'invalid-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Invalid API key');
});

test('it throws on 429 rate limit', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            return new HttpResponse(
                status: 429,
                headers: [],
                body: json_encode(['error' => ['message' => 'Rate limit exceeded']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Anthropic(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Rate limit exceeded');
});

test('it throws on 500 server error', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            return new HttpResponse(
                status: 500,
                headers: [],
                body: json_encode(['error' => ['message' => 'Internal server error']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Anthropic(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Internal server error');
});

test('it throws on malformed json response', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            return new HttpResponse(
                status: 200,
                headers: [],
                body: '{invalid json}',
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Anthropic(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(UnexpectedValueException::class);
});
