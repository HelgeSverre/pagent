<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Providers\Mock;

it('creates an agent with a name', function (): void {
    $agent = new Agent('test-agent');

    expect($agent->getName())->toBe('test-agent');
    expect($agent->messages)->toBeArray()->toBeEmpty();
});

it('sets and uses a provider', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock(['responses' => ['hello' => 'Hi there!']]);

    $agent->provider($mock);

    $response = $agent->prompt('hello');
    expect($response->content)->toBe('Hi there!');
});

it('throws exception when no provider is set', function (): void {
    $agent = new Agent('test-agent');

    expect(fn () => $agent->prompt('hello'))
        ->toThrow(RuntimeException::class, "No provider set for agent 'test-agent'");
});

it('configures agent settings fluently', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock;

    $agent
        ->provider($mock)
        ->system('You are helpful')
        ->model('gpt-4.1-mini')
        ->temperature(0.8)
        ->maxTokens(2000);

    // Settings should be passed to provider
    $response = $agent->prompt('test', ['extra' => 'option']);
    expect($response)->toBeObject();
});

it('tracks conversation history', function (): void {
    $agent = new Agent('chat-agent');
    $agent->provider(new Mock);

    expect($agent->messages)->toBeEmpty();

    $agent->prompt('Hello');
    expect($agent->messages)->toHaveCount(2); // user + assistant
    expect($agent->messages[0])->toBe(['role' => 'user', 'content' => 'Hello']);
    expect($agent->messages[1]['role'])->toBe('assistant');

    $agent->prompt('How are you?');
    expect($agent->messages)->toHaveCount(4); // 2 user + 2 assistant
});

it('merges config with prompt options', function (): void {
    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock)
        ->temperature(0.7)
        ->model('base-model');

    // Provider should receive merged options
    $response = $agent->prompt('test', [
        'temperature' => 0.9,  // Override
        'max_tokens' => 100,    // Additional
    ]);

    expect($response)->toBeObject();
});

it('returns the same agent instance for fluent calls', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock;

    $result1 = $agent->provider($mock);
    $result2 = $agent->system('test');
    $result3 = $agent->temperature(0.5);

    expect($result1)->toBe($agent);
    expect($result2)->toBe($agent);
    expect($result3)->toBe($agent);
});

// ========================================
// CRITICAL SECURITY: Tool Call Loop Protection
// ========================================
// These tests document required loop protection to prevent infinite loops
// and resource exhaustion from malicious or buggy tool call chains.

test('it prevents infinite tool call loops', function (): void {
    // Create a mock provider that always returns tool_calls (infinite loop scenario)
    $callCount = 0;
    $mock = new class($callCount) implements \Pagent\Contracts\Provider
    {
        private int $callCount = 0;

        public function __construct(private int &$externalCount) {}

        public function prompt(string $message, array $options = []): object
        {
            $this->callCount++;
            $this->externalCount = $this->callCount;

            // Always return a tool call to create infinite loop
            return (object) [
                'content' => 'Using recursive tool',
                'tool_calls' => [
                    ['id' => 'call_'.$this->callCount, 'name' => 'recursive_tool', 'arguments' => []],
                ],
                'model' => 'mock',
                'tokens' => 10,
                'provider' => 'mock',
            ];
        }
    };

    $agent = new Agent('test-agent');
    $agent->provider($mock);
    $agent->tool('recursive_tool', 'A tool that triggers itself', fn () => ['result' => 'done']);

    // Should throw after maximum depth exceeded (suggested: 10 iterations)
    expect(fn () => $agent->prompt('start'))
        ->toThrow(RuntimeException::class, 'Maximum tool call depth exceeded');
});

test('it handles tool removal during execution gracefully', function (): void {
    $callCount = 0;
    $mock = new class($callCount) implements \Pagent\Contracts\Provider
    {
        private int $callCount = 0;

        public function __construct(private int &$externalCount) {}

        public function prompt(string $message, array $options = []): object
        {
            $this->callCount++;
            $this->externalCount = $this->callCount;

            if ($this->callCount === 1) {
                // First call: request a tool that will be removed
                return (object) [
                    'content' => 'I will use the calculator',
                    'tool_calls' => [
                        ['id' => 'call_1', 'name' => 'calculate', 'arguments' => ['a' => 5, 'b' => 3]],
                    ],
                    'model' => 'mock',
                    'tokens' => 10,
                    'provider' => 'mock',
                ];
            }

            return (object) ['content' => 'Final response', 'model' => 'mock', 'tokens' => 5, 'provider' => 'mock'];
        }
    };

    $agent = new Agent('test-agent');
    $agent->provider($mock);
    $agent->tool('calculate', 'Do math', fn ($a, $b) => $a + $b);

    // Simulate tool removal before execution (race condition)
    // This could happen if tools are dynamically managed
    $agent->clearTools();

    // Should throw with clear error message about missing tool
    expect(fn () => $agent->prompt('test'))
        ->toThrow(RuntimeException::class, "Tool 'calculate' not found");
}); // This test PASSES - current implementation already handles this correctly!

test('it detects circular tool call chains', function (): void {
    $calls = [];
    $mock = new class($calls) implements \Pagent\Contracts\Provider
    {
        private int $callCount = 0;

        public function __construct(private array &$externalCalls) {}

        public function prompt(string $message, array $options = []): object
        {
            $this->callCount++;

            // Simulate circular chain: tool_a → tool_b → tool_a → tool_b ...
            if ($this->callCount % 2 === 1) {
                $this->externalCalls[] = 'tool_a';

                return (object) [
                    'content' => 'Calling tool_a',
                    'tool_calls' => [['id' => 'call_'.$this->callCount, 'name' => 'tool_a', 'arguments' => []]],
                    'model' => 'mock',
                    'tokens' => 10,
                    'provider' => 'mock',
                ];
            } else {
                $this->externalCalls[] = 'tool_b';

                return (object) [
                    'content' => 'Calling tool_b',
                    'tool_calls' => [['id' => 'call_'.$this->callCount, 'name' => 'tool_b', 'arguments' => []]],
                    'model' => 'mock',
                    'tokens' => 10,
                    'provider' => 'mock',
                ];
            }
        }
    };

    $agent = new Agent('test-agent');
    $agent->provider($mock);
    $agent->tool('tool_a', 'Tool A', fn () => 'result_a');
    $agent->tool('tool_b', 'Tool B', fn () => 'result_b');

    // Should detect circular chain and throw before resource exhaustion
    expect(fn () => $agent->prompt('start'))
        ->toThrow(RuntimeException::class, 'Maximum tool call depth exceeded');

    // Should have stopped before excessive calls
    expect(count($calls))->toBeLessThan(20);
});

// ========================================
// Agent::clone() Tests
// ========================================

test('clone preserves telemetry configuration', function (): void {
    $agent = new Agent('original-agent');
    $agent->provider(new Mock);
    $agent->telemetry(true);

    expect($agent->telemetryEnabled)->toBeTrue();

    $cloned = $agent->clone('cloned-agent');

    expect($cloned->getName())->toBe('cloned-agent');
    expect($cloned->telemetryEnabled)->toBeTrue();
});

test('clone does not copy messages or sessionId', function (): void {
    $agent = new Agent('original-agent');
    $agent->provider(new Mock);
    $agent->sessionId('session-123');

    // Add some messages to the original agent
    $agent->prompt('Hello');
    $agent->prompt('How are you?');

    expect($agent->messages)->toHaveCount(4); // 2 user + 2 assistant

    $cloned = $agent->clone('cloned-agent');

    // Cloned agent should have empty messages
    expect($cloned->messages)->toBeEmpty();

    // Verify original agent still has its messages
    expect($agent->messages)->toHaveCount(4);
});
