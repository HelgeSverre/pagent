<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\Middleware;
use Pagent\Events\EventManager;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Mcp\McpClient;
use Pagent\Mcp\McpToolAdapter;
use Pagent\ProviderCapabilities;
use Pagent\Usage\UsageTracker;
use Tests\Unit\Mcp\Transports\FakeTransport;

beforeEach(function (): void {
    EventManager::reset();
    UsageTracker::resetGlobal();
});

afterEach(function (): void {
    UsageTracker::resetGlobal();
    EventManager::reset();
});

test('every tool-follow-up provider round preserves request options and lifecycle hooks', function (): void {
    $provider = new class implements IdentifiedProvider
    {
        /** @var array<int, array{message: string, options: array<string, mixed>}> */
        public array $requests = [];

        public function prompt(string $message, array $options = []): object
        {
            $this->requests[] = ['message' => $message, 'options' => $options];

            if (count($this->requests) === 1) {
                return (object) [
                    'content' => '',
                    'model' => 'test-model',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
                    'tool_calls' => [[
                        'id' => 'call-weather',
                        'name' => 'weather',
                        'arguments' => ['city' => 'Oslo'],
                    ]],
                ];
            }

            return (object) [
                'content' => 'It is sunny in Oslo.',
                'model' => 'test-model',
                'usage' => ['input_tokens' => 2, 'output_tokens' => 3, 'total_tokens' => 5],
                'tool_calls' => [],
            ];
        }

        public function providerId(): string
        {
            return 'openai';
        }

        public function capabilities(): ProviderCapabilities
        {
            return new ProviderCapabilities(supportsTools: true, protocol: 'openai-chat-completions', toolProtocol: 'openai');
        }
    };

    $middleware = new class implements Middleware
    {
        public int $beforeCalls = 0;

        public int $afterCalls = 0;

        public function before(string $message, array $options): array
        {
            $this->beforeCalls++;
            $options['middleware_marker'] = 'present';

            return $options;
        }

        public function after(object $response): object
        {
            $this->afterCalls++;

            return $response;
        }
    };

    $llmResponses = 0;
    $agent = (new Agent('tool-lifecycle'))
        ->provider($provider)
        ->model('test-model')
        ->contextWindow(10)
        ->middleware($middleware)
        ->tool('weather', 'Get weather for a city', fn (string $city): string => "Sunny in {$city}");

    $agent->on('after_llm_response', function (AfterLLMResponseEvent $event) use (&$llmResponses): void {
        $llmResponses++;
    });

    $response = $agent->prompt('What is the weather?', [
        'request_id' => 'request-123',
        'temperature' => 0.1,
    ]);

    expect($response->content)->toBe('It is sunny in Oslo.')
        ->and($provider->requests)->toHaveCount(2)
        ->and($provider->requests[1]['options'])->toMatchArray([
            'request_id' => 'request-123',
            'temperature' => 0.1,
            'middleware_marker' => 'present',
        ])
        ->and($provider->requests[1]['options']['messages'])->toHaveCount(2)
        ->and($provider->requests[1]['options']['messages'][0]['role'])->toBe('assistant')
        ->and($middleware->beforeCalls)->toBe(2)
        ->and($middleware->afterCalls)->toBe(2)
        ->and($llmResponses)->toBe(2);
});

test('identified third-party providers receive their declared identity and tool schemas', function (): void {
    $provider = new class implements IdentifiedProvider
    {
        /** @var array<string, mixed> */
        public array $options = [];

        public function prompt(string $message, array $options = []): object
        {
            $this->options = $options;

            return (object) [
                'content' => 'ok',
                'model' => 'gateway-model',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            ];
        }

        public function providerId(): string
        {
            return 'opencode';
        }

        public function capabilities(): ProviderCapabilities
        {
            return new ProviderCapabilities(supportsTools: true, protocol: 'openai-chat-completions', toolProtocol: 'openai');
        }
    };

    $providerIds = [];
    $agent = (new Agent('custom-provider'))
        ->provider($provider)
        ->tool('lookup', 'Look up an item', fn (string $query): string => $query);

    $agent->on('after_llm_response', function (AfterLLMResponseEvent $event) use (&$providerIds): void {
        $providerIds[] = $event->provider;
    });

    $agent->prompt('find it');

    expect($providerIds)->toBe(['opencode'])
        ->and($provider->options['tools'][0]['type'])->toBe('function')
        ->and($provider->options['tools'][0]['function']['name'])->toBe('lookup');
});

test('global usage tracking receives each real agent provider response exactly once', function (): void {
    $tracker = usage_tracker();

    $provider = new class implements IdentifiedProvider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => 'tracked',
                'model' => 'tracked-model',
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5],
            ];
        }

        public function providerId(): string
        {
            return 'custom-gateway';
        }

        public function capabilities(): ProviderCapabilities
        {
            return new ProviderCapabilities(protocol: 'custom');
        }
    };

    (new Agent('globally-tracked'))->provider($provider)->prompt('track this');

    expect($tracker->getAll())->toHaveCount(1)
        ->and($tracker->getAll()[0]->agentName)->toBe('globally-tracked')
        ->and($tracker->getAll()[0]->provider)->toBe('custom-gateway');
});

test('an MCP tool adapter can be attached to an agent and used in its tool loop', function (): void {
    $transport = new FakeTransport;
    $transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => ['protocolVersion' => '2024-11-05', 'capabilities' => []],
    ]);

    $client = new McpClient($transport, 'seam-test', '1.0');
    $client->connect();

    $transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => ['content' => [['type' => 'text', 'text' => '4']]],
    ]);

    $adapter = new McpToolAdapter(
        $client,
        'calculator',
        'Calculate an expression',
        ['type' => 'object', 'properties' => ['expression' => ['type' => 'string']]],
    );

    $provider = new class implements IdentifiedProvider
    {
        public int $calls = 0;

        /** @var array<string, mixed> */
        public array $firstOptions = [];

        public function prompt(string $message, array $options = []): object
        {
            $this->calls++;

            if ($this->calls === 1) {
                $this->firstOptions = $options;

                return (object) [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'calculate-1',
                        'name' => 'calculator',
                        'arguments' => ['expression' => '2 + 2'],
                    ]],
                ];
            }

            return (object) ['content' => 'The result is 4.', 'tool_calls' => []];
        }

        public function providerId(): string
        {
            return 'openai';
        }

        public function capabilities(): ProviderCapabilities
        {
            return new ProviderCapabilities(supportsTools: true, protocol: 'openai-chat-completions', toolProtocol: 'openai');
        }
    };

    $response = (new Agent('mcp-agent'))
        ->provider($provider)
        ->tools([$adapter])
        ->prompt('What is 2 + 2?');

    expect($response->content)->toBe('The result is 4.')
        ->and($provider->calls)->toBe(2)
        ->and($provider->firstOptions['tools'][0]['function']['name'])->toBe('calculator');
});
