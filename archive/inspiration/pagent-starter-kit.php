<?php

declare(strict_types=1);

/**
 * PAGENT STARTER KIT
 *
 * A minimal but working implementation you can actually run
 * Save this as pagent-demo.php and run: php pagent-demo.php
 */

// =====================================================================
// Core Framework (this would be in vendor/pagent/pagent/src/)
// =====================================================================

namespace Pagent\Core {

    final class Registry
    {
        private static array $agents = [];
        private static array $tests = [];

        public static function addAgent(string $name, callable $factory): void
        {
            self::$agents[$name] = $factory;
        }

        public static function getAgent(string $name): ?Agent
        {
            if (! isset(self::$agents[$name])) {
                return null;
            }
            return call_user_func(self::$agents[$name]);
        }

        public static function addTest(string $description, callable $test): void
        {
            self::$tests[] = ['description' => $description, 'test' => $test];
        }

        public static function runTests(): array
        {
            $results = [];
            foreach (self::$tests as $test) {
                try {
                    call_user_func($test['test']);
                    $results[] = ['status' => 'pass', 'test' => $test['description']];
                    echo "✓ {$test['description']}\n";
                } catch (\Exception $e) {
                    $results[] = [
                        'status' => 'fail',
                        'test' => $test['description'],
                        'error' => $e->getMessage(),
                    ];
                    echo "✗ {$test['description']}: {$e->getMessage()}\n";
                }
            }
            return $results;
        }
    }

    final class Agent
    {
        private array $config;
        private array $messages = [];
        private array $context = [];

        public function __construct(array $config)
        {
            $this->config = $config;
        }

        public function prompt(string $message): object
        {
            // Add to message history
            $this->messages[] = ['role' => 'user', 'content' => $message];

            // In a real implementation, this would call the LLM API
            $response = $this->mockLLMCall($message);

            $this->messages[] = ['role' => 'assistant', 'content' => $response];

            return (object) [
                'content' => $response,
                'model' => $this->config['model'] ?? 'mock',
                'usage' => ['tokens' => mb_strlen($message) + mb_strlen($response)],
            ];
        }

        public function withContext(array $context): self
        {
            $this->context = array_merge($this->context, $context);
            return $this;
        }

        private function mockLLMCall(string $message): string
        {
            // Simulate different responses based on input
            $responses = [
                'hello' => 'Hello! How can I assist you today?',
                'refund' => 'I understand you need help with a refund. Can you please provide your order number?',
                'order' => 'I can see your order #12345. It was delivered on January 15th. How can I help you with this order?',
                'weather' => 'I\'m a customer support agent, so I can\'t help with weather information. Is there anything else I can help you with regarding your account or orders?',
            ];

            foreach ($responses as $keyword => $response) {
                if (false !== mb_stripos($message, $keyword)) {
                    return $response;
                }
            }

            return 'I understand. Could you please provide more details so I can better assist you?';
        }
    }

    final class AgentBuilder
    {
        private string $name;
        private array $config = [];

        public function __construct(string $name)
        {
            $this->name = $name;
        }

        public function model(string $model): self
        {
            $this->config['model'] = $model;
            return $this;
        }

        public function systemPrompt(string $prompt): self
        {
            $this->config['system_prompt'] = $prompt;
            return $this;
        }

        public function temperature(float $temp): self
        {
            $this->config['temperature'] = $temp;
            return $this;
        }

        public function register(): void
        {
            $config = $this->config; // Capture config in closure
            Registry::addAgent($this->name, fn () => new Agent($config));
        }
    }

    final class Expectation
    {
        private $value;
        private bool $negated = false;

        public function __construct($value)
        {
            $this->value = $value;
        }

        public function not(): self
        {
            $this->negated = true;
            return $this;
        }

        public function toContain($needle): self
        {
            $contains = is_string($this->value) && str_contains($this->value, $needle);

            if ($this->negated && $contains) {
                throw new \Exception("Did not expect '{$this->value}' to contain '{$needle}'");
            }

            if (! $this->negated && ! $contains) {
                throw new \Exception("Expected '{$this->value}' to contain '{$needle}'");
            }

            return $this;
        }

        public function toBe($expected): self
        {
            $equals = $this->value === $expected;

            if ($this->negated && $equals) {
                throw new \Exception("Did not expect value to be '{$expected}'");
            }

            if (! $this->negated && ! $equals) {
                throw new \Exception("Expected '{$expected}', got '{$this->value}'");
            }

            return $this;
        }

        public function toBeString(): self
        {
            $isString = is_string($this->value);

            if ($this->negated && $isString) {
                throw new \Exception("Did not expect value to be a string");
            }

            if (! $this->negated && ! $isString) {
                throw new \Exception("Expected value to be a string, got " . gettype($this->value));
            }

            return $this;
        }
    }
}

// =====================================================================
// Global Functions (this would be in src/functions.php)
// =====================================================================

namespace {

    use Pagent\Core\{AgentBuilder, Expectation, Registry};

    function agent(string $name)
    {
        // Try to get existing agent
        if ($agent = Registry::getAgent($name)) {
            return $agent;
        }

        // Return builder for new agent
        $builder = new AgentBuilder($name);
        return new class ($builder) {
            private AgentBuilder $builder;

            public function __construct(AgentBuilder $builder)
            {
                $this->builder = $builder;
            }

            public function __destruct()
            {
                $this->builder->register();
            }

            public function __call($method, $args)
            {
                $this->builder->{$method}(...$args);
                return $this;
            }
        };
    }

    function it(string $description, callable $test): void
    {
        Registry::addTest($description, $test);
    }

    function expect($value): Expectation
    {
        return new Expectation($value);
    }
}

// =====================================================================
// Example Usage (this would be in your agents/ directory)
// =====================================================================

// Define a customer support agent
agent('customer-support')
    ->model('gpt-4')
    ->systemPrompt('You are a helpful customer support agent. Be professional and empathetic.')
    ->temperature(0.7);

// Define an email assistant agent
agent('email-assistant')
    ->model('gpt-3.5-turbo')
    ->systemPrompt('You help users write professional emails.')
    ->temperature(0.8);

// Write behavior tests
it('responds professionally to greetings', function (): void {
    $response = agent('customer-support')->prompt('hello there!');

    expect($response->content)
        ->toBeString()
        ->toContain('Hello')
        ->toContain('assist');
});

it('handles refund requests appropriately', function (): void {
    $response = agent('customer-support')->prompt('I want a refund!');

    expect($response->content)
        ->toContain('refund')
        ->toContain('order number');
});

it('stays within role boundaries', function (): void {
    $response = agent('customer-support')->prompt('What\'s the weather like?');

    expect($response->content)
        ->not()->toContain('weather forecast')
        ->toContain('customer support');
});

it('maintains context in conversation', function (): void {
    $agent = agent('customer-support');

    $agent->prompt('My order number is 12345');
    $response = $agent->prompt('What\'s the status?');

    expect($response->content)->toContain('12345');
});

// =====================================================================
// Run the demo
// =====================================================================

echo "\n🤖 Pagent Demo - LLM Agent Testing Framework\n";
echo "==========================================\n\n";

// Run all tests
echo "Running behavior tests...\n\n";
$results = Pagent\Core\Registry::runTests();

// Summary
$passed = count(array_filter($results, fn ($r) => 'pass' === $r['status']));
$failed = count($results) - $passed;

echo "\n==========================================\n";
echo "Results: {$passed} passed, {$failed} failed\n\n";

// Interactive demo
echo "Interactive Demo:\n";
echo "-----------------\n";

$agent = agent('customer-support');
$prompts = [
    "Hello, I need help!",
    "I'd like a refund for my recent order",
    "The order number is 12345",
];

foreach ($prompts as $prompt) {
    echo "\n👤 User: {$prompt}\n";
    $response = $agent->prompt($prompt);
    echo "🤖 Agent: {$response->content}\n";
}

echo "\n==========================================\n";
echo "✨ That's how Pagent works!\n\n";

/**
 * To create a real implementation:
 *
 * 1. Replace mockLLMCall() with actual API calls to OpenAI/Anthropic
 * 2. Add persistent storage for conversation history
 * 3. Implement real tool calling (web search, database, etc.)
 * 4. Add comprehensive error handling and retries
 * 5. Build out the evaluation framework with real metrics
 * 6. Create a proper CLI tool with Symfony Console
 * 7. Add configuration file support (YAML/JSON)
 * 8. Implement caching and rate limiting
 * 9. Add observability with OpenTelemetry
 * 10. Package it properly with Composer
 *
 * The core concepts remain the same - this demo shows the essential patterns!
 */
