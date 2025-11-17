# Chapter 21: Testing Strategies

## Why Testing Matters for AI Agents

Testing AI agents presents unique challenges. Unlike traditional applications where outputs are deterministic, LLM responses can vary between runs. However, the framework logic surrounding those responses - conversation management, tool execution, guard validation, error handling - must be rock-solid and testable.

This chapter shows you how to write comprehensive test suites for Pagent agents using the built-in mock provider, Pest PHP test framework, and proven testing patterns from the codebase itself.

**Testing Philosophy:**

- **Unit test framework logic** - Use mock providers to test agent behavior without API calls
- **Integration test real providers** - Verify actual LLM interactions in isolated tests
- **Test edge cases aggressively** - Loop protection, error handling, race conditions
- **Keep tests fast** - Unit tests should run in milliseconds, integration tests separately
- **Make tests readable** - Clear test names and expectations document behavior

## The Mock Provider

The `Mock` provider is your foundation for fast, deterministic unit tests. It implements the `Provider` interface but returns predefined responses instead of making API calls.

### Basic Mock Usage

```php
<?php

declare(strict_types=1);

use function Pagent\mock;

// Create mock with predefined responses
$mockProvider = mock([
    'Hello' => 'Hi there!',
    'What is 2+2?' => '4',
    'Goodbye' => 'See you later!',
]);

$response = $mockProvider->prompt('Hello');
echo $response->content; // "Hi there!"
```

The mock provider returns a standard response object with the same structure as real providers:

```php
$response = $mockProvider->prompt('Hello');

expect($response->content)->toBe('Hi there!');
expect($response->model)->toBe('mock');
expect($response->provider)->toBe('mock');
expect($response->tokens)->toBeInt();
```

### Dynamic Mock Responses

For tests requiring dynamic behavior, use `setResponse()` to configure responses after instantiation:

```php
use Pagent\Providers\Mock;

$mock = new Mock();

// Configure responses dynamically
$mock->setResponse('test prompt', 'test response');
$mock->setResponse('another prompt', 'another response');

$agent = new Agent('test-agent');
$agent->provider($mock);

$response = $agent->prompt('test prompt');
expect($response->content)->toBe('test response');
```

## Unit Testing with Pest

Pagent's test suite uses Pest PHP, a modern testing framework with expressive syntax. The test structure follows Pest's conventions with custom helpers for agent testing.

### Test Helper Functions

The `tests/Pest.php` file provides essential helpers for every test:

```php
// Create a test agent with mock provider
function testAgent(string $name = 'test-agent'): Agent
{
    $agent = new Agent($name);
    $agent->provider(mock());

    return $agent;
}

// Check environment variables
function hasEnv(string $key): bool
{
    return !empty($_ENV[$key] ?? getenv($key));
}

// Skip tests conditionally
function skipIfMissingEnv(string $key, ?string $message = null): void
{
    if (!hasEnv($key)) {
        $message ??= "{$key} not set";
        test()->markTestSkipped($message);
    }
}
```

Use `testAgent()` to quickly create agents for unit tests:

```php
test('it executes tools correctly', function (): void {
    $agent = testAgent('calculator');

    $agent->tool('add', 'Add numbers', fn(int $a, int $b) => $a + $b);

    $result = $agent->executeTool('add', [2, 3]);

    expect($result)->toBe(5);
});
```

### Testing Agent Behavior

Test core agent functionality without API calls:

```php
test('it creates agent with name', function (): void {
    $agent = new Agent('test-agent');

    expect($agent->getName())->toBe('test-agent');
    expect($agent->messages)->toBeArray()->toBeEmpty();
});

test('it tracks conversation history', function (): void {
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

test('it throws exception when no provider is set', function (): void {
    $agent = new Agent('test-agent');

    expect(fn() => $agent->prompt('hello'))
        ->toThrow(RuntimeException::class, "No provider set for agent 'test-agent'");
});
```

### Testing Fluent Configuration

Verify that method chaining works correctly and returns the same instance:

```php
test('it configures agent settings fluently', function (): void {
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

test('it returns the same agent instance for fluent calls', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock;

    $result1 = $agent->provider($mock);
    $result2 = $agent->system('test');
    $result3 = $agent->temperature(0.5);

    expect($result1)->toBe($agent);
    expect($result2)->toBe($agent);
    expect($result3)->toBe($agent);
});
```

## Testing Edge Cases

The most critical tests are those covering edge cases, error conditions, and security scenarios. Pagent's test suite includes comprehensive edge case coverage.

### Loop Protection Tests

Prevent infinite tool call loops that could exhaust resources:

```php
test('it prevents infinite tool call loops', function (): void {
    $callCount = 0;

    // Mock provider that always returns tool_calls
    $mock = new class($callCount) implements \Pagent\Contracts\Provider {
        private int $callCount = 0;

        public function __construct(private int &$externalCount) {}

        public function prompt(string $message, array $options = []): object {
            $this->callCount++;
            $this->externalCount = $this->callCount;

            // Always return a tool call to create infinite loop
            return (object) [
                'content' => 'Using recursive tool',
                'tool_calls' => [
                    ['id' => 'call_' . $this->callCount, 'name' => 'recursive_tool', 'arguments' => []],
                ],
                'model' => 'mock',
                'tokens' => 10,
                'provider' => 'mock',
            ];
        }
    };

    $agent = new Agent('test-agent');
    $agent->provider($mock);
    $agent->tool('recursive_tool', 'A tool that triggers itself', fn() => ['result' => 'done']);

    // Should throw after maximum depth exceeded
    expect(fn() => $agent->prompt('start'))
        ->toThrow(RuntimeException::class, 'Maximum tool call depth exceeded');
});
```

### Tool Execution Error Handling

Test how agents handle tool failures gracefully:

```php
test('it handles tool execution exceptions gracefully', function (): void {
    $agent = testAgent();

    $agent->tool('failing_tool', 'A tool that always fails', function() {
        throw new Exception('Tool execution failed');
    });

    expect(fn() => $agent->executeTool('failing_tool', []))
        ->toThrow(Exception::class, 'Tool execution failed');
});

test('it handles tool with incorrect argument count', function (): void {
    $mockProvider = new class implements \Pagent\Contracts\Provider {
        public function prompt(string $message, array $options = []): object {
            return (object) [
                'content' => 'Using tool',
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'add', 'arguments' => ['a' => 5]], // Missing 'b'
                ],
                'model' => 'mock',
                'tokens' => 10,
                'provider' => 'mock',
            ];
        }
    };

    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);
    $agent->tool('add', 'Add two numbers', fn(int $a, int $b) => $a + $b);

    expect(fn() => $agent->prompt('test'))
        ->toThrow(RuntimeException::class);
});
```

### Testing Multiple Concurrent Tool Calls

Verify agents handle multiple simultaneous tool executions:

```php
test('it handles multiple concurrent tool calls', function (): void {
    $mockProvider = new class implements \Pagent\Contracts\Provider {
        private int $callCount = 0;

        public function prompt(string $message, array $options = []): object {
            $this->callCount++;

            if ($this->callCount === 1) {
                return (object) [
                    'content' => 'Using multiple tools',
                    'tool_calls' => [
                        ['id' => 'call_1', 'name' => 'add', 'arguments' => ['a' => 5, 'b' => 3]],
                        ['id' => 'call_2', 'name' => 'multiply', 'arguments' => ['a' => 4, 'b' => 2]],
                        ['id' => 'call_3', 'name' => 'add', 'arguments' => ['a' => 10, 'b' => 20]],
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
    $agent->provider($mockProvider);
    $agent->tool('add', 'Add', fn(int $a, int $b) => $a + $b);
    $agent->tool('multiply', 'Multiply', fn(int $a, int $b) => $a * $b);

    $response = $agent->prompt('test');

    expect($response->content)->toBe('Final response');
});
```

## Integration Testing with Real Providers

While unit tests use mocks, integration tests verify actual LLM behavior. Use Pest's test groups to separate them:

```php
/**
 * @group api
 * @group anthropic
 */
describe('Anthropic API', function (): void {
    beforeEach(function (): void {
        skipIfMissingEnv('ANTHROPIC_API_KEY');
    });

    it('makes a simple API call', function (): void {
        $anthropic = new Anthropic;

        $response = $anthropic->prompt('Say "Hello from Anthropic" and nothing else.');

        expect($response->content)->toContain('Hello from Anthropic');
        expect($response->provider)->toBe('anthropic');
        expect($response->model)->toContain('claude');
        expect($response->tokens)->toBeGreaterThan(0);
    });

    it('tracks token usage', function (): void {
        $anthropic = new Anthropic;

        $response = $anthropic->prompt('Count to 5', ['max_tokens' => 50]);

        expect($response->usage)->toBeArray();
        expect($response->usage['input_tokens'])->toBeGreaterThan(0);
        expect($response->usage['output_tokens'])->toBeGreaterThan(0);
    });
});
```

Run integration tests separately to keep unit tests fast:

```bash
# Run all tests except API integration
./vendor/bin/pest --exclude-group=api

# Run only API integration tests
./vendor/bin/pest --group=api

# Run tests for specific provider
./vendor/bin/pest --group=anthropic
```

## Testing Helper Functions

Pagent provides global helper functions - test them thoroughly:

```php
test('it creates agent builders with agent() function', function (): void {
    $result = agent('new-agent');

    expect($result)->toBeInstanceOf(AgentBuilder::class);
});

test('it retrieves existing agents with agent() function', function (): void {
    // First create an agent
    agent('existing')
        ->provider('mock')
        ->system('Test agent');

    // Now retrieve it
    $agent = agent('existing');

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent->getName())->toBe('existing');
});

test('it creates mock provider with helper function', function (): void {
    $provider = mock(['test' => 'response']);

    expect($provider)->toBeInstanceOf(Pagent\Providers\Mock::class);

    $response = $provider->prompt('test');
    expect($response->content)->toBe('response');
});
```

## Test Organization Best Practices

Pagent's test suite follows a clear organizational structure:

### Directory Structure

```
tests/
├── Pest.php                    # Global test helpers and setup
├── Unit/                       # Fast unit tests with mocks
│   ├── AgentTest.php          # Core agent functionality
│   ├── AgentToolsTest.php     # Tool execution
│   ├── FunctionsTest.php      # Helper functions
│   └── RegistryTest.php       # Agent registry
└── Integration/                # Slower tests with real APIs
    ├── BasicUsageTest.php     # End-to-end scenarios
    ├── RealAPITest.php        # Provider API calls
    └── ToolCallingTest.php    # Real tool usage
```

### Naming Conventions

- **Test files**: `{Feature}Test.php` (e.g., `AgentTest.php`)
- **Test names**: Descriptive phrases starting with "it" (e.g., `it creates agent with name`)
- **Group markers**: Use `@group` annotations for filtering tests

### beforeEach and afterEach Hooks

Clean up state between tests:

```php
// In tests/Pest.php
beforeEach(function (): void {
    clearAgents(); // Clear agent registry before each test
});
```

## Custom Expectations

Extend Pest with domain-specific expectations:

```php
// In tests/Pest.php
expect()->extend('toBeAgent', fn() => $this->toBeInstanceOf(Agent::class));

expect()->extend('toHaveProvider', function() {
    $agent = $this->value;
    if (!$agent instanceof Agent) {
        throw new InvalidArgumentException('Expected Agent instance');
    }

    try {
        $agent->prompt('test');
        return $this->toBeTrue(); // Has provider
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'No provider set')) {
            return $this->toBeFalse();
        }
        throw $e;
    }
});

// Usage in tests
test('agent has custom expectations', function (): void {
    $agent = testAgent();

    expect($agent)->toBeAgent();
    expect($agent)->toHaveProvider();
});
```

## Continuous Integration

Configure Pest to run in CI environments:

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, curl

      - name: Install Dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run Unit Tests
        run: ./vendor/bin/pest --exclude-group=api

      - name: Run Integration Tests
        if: github.event_name == 'schedule'
        env:
          ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
        run: ./vendor/bin/pest --group=api
```

Run unit tests on every commit, integration tests on schedule or manual trigger to avoid excessive API costs.

## Testing Checklist

When building agents, ensure comprehensive test coverage:

**Unit Tests (with Mock Provider):**
- [ ] Agent creation and configuration
- [ ] Conversation history tracking
- [ ] Tool registration and execution
- [ ] Guard application and validation
- [ ] Error handling for missing providers
- [ ] Fluent method chaining
- [ ] Loop protection limits
- [ ] Edge cases (null responses, malformed data, etc.)

**Integration Tests (with Real Providers):**
- [ ] Basic prompt-response cycles
- [ ] System prompt configuration
- [ ] Temperature and token settings
- [ ] Tool calling with actual LLMs
- [ ] Streaming responses (if supported)
- [ ] Token usage tracking
- [ ] Provider-specific features

**Performance Tests:**
- [ ] Response time benchmarks
- [ ] Memory usage under load
- [ ] Concurrent agent operations
- [ ] Large conversation histories

## What We've Learned

Testing AI agents requires a balanced approach: unit test the framework logic with mock providers, integration test LLM behavior with real APIs, and aggressively test edge cases to prevent production failures.

**Key Takeaways:**

- Use the `Mock` provider for fast, deterministic unit tests
- Test edge cases like loop protection and error handling thoroughly
- Separate unit and integration tests with `@group` annotations
- Create custom test helpers for common patterns
- Keep unit tests fast and run them on every commit
- Run expensive integration tests on schedule or manual trigger
- Document behavior through clear, readable test names

In the next chapter, we'll explore OpenTelemetry integration for monitoring agents in production, giving you visibility into LLM interactions, tool executions, and performance metrics.
