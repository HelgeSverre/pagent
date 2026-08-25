<?php

declare(strict_types=1);

use Pagent\Http\HttpClientInterface;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
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
                body: json_encode(['error' => ['message' => 'Incorrect API key provided']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new OpenAI(['api_key' => 'invalid-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Incorrect API key provided');
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
                body: json_encode(['error' => ['message' => 'Rate limit reached']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new OpenAI(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Rate limit reached');
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
                body: json_encode(['error' => ['message' => 'The server had an error']]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new OpenAI(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'The server had an error');
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

    $provider = new OpenAI(['api_key' => 'test-key'], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(UnexpectedValueException::class);
});

test('streaming requests include usage metadata by default', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): HttpResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            $this->capturedJson = is_array($json) ? $json : [];
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\",\"index\":0}]}\n\n");
            fwrite($stream, "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":2,\"completion_tokens\":1,\"total_tokens\":3}}\n\n");
            fwrite($stream, "data: [DONE]\n\n");
            rewind($stream);

            return new StreamTransport($stream, 200, []);
        }
    };

    $provider = new OpenAI(['api_key' => 'test-key'], $mockHttp);
    $response = $provider->streamPrompt('hello');
    $response->collect();

    expect($mockHttp->capturedJson['stream_options']['include_usage'])->toBeTrue()
        ->and($response->getUsage()['total_tokens'] ?? null)->toBe(3);
});
