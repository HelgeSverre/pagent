<?php

declare(strict_types=1);

use Pagent\Providers\OpenAI;

it('requires api key', function (): void {
    expect(fn () => new OpenAI(['api_key' => '']))
        ->toThrow(RuntimeException::class, 'OpenAI API key not configured');
});

it('accepts api key in config', function (): void {
    $provider = new OpenAI(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(OpenAI::class);
});

// ========================================
// ERROR RESPONSE HANDLING
// ========================================

test('it throws on 401 unauthorized', function (): void {
    $mockHttp = new class implements \Pagent\Http\HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): \Pagent\Http\HttpResponse {
            return new \Pagent\Http\HttpResponse(
                status: 401,
                headers: [],
                body: json_encode(['error' => ['message' => 'Incorrect API key provided']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): \Pagent\Http\StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new OpenAI(['api_key' => 'invalid-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Incorrect API key provided');
});

test('it throws on 429 rate limit', function (): void {
    $mockHttp = new class implements \Pagent\Http\HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): \Pagent\Http\HttpResponse {
            return new \Pagent\Http\HttpResponse(
                status: 429,
                headers: [],
                body: json_encode(['error' => ['message' => 'Rate limit reached']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): \Pagent\Http\StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new OpenAI(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Rate limit reached');
});

test('it throws on 500 server error', function (): void {
    $mockHttp = new class implements \Pagent\Http\HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): \Pagent\Http\HttpResponse {
            return new \Pagent\Http\HttpResponse(
                status: 500,
                headers: [],
                body: json_encode(['error' => ['message' => 'The server had an error']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): \Pagent\Http\StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new OpenAI(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'The server had an error');
});

test('it throws on malformed json response', function (): void {
    $mockHttp = new class implements \Pagent\Http\HttpClientInterface
    {
        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): \Pagent\Http\HttpResponse {
            return new \Pagent\Http\HttpResponse(
                status: 200,
                headers: [],
                body: '{invalid json}',
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): \Pagent\Http\StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new OpenAI(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(UnexpectedValueException::class);
});
