# Chapter 21: Testing Strategies

## What You'll Learn

After completing this chapter, you'll be able to:

- Write comprehensive unit tests for agent behaviors
- Create integration test suites that verify provider interactions
- Implement mock providers for isolated testing
- Identify and test edge cases systematically
- Build automated regression test suites

**Prerequisites:** Understanding of Chapter 20 (Integration Patterns)
**Time Estimate:** 45 minutes
**Final Result:** A complete test suite with unit, integration, and regression tests

## The Testing Pyramid

Testing AI agents presents unique challenges. Unlike deterministic functions, agents produce varied outputs based on model responses. Let's build a testing strategy that provides confidence while handling this variability.

```php
// tests/Unit/AgentBehaviorTest.php
use Pagent\Agent;
use Pagent\Providers\MockProvider;

describe('Agent Behavior Tests', function () {
    it('processes simple queries correctly', function () {
        $agent = Agent::create()
            ->using(new MockProvider([
                'What is 2+2?' => '4',
                'Name a color' => 'Blue'
            ]))
            ->build();

        $response = $agent->prompt('What is 2+2?');

        expect($response->content)->toBe('4')
            ->and($response->model)->toBe('mock');
    });
});
```

This minimal test introduces the core pattern: using mock providers to create predictable test scenarios.

## Unit Testing Agent Components

### Testing Agent Configuration

Start by testing the agent builder's configuration capabilities:

```php
// tests/Unit/AgentConfigurationTest.php
use Pagent\Agent;
use Pagent\Providers\AnthropicProvider;

describe('Agent Configuration', function () {
    it('configures system prompts correctly', function () {
        $agent = Agent::create()
            ->using(new MockProvider())
            ->system('You are a helpful assistant')
            ->build();

        expect($agent->getConfiguration())
            ->system->toBe('You are a helpful assistant')
            ->model->toBe('mock');
    });

    it('sets temperature and max tokens', function () {
        $agent = Agent::create()
            ->using(new MockProvider())
            ->temperature(0.7)
            ->maxTokens(1000)
            ->build();

        $config = $agent->getConfiguration();

        expect($config)
            ->temperature->toBe(0.7)
            ->maxTokens->toBe(1000);
    });

    it('validates temperature bounds', function () {
        expect(fn() => Agent::create()
            ->using(new MockProvider())
            ->temperature(1.5)
            ->build()
        )->toThrow(InvalidArgumentException::class, 'Temperature must be between 0 and 1');
    });
});
```

### Testing Conversation Management

Agents maintain conversation history. Test this stateful behavior:

```php
// tests/Unit/ConversationTest.php
describe('Conversation Management', function () {
    beforeEach(function () {
        $this->agent = Agent::create()
            ->using(new MockProvider([
                'Hello' => 'Hi there!',
                'How are you?' => 'I am doing well, thank you!',
                'Goodbye' => 'See you later!'
            ]))
            ->build();
    });

    it('maintains conversation history', function () {
        $this->agent->prompt('Hello');
        $this->agent->prompt('How are you?');

        $history = $this->agent->getHistory();

        expect($history)->toHaveCount(4) // 2 user + 2 assistant
            ->and($history[0]['role'])->toBe('user')
            ->and($history[0]['content'])->toBe('Hello')
            ->and($history[1]['role'])->toBe('assistant')
            ->and($history[1]['content'])->toBe('Hi there!');
    });

    it('clears history on reset', function () {
        $this->agent->prompt('Hello');
        $this->agent->prompt('How are you?');
        $this->agent->reset();

        expect($this->agent->getHistory())->toBeEmpty();
    });

    it('preserves system prompt after reset', function () {
        $agent = Agent::create()
            ->using(new MockProvider())
            ->system('You are helpful')
            ->build();

        $agent->prompt('Hello');
        $agent->reset();

        expect($agent->getConfiguration()->system)
            ->toBe('You are helpful');
    });
});
```

## Integration Testing with Real Providers

Integration tests verify actual provider interactions. Use environment-based skipping for tests requiring API keys:

```php
// tests/Integration/ProviderIntegrationTest.php
use Pagent\Providers\AnthropicProvider;
use Pagent\Providers\OpenAIProvider;

describe('Provider Integration Tests', function () {
    it('communicates with Anthropic API', function () {
        if (!env('ANTHROPIC_API_KEY')) {
            $this->markTestSkipped('Anthropic API key not configured');
        }

        $agent = Agent::create()
            ->using(new AnthropicProvider(env('ANTHROPIC_API_KEY')))
            ->model('claude-3-haiku-20240307')
            ->maxTokens(50)
            ->build();

        $response = $agent->prompt('Say "test successful" and nothing else');

        expect($response->content)
            ->toContain('test successful', ignoreCase: true)
            ->and($response->usage->input_tokens)->toBeGreaterThan(0)
            ->and($response->usage->output_tokens)->toBeLessThanOrEqual(50);
    })->group('api');

    it('handles OpenAI streaming responses', function () {
        if (!env('OPENAI_API_KEY')) {
            $this->markTestSkipped('OpenAI API key not configured');
        }

        $agent = Agent::create()
            ->using(new OpenAIProvider(env('OPENAI_API_KEY')))
            ->model('gpt-3.5-turbo')
            ->stream()
            ->build();

        $chunks = [];
        $response = $agent->prompt('Count from 1 to 5', function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });

        expect($chunks)->not->toBeEmpty()
            ->and($response->content)->toContain('1')
            ->and($response->content)->toContain('5');
    })->group('api');
});
```

## Mock Provider Development

Create sophisticated mock providers for complex testing scenarios:

```php
// tests/Support/AdvancedMockProvider.php
namespace Tests\Support;

use Pagent\Contracts\Provider;
use Pagent\Response;

class AdvancedMockProvider implements Provider
{
    private array $responses;
    private array $callHistory = [];
    private int $callCount = 0;
    private ?float $simulatedDelay = null;
    private ?callable $responseCallback = null;

    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function withDelay(float $seconds): self
    {
        $this->simulatedDelay = $seconds;
        return $this;
    }

    public function withCallback(callable $callback): self
    {
        $this->responseCallback = $callback;
        return $this;
    }

    public function complete(array $messages, array $options = []): Response
    {
        // Record the call
        $this->callHistory[] = [
            'messages' => $messages,
            'options' => $options,
            'timestamp' => microtime(true)
        ];
        $this->callCount++;

        // Simulate delay if configured
        if ($this->simulatedDelay) {
            usleep($this->simulatedDelay * 1000000);
        }

        // Get the last user message
        $lastMessage = collect($messages)
            ->where('role', 'user')
            ->last();

        $prompt = $lastMessage['content'] ?? '';

        // Determine response
        $content = $this->determineResponse($prompt, $messages, $options);

        // Apply callback if set
        if ($this->responseCallback) {
            $content = call_user_func($this->responseCallback, $content, $prompt, $messages);
        }

        return new Response(
            content: $content,
            model: 'mock-advanced',
            usage: [
                'input_tokens' => strlen($prompt),
                'output_tokens' => strlen($content),
                'total_tokens' => strlen($prompt) + strlen($content)
            ],
            metadata: [
                'call_count' => $this->callCount,
                'simulated_delay' => $this->simulatedDelay
            ]
        );
    }

    private function determineResponse(string $prompt, array $messages, array $options): string
    {
        // Check for exact match
        if (isset($this->responses[$prompt])) {
            return $this->responses[$prompt];
        }

        // Check for pattern match
        foreach ($this->responses as $pattern => $response) {
            if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                $regex = substr($pattern, 1, -1);
                if (preg_match($regex, $prompt)) {
                    return $response;
                }
            }
        }

        // Check for sequential responses
        if (isset($this->responses['_sequence'])) {
            $sequence = $this->responses['_sequence'];
            $index = ($this->callCount - 1) % count($sequence);
            return $sequence[$index];
        }

        // Default response
        return $this->responses['_default'] ?? 'Mock response';
    }

    public function getCallHistory(): array
    {
        return $this->callHistory;
    }

    public function assertCalled(int $times): void
    {
        expect($this->callCount)->toBe($times);
    }

    public function assertLastPromptContains(string $text): void
    {
        $lastCall = end($this->callHistory);
        $lastMessage = collect($lastCall['messages'])
            ->where('role', 'user')
            ->last();

        expect($lastMessage['content'])->toContain($text);
    }
}
```

Use the advanced mock in tests:

```php
// tests/Unit/AdvancedMockTest.php
use Tests\Support\AdvancedMockProvider;

describe('Advanced Mock Testing', function () {
    it('simulates delays for performance testing', function () {
        $mock = new AdvancedMockProvider(['test' => 'response']);
        $mock->withDelay(0.1); // 100ms delay

        $agent = Agent::create()
            ->using($mock)
            ->build();

        $start = microtime(true);
        $agent->prompt('test');
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThan(0.1);
    });

    it('uses pattern matching for responses', function () {
        $mock = new AdvancedMockProvider([
            '/^translate .+ to Spanish$/' => 'Hola',
            '/^calculate \d+ \+ \d+$/' => '42',
            '_default' => 'Unknown command'
        ]);

        $agent = Agent::create()->using($mock)->build();

        expect($agent->prompt('translate hello to Spanish')->content)
            ->toBe('Hola')
            ->and($agent->prompt('calculate 20 + 22')->content)
            ->toBe('42')
            ->and($agent->prompt('random text')->content)
            ->toBe('Unknown command');
    });

    it('provides sequential responses', function () {
        $mock = new AdvancedMockProvider([
            '_sequence' => ['First', 'Second', 'Third']
        ]);

        $agent = Agent::create()->using($mock)->build();

        expect($agent->prompt('any')->content)->toBe('First')
            ->and($agent->prompt('any')->content)->toBe('Second')
            ->and($agent->prompt('any')->content)->toBe('Third')
            ->and($agent->prompt('any')->content)->toBe('First'); // Cycles back
    });
});
```

## Edge Case Testing

Identify and test boundary conditions systematically:

```php
// tests/Unit/EdgeCaseTest.php
describe('Edge Case Handling', function () {
    beforeEach(function () {
        $this->mock = new AdvancedMockProvider();
        $this->agent = Agent::create()->using($this->mock)->build();
    });

    it('handles empty prompts', function () {
        expect(fn() => $this->agent->prompt(''))
            ->toThrow(InvalidArgumentException::class, 'Prompt cannot be empty');
    });

    it('handles extremely long prompts', function () {
        $longPrompt = str_repeat('a', 100000);

        expect(fn() => $this->agent->prompt($longPrompt))
            ->toThrow(InvalidArgumentException::class, 'Prompt exceeds maximum length');
    });

    it('handles special characters in prompts', function () {
        $specialChars = "Hello\n\r\t\0\x0B";
        $response = $this->agent->prompt($specialChars);

        expect($response->content)->toBeString();
    });

    it('handles concurrent requests', function () {
        $promises = [];

        for ($i = 0; $i < 10; $i++) {
            $promises[] = async(fn() => $this->agent->prompt("Request $i"));
        }

        $responses = await($promises);

        expect($responses)->toHaveCount(10)
            ->each->toBeInstanceOf(Response::class);
    });

    it('recovers from provider errors', function () {
        $mock = new AdvancedMockProvider();
        $mock->withCallback(function ($content, $prompt) {
            if (str_contains($prompt, 'error')) {
                throw new Exception('Provider error');
            }
            return 'Success';
        });

        $agent = Agent::create()
            ->using($mock)
            ->withRetry(3, 100)
            ->build();

        expect(fn() => $agent->prompt('trigger error'))
            ->toThrow(Exception::class)
            ->and($agent->prompt('normal request')->content)
            ->toBe('Success');
    });
});
```

## Regression Test Automation

Build automated regression tests that capture and verify expected behaviors:

```php
// tests/Regression/RegressionTestCase.php
namespace Tests\Regression;

use Pagent\Agent;
use PHPUnit\Framework\TestCase;

abstract class RegressionTestCase extends TestCase
{
    protected string $snapshotDir = __DIR__ . '/snapshots';

    protected function assertResponseMatches(string $name, Response $response): void
    {
        $snapshotFile = "{$this->snapshotDir}/{$name}.json";

        if (!file_exists($snapshotFile)) {
            // Create snapshot on first run
            $this->createSnapshot($snapshotFile, $response);
            $this->markTestSkipped('Snapshot created. Re-run to test.');
        }

        $snapshot = json_decode(file_get_contents($snapshotFile), true);

        // Compare structure, not exact content
        expect($response->toArray())
            ->toHaveKeys(array_keys($snapshot))
            ->and($response->model)->toBe($snapshot['model'])
            ->and(strlen($response->content))
            ->toBeBetween(
                strlen($snapshot['content']) * 0.8,
                strlen($snapshot['content']) * 1.2
            );
    }

    private function createSnapshot(string $file, Response $response): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($file, json_encode($response->toArray(), JSON_PRETTY_PRINT));
    }
}

// tests/Regression/PromptRegressionTest.php
use Tests\Regression\RegressionTestCase;

class PromptRegressionTest extends RegressionTestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::create()
            ->using(new MockProvider([
                'Summarize this text' => 'This is a summary of the provided text.',
                'Translate to French' => 'Ceci est une traduction en français.',
                'Generate code' => 'function example() { return "code"; }'
            ]))
            ->build();
    }

    /**
     * @dataProvider regressionPrompts
     */
    public function testPromptRegression(string $prompt, string $snapshotName): void
    {
        $response = $this->agent->prompt($prompt);
        $this->assertResponseMatches($snapshotName, $response);
    }

    public function regressionPrompts(): array
    {
        return [
            ['Summarize this text', 'summarize_prompt'],
            ['Translate to French', 'translate_prompt'],
            ['Generate code', 'generate_code_prompt']
        ];
    }
}
```

## Performance Testing

Create performance benchmarks to detect regressions:

```php
// tests/Performance/PerformanceTest.php
describe('Performance Benchmarks', function () {
    it('processes single requests within time limit', function () {
        $mock = new MockProvider(['test' => 'response']);
        $agent = Agent::create()->using($mock)->build();

        $start = microtime(true);
        $agent->prompt('test');
        $duration = microtime(true) - $start;

        expect($duration)->toBeLessThan(0.01); // 10ms limit
    });

    it('handles batch requests efficiently', function () {
        $mock = new MockProvider(['test' => 'response']);
        $agent = Agent::create()->using($mock)->build();

        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $agent->prompt('test');
        }
        $duration = microtime(true) - $start;

        expect($duration)->toBeLessThan(0.5); // 500ms for 100 requests
    });

    it('maintains memory efficiency', function () {
        $mock = new MockProvider(['test' => 'response']);
        $agent = Agent::create()->using($mock)->build();

        $memStart = memory_get_usage();

        for ($i = 0; $i < 1000; $i++) {
            $agent->prompt('test');
            if ($i % 100 === 0) {
                $agent->reset(); // Clear history periodically
            }
        }

        $memEnd = memory_get_usage();
        $memUsed = ($memEnd - $memStart) / 1024 / 1024; // MB

        expect($memUsed)->toBeLessThan(10); // Less than 10MB growth
    });
});
```

## Test Organization and Automation

Create a comprehensive test suite structure:

```php
// tests/TestSuite.php
namespace Tests;

class TestSuite
{
    public static function runAll(): array
    {
        return [
            'unit' => shell_exec('vendor/bin/pest tests/Unit'),
            'integration' => shell_exec('vendor/bin/pest tests/Integration --group=api'),
            'regression' => shell_exec('vendor/bin/pest tests/Regression'),
            'performance' => shell_exec('vendor/bin/pest tests/Performance')
        ];
    }

    public static function runCritical(): array
    {
        return [
            'unit' => shell_exec('vendor/bin/pest tests/Unit --filter="critical"'),
            'smoke' => shell_exec('vendor/bin/pest tests/Integration --filter="smoke"')
        ];
    }
}

// composer.json scripts
{
    "scripts": {
        "test": "pest",
        "test:unit": "pest tests/Unit",
        "test:integration": "pest tests/Integration --group=api",
        "test:regression": "pest tests/Regression",
        "test:performance": "pest tests/Performance",
        "test:coverage": "pest --coverage --min=80",
        "test:ci": [
            "@test:unit",
            "@test:regression",
            "@test:performance"
        ]
    }
}
```

## Summary

You've learned comprehensive testing strategies for AI agents:

**Key Concepts Mastered:**

- Unit testing with mock providers
- Integration testing with real APIs
- Advanced mock provider development
- Edge case identification and testing
- Regression test automation
- Performance benchmarking

**Testing Best Practices:**

1. Use mocks for predictable unit tests
2. Group API tests for optional execution
3. Test edge cases systematically
4. Automate regression detection
5. Monitor performance metrics
6. Maintain test organization

## Next Steps

In Chapter 22, we'll explore deployment strategies, covering containerization, CI/CD pipelines, and production monitoring for your agent-powered applications.

**Key Takeaways:**

- Mock providers enable deterministic testing of non-deterministic agents
- Integration tests verify real-world behavior but should be grouped for flexibility
- Regression tests capture expected behaviors and detect deviations
- Performance tests prevent degradation over time
- Comprehensive test suites provide confidence in agent reliability

## Exercises

1. **Create Custom Mock**: Build a mock provider that simulates API rate limiting
2. **Test Error Recovery**: Write tests for network failures and retry logic
3. **Benchmark Providers**: Compare performance across different providers
4. **Snapshot Testing**: Implement visual regression tests for formatted outputs
5. **Load Testing**: Create tests that simulate concurrent user sessions

## Additional Resources

- [Pest PHP Documentation](https://pestphp.com/)
- [PHPUnit Assertions](https://docs.phpunit.de/en/10.5/assertions.html)
- [Testing Best Practices](https://martinfowler.com/testing/)
- [Mock Object Patterns](https://martinfowler.com/articles/mocksArentStubs.html)
