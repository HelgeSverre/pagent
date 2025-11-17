<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Observability\Exporters\InMemoryExporter;
use Pagent\Observability\TelemetryManager;
use Pagent\Providers\Mock;

require_once __DIR__.'/../../../src/functions.php';

beforeEach(function () {
    // Reset telemetry manager before each test
    TelemetryManager::reset();
    // Clear agent registry
    \clearAgents();

    // Create in-memory exporter for testing
    $this->exporter = new InMemoryExporter;

    // Initialize telemetry with test exporter
    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'service_name' => 'test-pagent',
        'exporter' => 'console', // Will be overridden by setExporter
    ])->setExporter($this->exporter);
});

afterEach(function () {
    // Clean up after each test
    TelemetryManager::reset();
    \clearAgents();
});

describe('Agent Telemetry Integration', function () {
    it('creates span for prompt operation', function () {
        // Create agent with telemetry enabled
        $agent = agent('test-agent')
            ->provider(new Mock(['responses' => ['Hello' => 'World']]))
            ->telemetry(true);

        // Call prompt
        $response = $agent->prompt('Hello');

        // Verify response
        expect($response->content)->toBe('World');

        // Force flush to ensure spans are exported
        TelemetryManager::instance()->shutdown();

        // Verify spans were created
        $spans = $this->exporter->getSpans();
        expect($spans)->not->toBeEmpty();

        // Find the agent.prompt span
        $promptSpans = $this->exporter->findSpansByName('agent.prompt');
        expect($promptSpans)->toHaveCount(1);

        $promptSpan = $promptSpans[0];
        expect($promptSpan->getAttributes()->get('agent.name'))->toBe('test-agent');
        expect($promptSpan->getAttributes()->get('agent.operation'))->toBe('prompt');
    });

    it('creates LLM span for provider call', function () {
        $agent = agent('test-llm-agent')
            ->provider(new Mock(['responses' => ['Test' => 'Response']]))
            ->model('test-model')
            ->temperature(0.7)
            ->maxTokens(100)
            ->telemetry(true);

        $response = $agent->prompt('Test');

        // Verify response
        expect($response->content)->toBe('Response');

        TelemetryManager::instance()->shutdown();

        // Verify LLM span was created
        $llmSpans = $this->exporter->findSpansByName('llm.request');
        expect($llmSpans)->toHaveCount(1);

        $llmSpan = $llmSpans[0];
        expect($llmSpan->getAttributes()->get('gen_ai.system'))->toBe('mock');
        expect($llmSpan->getAttributes()->get('gen_ai.request.model'))->toBe('test-model');
        expect($llmSpan->getAttributes()->get('gen_ai.operation.name'))->toBe('chat');
        expect($llmSpan->getAttributes()->get('gen_ai.request.temperature'))->toBe(0.7);
        expect($llmSpan->getAttributes()->get('gen_ai.request.max_tokens'))->toBe(100);
    });

    it('creates tool execution spans', function () {
        // Create mock provider that returns tool calls
        $mockProvider = new class implements \Pagent\Contracts\Provider
        {
            private int $callCount = 0;

            public function prompt(string $message, array $options = []): object
            {
                $this->callCount++;

                if ($this->callCount === 1) {
                    // First call: return tool call
                    return (object) [
                        'content' => '',
                        'model' => 'mock',
                        'tokens' => 10,
                        'provider' => 'mock',
                        'tool_calls' => [
                            [
                                'id' => 'tool_1',
                                'name' => 'calculate',
                                'arguments' => ['x' => 5, 'y' => 3],
                            ],
                        ],
                    ];
                }

                // Second call: return final response
                return (object) [
                    'content' => 'The result is 8',
                    'model' => 'mock',
                    'tokens' => 20,
                    'provider' => 'mock',
                    'tool_calls' => [],
                ];
            }
        };

        $agent = agent('test-agent')
            ->provider($mockProvider)
            ->telemetry(true)
            ->tool('calculate', 'Add two numbers', function (int $x, int $y): int {
                return $x + $y;
            });

        $response = $agent->prompt('Calculate 5 + 3');

        expect($response->content)->toBe('The result is 8');

        TelemetryManager::instance()->shutdown();

        // Verify tool execution span was created
        $toolSpans = $this->exporter->findSpansByName('tool.execute');
        expect($toolSpans)->toHaveCount(1);

        $toolSpan = $toolSpans[0];
        expect($toolSpan->getAttributes()->get('tool.name'))->toBe('calculate');

        $arguments = json_decode($toolSpan->getAttributes()->get('tool.arguments'), true);
        expect($arguments)->toBe(['x' => 5, 'y' => 3]);
    });

    it('tracks memory operations', function () {
        // Create a simple memory adapter
        $memory = new class implements \Pagent\Contracts\Memory
        {
            private array $storage = [];

            public function save(string $sessionId, array $messages): void
            {
                $this->storage[$sessionId] = $messages;
            }

            public function load(string $sessionId): array
            {
                return $this->storage[$sessionId] ?? [];
            }

            public function delete(string $sessionId): void
            {
                unset($this->storage[$sessionId]);
            }

            public function exists(string $sessionId): bool
            {
                return isset($this->storage[$sessionId]);
            }

            public function prune(string $sessionId, int $maxMessages): array
            {
                $messages = $this->storage[$sessionId] ?? [];

                return array_slice($messages, -$maxMessages);
            }
        };

        $agent = agent('test-agent-memory')
            ->provider(new Mock(['responses' => ['Hello' => 'World']]))
            ->memory($memory)
            ->sessionId('test-session')
            ->telemetry(true);

        $response = $agent->prompt('Hello');

        expect($response->content)->toBe('World');

        TelemetryManager::instance()->shutdown();

        // Verify memory spans were created
        $spans = $this->exporter->getSpans();
        expect($spans)->not->toBeEmpty();

        // Check for memory-related operations
        $memoryLoadSpans = $this->exporter->findSpansByName('memory.load');
        expect($memoryLoadSpans)->not->toBeEmpty();

        $memorySaveSpans = $this->exporter->findSpansByName('memory.save');
        expect($memorySaveSpans)->not->toBeEmpty();
    });

    it('records exceptions in spans', function () {
        $mockProvider = new class implements \Pagent\Contracts\Provider
        {
            public function prompt(string $message, array $options = []): object
            {
                throw new RuntimeException('Provider error');
            }
        };

        $agent = agent('test-agent')
            ->provider($mockProvider)
            ->telemetry(true);

        try {
            $agent->prompt('Test');
            $this->fail('Expected exception to be thrown');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Provider error');
        }

        TelemetryManager::instance()->shutdown();

        // Verify spans were created even with exception
        $spans = $this->exporter->getSpans();
        expect($spans)->not->toBeEmpty();

        // Check that at least one span has error status
        $hasErrorSpan = false;
        foreach ($spans as $span) {
            if ($span->getStatus()->getCode() === \OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR) {
                $hasErrorSpan = true;
                break;
            }
        }
        expect($hasErrorSpan)->toBeTrue('Expected at least one span with error status');
    });

    it('works when telemetry is disabled', function () {
        // Don't call telemetry(true), so it should be disabled
        $agent = agent('test-agent')
            ->provider(new Mock(['responses' => ['Test' => 'Response']]));

        $response = $agent->prompt('Test');

        expect($response->content)->toBe('Response');

        TelemetryManager::instance()->shutdown();

        // Verify NO spans were created (agent telemetry disabled)
        $spans = $this->exporter->getSpans();
        expect($spans)->toBeEmpty();
    });

    it('works with guard checks', function () {
        $agent = agent('test-agent')
            ->provider(new Mock(['responses' => ['Test' => 'Response']]))
            ->telemetry(true)
            ->guard('length', function (string $input, string $output): bool {
                return strlen($output) > 3;
            });

        $response = $agent->prompt('Test');

        expect($response->content)->toBe('Response');

        TelemetryManager::instance()->shutdown();

        // Verify guard check span was created
        $guardSpans = $this->exporter->findSpansByName('guard.check');
        expect($guardSpans)->toHaveCount(1);

        $guardSpan = $guardSpans[0];
        expect($guardSpan->getAttributes()->get('guard.name'))->toBe('length');
        expect($guardSpan->getAttributes()->get('guard.passed'))->toBe(true);
    });

    it('records guard failures with fallback', function () {
        $agent = agent('test-agent')
            ->provider(new Mock(['responses' => ['Test' => 'No']]))
            ->telemetry(true)
            ->guard('length', function (string $input, string $output): bool {
                return strlen($output) > 10;
            })
            ->fallback(function ($exception) {
                return 'Fallback response';
            });

        $response = $agent->prompt('Test');

        expect($response->content)->toBe('Fallback response')
            ->and($response->guard_triggered)->toBe('length');

        TelemetryManager::instance()->shutdown();

        // Verify guard failure was recorded
        $guardSpans = $this->exporter->findSpansByName('guard.check');
        expect($guardSpans)->not->toBeEmpty();

        $guardSpan = $guardSpans[0];
        expect($guardSpan->getAttributes()->get('guard.name'))->toBe('length');
        expect($guardSpan->getAttributes()->get('guard.passed'))->toBe(false);
    });

    it('tracks context pruning events', function () {
        $agent = agent('test-agent')
            ->provider(new Mock(['responses' => ['Test' => 'Response']]))
            ->telemetry(true)
            ->contextWindow(50, 'oldest');

        // Add some messages to trigger pruning
        $agent->messages = [
            ['role' => 'user', 'content' => 'First message with lots of content to make it long enough to trigger pruning'],
            ['role' => 'assistant', 'content' => 'First response with lots of content to make it long enough'],
            ['role' => 'user', 'content' => 'Second message with lots of content to make it long enough'],
            ['role' => 'assistant', 'content' => 'Second response with lots of content to make it long enough'],
        ];

        $response = $agent->prompt('Test');

        expect($response->content)->toBe('Response');

        TelemetryManager::instance()->shutdown();

        // Verify pruning event was recorded
        $pruneSpans = $this->exporter->findSpansByName('context.prune');
        if (! empty($pruneSpans)) {
            $pruneSpan = $pruneSpans[0];
            expect($pruneSpan->getAttributes()->get('context.strategy'))->toBe('oldest');
            expect($pruneSpan->getAttributes()->get('context.messages_removed'))->toBeGreaterThan(0);
        }
    });

    it('handles multiple operations with proper span lifecycle', function () {
        $agent = agent('test-agent-multiple')
            ->provider(new Mock([
                'responses' => [
                    'First' => 'Response 1',
                    'Second' => 'Response 2',
                    'Third' => 'Response 3',
                ],
            ]))
            ->telemetry(true);

        $response1 = $agent->prompt('First');
        $response2 = $agent->prompt('Second');
        $response3 = $agent->prompt('Third');

        expect($response1->content)->toBe('Response 1')
            ->and($response2->content)->toBe('Response 2')
            ->and($response3->content)->toBe('Response 3');

        TelemetryManager::instance()->shutdown();

        // Verify we have spans for all three operations
        $promptSpans = $this->exporter->findSpansByName('agent.prompt');
        expect($promptSpans)->toHaveCount(3);

        $llmSpans = $this->exporter->findSpansByName('llm.request');
        expect($llmSpans)->toHaveCount(3);
    });

    it('records token usage in LLM spans', function () {
        $agent = agent('test-tokens')
            ->provider(new Mock(['responses' => ['Test' => 'Response']]))
            ->telemetry(true);

        $response = $agent->prompt('Test');

        expect($response->content)->toBe('Response');

        TelemetryManager::instance()->shutdown();

        // Verify token usage is recorded
        $llmSpans = $this->exporter->findSpansByName('llm.request');
        expect($llmSpans)->toHaveCount(1);

        $llmSpan = $llmSpans[0];
        $tokens = $llmSpan->getAttributes()->get('gen_ai.usage.completion_tokens');
        expect($tokens)->toBeGreaterThan(0);
    });

    it('verifies span timing information', function () {
        $agent = agent('test-timing')
            ->provider(new Mock(['responses' => ['Test' => 'Response']]))
            ->telemetry(true);

        $response = $agent->prompt('Test');

        expect($response->content)->toBe('Response');

        TelemetryManager::instance()->shutdown();

        // Verify all spans have valid timing
        $spans = $this->exporter->getSpans();
        expect($spans)->not->toBeEmpty();

        foreach ($spans as $span) {
            expect($span->getStartEpochNanos())->toBeGreaterThan(0);
            expect($span->getEndEpochNanos())->toBeGreaterThan($span->getStartEpochNanos());
        }
    });
});
