<?php

declare(strict_types=1);

use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Http\HttpClientInterface;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
use Pagent\ProviderCapabilities;
use Pagent\Providers\Anthropic;
use Pagent\Providers\Mock;
use Pagent\Providers\Ollama;
use Pagent\Providers\OpenAI;

test('built-in providers expose stable identities and adapter capabilities', function (): void {
    $providers = [
        [new Anthropic(['api_key' => 'test-key']), 'anthropic', 'anthropic-messages', 'anthropic', true, true, true, false],
        [new OpenAI(['api_key' => 'test-key']), 'openai', 'openai-chat-completions', 'openai', true, true, true, true],
        [new Ollama, 'ollama', 'ollama-chat', 'openai', true, true, true, true],
        [new Mock, 'mock', 'mock', 'none', true, false, false, false],
    ];

    foreach ($providers as [$provider, $id, $protocol, $toolProtocol, $streaming, $tools, $systemMessages, $structuredOutput]) {
        expect($provider)
            ->toBeInstanceOf(IdentifiedProvider::class)
            ->toBeInstanceOf(StreamingProvider::class)
            ->and($provider->providerId())->toBe($id);

        $capabilities = $provider->capabilities();

        expect($capabilities)
            ->toBeInstanceOf(ProviderCapabilities::class)
            ->and($capabilities->protocol)->toBe($protocol)
            ->and($capabilities->toolProtocol)->toBe($toolProtocol)
            ->and($capabilities->supportsStreaming)->toBe($streaming)
            ->and($capabilities->supportsTools)->toBe($tools)
            ->and($capabilities->supportsSystemMessages)->toBe($systemMessages)
            ->and($capabilities->supportsStructuredOutput)->toBe($structuredOutput);
    }
});

test('Anthropic uses the current default model for non-streaming requests', function (): void {
    $http = new class implements HttpClientInterface
    {
        public array $requests = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->requests[] = compact('method', 'url', 'headers', 'json', 'options');

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode(['content' => [['type' => 'text', 'text' => 'ok']]]),
                info: [],
            );
        }

        public function streamJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): StreamTransport {
            throw new RuntimeException('Not used by this test');
        }
    };

    (new Anthropic(['api_key' => 'test-key'], $http))->prompt('hello');

    expect($http->requests[0]['json']['model'])->toBe('claude-sonnet-4-6');
});
