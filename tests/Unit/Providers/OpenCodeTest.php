<?php

declare(strict_types=1);

use Pagent\Exceptions\ConfigurationException;
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

        public ?array $responseBody = null;

        public ?string $streamBody = null;

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

            $body = $this->responseBody ?? ($this->status >= 400
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
                ]);

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
            fwrite($stream, $this->streamBody ?? "data: {\"choices\":[{\"delta\":{\"content\":\"smoke-ok\"},\"index\":0}]}\n\ndata: [DONE]\n\n");
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
        ->toThrow(ConfigurationException::class, 'OpenCode API key not configured');
});

it('rejects unknown gateways', function (): void {
    expect(fn () => new OpenCode(['api_key' => 'test-key', 'gateway' => 'other']))
        ->toThrow(InvalidArgumentException::class, 'Unknown OpenCode gateway: other');
});

it('declares stable capabilities independently from its selected wire protocol', function (): void {
    $provider = new OpenCode(['api_key' => 'test-key', 'protocol' => 'messages'], $this->http);

    expect($provider->providerId())->toBe('opencode')
        ->and($provider->capabilities()->protocol)->toBe('opencode-multi-protocol')
        ->and($provider->capabilities()->toolProtocol)->toBe('openai')
        ->and($provider->capabilities()->supportsTools)->toBeTrue();
});

it('declares its stable identity and capabilities', function (): void {
    $provider = new OpenCode(['api_key' => 'test-key'], $this->http);
    $capabilities = $provider->capabilities();

    expect($provider->providerId())->toBe('opencode')
        ->and($capabilities->supportsStreaming)->toBeTrue()
        ->and($capabilities->supportsTools)->toBeTrue()
        ->and($capabilities->supportsSystemMessages)->toBeTrue()
        ->and($capabilities->supportsStructuredOutput)->toBeTrue()
        ->and($capabilities->protocol)->toBe('opencode-multi-protocol');
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

it('uses the Responses protocol and normalizes text, tool calls, and usage', function (): void {
    $this->http->responseBody = [
        'model' => 'gpt-response-model',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'A response']],
            ],
            [
                'type' => 'function_call',
                'call_id' => 'call_weather',
                'name' => 'weather',
                'arguments' => '{"city":"Oslo"}',
            ],
        ],
        'usage' => ['input_tokens' => 3, 'output_tokens' => 7, 'total_tokens' => 10],
    ];
    $provider = new OpenCode(['api_key' => 'test-key', 'protocol' => 'responses'], $this->http);

    $response = $provider->prompt('Hello', [
        'system' => 'Be concise.',
        'max_tokens' => 120,
        'tools' => [[
            'type' => 'function',
            'function' => [
                'name' => 'weather',
                'description' => 'Look up weather',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ],
        ]],
    ]);

    expect($this->http->url)->toBe('https://opencode.ai/zen/v1/responses')
        ->and($this->http->json)->toMatchArray([
            'model' => 'x-preview-f-free',
            'max_output_tokens' => 120,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [['type' => 'input_text', 'text' => 'Be concise.']],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => 'Hello']],
                ],
            ],
            'tools' => [[
                'type' => 'function',
                'name' => 'weather',
                'description' => 'Look up weather',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ]],
        ])
        ->and($response->content)->toBe('A response')
        ->and($response->model)->toBe('gpt-response-model')
        ->and($response->tokens)->toBe(10)
        ->and($response->finish_reason)->toBe('completed')
        ->and($response->tool_calls)->toBe([[
            'id' => 'call_weather',
            'name' => 'weather',
            'arguments' => ['city' => 'Oslo'],
        ]]);
});

it('uses model protocol configuration and maps Messages requests and responses', function (): void {
    $this->http->responseBody = [
        'model' => 'claude-like-model',
        'stop_reason' => 'tool_use',
        'content' => [
            ['type' => 'text', 'text' => 'Let me check.'],
            ['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'weather', 'input' => ['city' => 'Oslo']],
        ],
        'usage' => ['input_tokens' => 4, 'output_tokens' => 6],
    ];
    $provider = new OpenCode([
        'api_key' => 'test-key',
        'model_protocols' => ['claude-like-model' => 'messages'],
    ], $this->http);

    $response = $provider->prompt('Hello', [
        'model' => 'claude-like-model',
        'system' => 'You are helpful.',
        'max_tokens' => 200,
        'tools' => [[
            'type' => 'function',
            'function' => [
                'name' => 'weather',
                'description' => 'Look up weather',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ]],
    ]);

    expect($this->http->url)->toBe('https://opencode.ai/zen/v1/messages')
        ->and($this->http->json)->toMatchArray([
            'model' => 'claude-like-model',
            'system' => 'You are helpful.',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 200,
            'tools' => [[
                'name' => 'weather',
                'description' => 'Look up weather',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ]],
        ])
        ->and($response->content)->toBe('Let me check.')
        ->and($response->tokens)->toBe(10)
        ->and($response->finish_reason)->toBe('tool_use')
        ->and($response->tool_calls)->toBe([[
            'id' => 'tool_1',
            'name' => 'weather',
            'arguments' => ['city' => 'Oslo'],
        ]]);
});

it('allows a per-prompt protocol override and rejects invalid protocol configuration', function (): void {
    $this->http->responseBody = [
        'status' => 'completed',
        'output' => [],
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
    ];
    $provider = new OpenCode(['api_key' => 'test-key'], $this->http);

    $provider->prompt('Hello', ['protocol' => 'responses']);

    expect($this->http->url)->toBe('https://opencode.ai/zen/v1/responses');
    expect(fn () => new OpenCode(['api_key' => 'test-key', 'protocol' => 'unknown']))
        ->toThrow(InvalidArgumentException::class, 'Unknown OpenCode protocol: unknown');
    expect(fn () => $provider->prompt('Hello', ['protocol' => 'unknown']))
        ->toThrow(InvalidArgumentException::class, 'Unknown OpenCode protocol: unknown');
});

it('maps automatic tool follow-up history for Responses and Messages protocols', function (): void {
    $toolHistory = [
        [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'function' => ['name' => 'weather', 'arguments' => '{"city":"Oslo"}'],
            ]],
        ],
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"temperature":18}'],
    ];

    $this->http->responseBody = [
        'status' => 'completed',
        'output' => [],
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
    ];
    $responses = new OpenCode(['api_key' => 'test-key', 'protocol' => 'responses'], $this->http);
    $responses->prompt('ignored', ['messages' => $toolHistory]);

    expect($this->http->json['input'])->toBe([
        [
            'type' => 'function_call',
            'call_id' => 'call_1',
            'name' => 'weather',
            'arguments' => '{"city":"Oslo"}',
        ],
        [
            'type' => 'function_call_output',
            'call_id' => 'call_1',
            'output' => '{"temperature":18}',
        ],
    ]);

    $this->http->responseBody = [
        'content' => [['type' => 'text', 'text' => 'done']],
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
    ];
    $messages = new OpenCode(['api_key' => 'test-key', 'protocol' => 'messages'], $this->http);
    $messages->prompt('ignored', ['messages' => $toolHistory]);

    expect($this->http->json['messages'])->toBe([
        [
            'role' => 'assistant',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'call_1',
                'name' => 'weather',
                'input' => ['city' => 'Oslo'],
            ]],
        ],
        [
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'call_1',
                'content' => '{"temperature":18}',
            ]],
        ],
    ]);
});

it('streams Responses protocol events incrementally', function (): void {
    $this->http->streamBody = <<<'SSE'
event: response.created
data: {"response":{"id":"resp_1"}}

event: response.output_text.delta
data: {"delta":"Hello ","output_index":0,"content_index":0}

event: response.output_text.delta
data: {"delta":"world","output_index":0,"content_index":0}

event: response.completed
data: {"response":{"status":"completed","usage":{"input_tokens":2,"output_tokens":2,"total_tokens":4}}}

SSE;
    $provider = new OpenCode(['api_key' => 'test-key', 'protocol' => 'responses'], $this->http);

    $response = $provider->streamPrompt('Hello');

    expect($this->http->url)->toBe('https://opencode.ai/zen/v1/responses')
        ->and($this->http->json['stream'])->toBeTrue()
        ->and($response->collect())->toBe('Hello world')
        ->and($response->getUsage())->toBe(['input_tokens' => 2, 'output_tokens' => 2, 'total_tokens' => 4])
        ->and($response->isComplete())->toBeTrue();
});

it('associates Responses tool argument deltas with their function call', function (): void {
    $this->http->streamBody = <<<'SSE'
event: response.created
data: {"response":{"id":"resp_1"}}

event: response.output_item.added
data: {"output_index":0,"item":{"type":"function_call","call_id":"call_1","name":"weather"}}

event: response.function_call_arguments.delta
data: {"output_index":0,"delta":"{\"city\":\"Oslo\"}"}

event: response.completed
data: {"response":{"status":"completed"}}

SSE;
    $provider = new OpenCode(['api_key' => 'test-key', 'protocol' => 'responses'], $this->http);

    $response = $provider->streamPrompt('Weather?');
    $response->collect();
    $toolChunks = array_values(array_filter($response->getChunks(), fn ($chunk) => $chunk->isToolCall()));

    expect($toolChunks)->toHaveCount(1)
        ->and($toolChunks[0]->getMetadata('tool_call_id'))->toBe('call_1')
        ->and($toolChunks[0]->getMetadata('tool_name'))->toBe('weather');
});

it('streams Messages protocol events through the Anthropic-compatible parser', function (): void {
    $this->http->streamBody = <<<'SSE'
event: message_start
data: {"type":"message_start","message":{"id":"msg_1","usage":{"input_tokens":2}}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello from Messages"}}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":3}}

event: message_stop
data: {"type":"message_stop"}

SSE;
    $this->http->streamBody .= "\n";
    $provider = new OpenCode(['api_key' => 'test-key', 'protocol' => 'messages'], $this->http);

    $response = $provider->streamPrompt('Hello');

    $content = $response->collect();

    expect($this->http->url)->toBe('https://opencode.ai/zen/v1/messages')
        ->and($this->http->json['stream'])->toBeTrue()
        ->and($content)->toBe('Hello from Messages');
    expect($response->getUsage())->toBe(['input_tokens' => 2, 'output_tokens' => 3, 'total_tokens' => 5])
        ->and($response->getStopReason())->toBe('end_turn');
});
