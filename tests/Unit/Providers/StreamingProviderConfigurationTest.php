<?php

declare(strict_types=1);

use Pagent\Http\HttpClientInterface;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
use Pagent\Providers\Anthropic;
use Pagent\Providers\Ollama;
use Pagent\Providers\OpenAI;
use Pagent\Providers\OpenCode;

final class CapturingStreamingClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, mixed> */
    public array $json = [];

    public function requestJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): HttpResponse
    {
        throw new RuntimeException('Not used');
    }

    public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
    {
        $this->options = $options;
        $this->json = is_array($json) ? $json : [];
        $stream = fopen('php://memory', 'r+');

        return new StreamTransport($stream, 200, []);
    }
}

test('every built-in provider applies stream controls without leaking them into API payloads', function (Closure $factory): void {
    $http = new CapturingStreamingClient;
    $provider = $factory($http);
    $response = $provider->streamPrompt('hello', [
        'stream_timeout' => 77,
        'connect_timeout' => 8,
        'idle_timeout' => 19,
        'retain_chunks' => false,
    ]);

    expect($http->options)->toBe([
        'timeout' => 77,
        'connect_timeout' => 8,
        'idle_timeout' => 19,
        'buffer_response' => false,
    ])->and($http->json)->not->toHaveKeys([
        'stream_timeout',
        'connect_timeout',
        'idle_timeout',
        'retain_chunks',
    ]);

    $response->cancel();
})->with([
    'OpenAI' => fn (HttpClientInterface $http) => new OpenAI(['api_key' => 'test'], $http),
    'Anthropic' => fn (HttpClientInterface $http) => new Anthropic(['api_key' => 'test'], $http),
    'Ollama' => fn (HttpClientInterface $http) => new Ollama([], $http),
    'OpenCode' => fn (HttpClientInterface $http) => new OpenCode(['api_key' => 'test'], $http),
]);

test('provider stream timeout defaults to the configured request timeout', function (): void {
    $http = new CapturingStreamingClient;
    $response = (new OpenAI(['api_key' => 'test', 'timeout' => 41], $http))->streamPrompt('hello');

    expect($http->options)->toMatchArray([
        'timeout' => 41,
        'connect_timeout' => 10,
        'idle_timeout' => 30,
    ]);

    $response->cancel();
});

test('built-in providers do not impose a total stream timeout unless one was configured', function (Closure $factory): void {
    $http = new CapturingStreamingClient;
    $response = $factory($http)->streamPrompt('hello');

    expect($http->options['timeout'])->toBe(0);

    $response->cancel();
})->with([
    'OpenAI' => fn (HttpClientInterface $http) => new OpenAI(['api_key' => 'test'], $http),
    'Anthropic' => fn (HttpClientInterface $http) => new Anthropic(['api_key' => 'test'], $http),
    'Ollama' => fn (HttpClientInterface $http) => new Ollama([], $http),
    'OpenCode' => fn (HttpClientInterface $http) => new OpenCode(['api_key' => 'test'], $http),
]);
