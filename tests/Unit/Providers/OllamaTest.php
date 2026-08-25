<?php

declare(strict_types=1);

use Pagent\Http\HttpClientInterface;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
use Pagent\Providers\Ollama;

it('accepts default base url when not configured', function (): void {
    $provider = new Ollama;

    expect($provider)->toBeInstanceOf(Ollama::class);
});

it('accepts base url in config', function (): void {
    $provider = new Ollama(['base_url' => 'http://custom-ollama:11434']);

    expect($provider)->toBeInstanceOf(Ollama::class);
});

it('removes trailing slash from base url', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public string $capturedUrl = '';

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedUrl = $url;

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                    'prompt_eval_count' => 10,
                    'eval_count' => 20,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama(['base_url' => 'http://localhost:11434/'], $mockHttp);
    $provider->prompt('test');

    expect($mockHttp->capturedUrl)->toBe('http://localhost:11434/api/chat');
});

it('makes successful request with basic options', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedJson = $json ?? [];

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'Hello!'],
                    'done' => true,
                    'prompt_eval_count' => 10,
                    'eval_count' => 20,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $response = $provider->prompt('Hello', ['model' => 'llama2']);

    expect($mockHttp->capturedJson['model'])->toBe('llama2')
        ->and($mockHttp->capturedJson['messages'])->toBe([['role' => 'user', 'content' => 'Hello']])
        ->and($mockHttp->capturedJson['stream'])->toBe(false)
        ->and($response->content)->toBe('Hello!')
        ->and($response->provider)->toBe('ollama')
        ->and($response->tokens)->toBe(30); // 10 + 20
});

it('uses default model when not specified', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedJson = $json ?? [];

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $provider->prompt('test');

    expect($mockHttp->capturedJson['model'])->toBe('qwen3:8b');
});

it('includes system message when provided', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedJson = $json ?? [];

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $provider->prompt('Hello', [
        'system' => 'You are helpful',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
    ]);

    expect($mockHttp->capturedJson['messages'])->toHaveCount(2)
        ->and($mockHttp->capturedJson['messages'][0])->toBe(['role' => 'system', 'content' => 'You are helpful'])
        ->and($mockHttp->capturedJson['messages'][1])->toBe(['role' => 'user', 'content' => 'Hello']);
});

it('passes temperature option', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedJson = $json ?? [];

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $provider->prompt('test', ['temperature' => 0.8]);

    expect($mockHttp->capturedJson['options']['temperature'])->toBe(0.8);
});

it('converts max_tokens to ollama num_predict format', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedJson = $json ?? [];

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $provider->prompt('test', ['max_tokens' => 500]);

    expect($mockHttp->capturedJson['options']['num_predict'])->toBe(500);
});

it('includes tools when provided', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedJson = $json ?? [];

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $tools = [
        ['name' => 'get_weather', 'description' => 'Get weather'],
    ];

    $provider = new Ollama([], $mockHttp);
    $provider->prompt('test', ['tools' => $tools]);

    expect($mockHttp->capturedJson['tools'])->toBe($tools);
});

it('parses tool calls from response', function (): void {
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
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => [
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_123',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => ['location' => 'London'],
                                ],
                            ],
                        ],
                    ],
                    'done' => true,
                    'prompt_eval_count' => 10,
                    'eval_count' => 5,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $response = $provider->prompt('test');

    expect($response->tool_calls)->toHaveCount(1)
        ->and($response->tool_calls[0]['id'])->toBe('call_123')
        ->and($response->tool_calls[0]['name'])->toBe('get_weather')
        ->and($response->tool_calls[0]['arguments'])->toBe(['location' => 'London']);
});

it('handles tool calls with string arguments', function (): void {
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
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => [
                        'content' => '',
                        'tool_calls' => [
                            [
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"location":"London"}', // String format
                                ],
                            ],
                        ],
                    ],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $response = $provider->prompt('test');

    expect($response->tool_calls[0]['arguments'])->toBe(['location' => 'London']);
});

it('generates unique tool call id when missing', function (): void {
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
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => [
                        'content' => '',
                        'tool_calls' => [
                            [
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => [],
                                ],
                            ],
                        ],
                    ],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $response = $provider->prompt('test');

    expect($response->tool_calls[0]['id'])->toStartWith('call_');
});

it('calculates token usage correctly', function (): void {
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
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                    'prompt_eval_count' => 100,
                    'eval_count' => 50,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $response = $provider->prompt('test');

    expect($response->tokens)->toBe(150)
        ->and($response->usage['prompt_tokens'])->toBe(100)
        ->and($response->usage['completion_tokens'])->toBe(50)
        ->and($response->usage['total_tokens'])->toBe(150);
});

it('throws exception on http error', function (): void {
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
                body: json_encode(['error' => 'Internal server error']),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Ollama API error: Internal server error');
});

it('throws exception when response contains error field', function (): void {
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
                body: json_encode(['error' => 'Model not found']),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);

    expect(fn () => $provider->prompt('test'))
        ->toThrow(RuntimeException::class, 'Ollama API error: Model not found');
});

it('handles response with missing optional fields', function (): void {
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
                body: json_encode([
                    'message' => ['content' => 'Hello'],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $response = $provider->prompt('test');

    expect($response->content)->toBe('Hello')
        ->and($response->tokens)->toBe(0)
        ->and($response->model)->toBe('qwen3:8b')
        ->and($response->usage['prompt_tokens'])->toBe(0)
        ->and($response->usage['completion_tokens'])->toBe(0);
});

it('passes through ollama-specific options', function (): void {
    $mockHttp = new class implements HttpClientInterface
    {
        public array $capturedJson = [];

        public function requestJson(
            string $method,
            string $url,
            array $headers = [],
            array|string|null $json = null,
            array $options = []
        ): HttpResponse {
            $this->capturedJson = $json ?? [];

            return new HttpResponse(
                status: 200,
                headers: [],
                body: json_encode([
                    'model' => 'qwen3:8b',
                    'message' => ['content' => 'test'],
                    'done' => true,
                ]),
                info: []
            );
        }

        public function streamJson(string $method, string $url, array $headers = [], array|string|null $json = null, array $options = []): StreamTransport
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = new Ollama([], $mockHttp);
    $provider->prompt('test', [
        'top_p' => 0.9,
        'top_k' => 40,
        'repeat_penalty' => 1.1,
    ]);

    expect($mockHttp->capturedJson['options']['top_p'])->toBe(0.9)
        ->and($mockHttp->capturedJson['options']['top_k'])->toBe(40)
        ->and($mockHttp->capturedJson['options']['repeat_penalty'])->toBe(1.1);
});
