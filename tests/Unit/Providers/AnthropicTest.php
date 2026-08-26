<?php

declare(strict_types=1);

use Pagent\Exceptions\ApiException;
use Pagent\Exceptions\ConfigurationException;
use Pagent\Http\HttpClientInterface;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
use Pagent\Providers\Anthropic;

it('requires api key', function (): void {
    expect(fn () => new Anthropic(['api_key' => '']))
        ->toThrow(ConfigurationException::class, 'Anthropic API key not configured');
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

    try {
        $provider->prompt('test');
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain('Invalid API key')
            ->and($e->provider)->toBe('anthropic')
            ->and($e->statusCode)->toBe(401)
            ->and($e->isRetryable())->toBeFalse();
    }
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

    try {
        $provider->prompt('test');
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain('Rate limit exceeded')
            ->and($e->statusCode)->toBe(429)
            ->and($e->isRetryable())->toBeTrue();
    }
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
        ->toThrow(ApiException::class, 'Internal server error');
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
