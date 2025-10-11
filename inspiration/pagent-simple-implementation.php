<?php

declare(strict_types=1);

/**
 * SIMPLE PAGENT IMPLEMENTATION
 *
 * Let's build a minimal version to understand the core concepts
 */

// =============================================================================
// Step 1: The Global State (like Pest's test repository)
// =============================================================================

final class Pagent
{
    private static array $agents = [];
    private static array $tests = [];
    private static ?string $currentAgent = null;

    public static function registerAgent(string $name, array $config): void
    {
        self::$agents[$name] = $config;
        self::$currentAgent = $name;
    }

    public static function registerTest(string $description, callable $test): void
    {
        self::$tests[] = [
            'agent' => self::$currentAgent,
            'description' => $description,
            'test' => $test,
        ];
    }

    public static function getAgent(string $name): ?array
    {
        return self::$agents[$name] ?? null;
    }

    public static function runTests(): void
    {
        foreach (self::$tests as $test) {
            echo "Running: {$test['description']}\n";
            try {
                $agent = new Agent($test['agent'], self::$agents[$test['agent']]);
                call_user_func($test['test'], $agent);
                echo "✓ Passed\n";
            } catch (Exception $e) {
                echo "✗ Failed: {$e->getMessage()}\n";
            }
        }
    }
}

// =============================================================================
// Step 2: The Global Functions (like test(), it(), expect())
// =============================================================================

function agent(string $name)
{
    // Check if we're retrieving or defining
    if ($config = Pagent::getAgent($name)) {
        return new Agent($name, $config);
    }

    // Return a builder for definition
    return new class ($name) {
        private string $name;
        private array $config = [];

        public function __construct(string $name)
        {
            $this->name = $name;
        }

        public function __destruct()
        {
            Pagent::registerAgent($this->name, $this->config);
        }

        public function provider(string $provider, array $options = []): self
        {
            $this->config['provider'] = $provider;
            $this->config['options'] = $options;
            return $this;
        }

        public function systemPrompt(string $prompt): self
        {
            $this->config['system_prompt'] = $prompt;
            return $this;
        }
    };
}

function it(string $description, callable $test): void
{
    Pagent::registerTest($description, $test);
}

function expect($value)
{
    return new class ($value) {
        private $value;

        public function __construct($value)
        {
            $this->value = $value;
        }

        public function toContain(string $expected): self
        {
            if ( ! str_contains($this->value, $expected)) {
                throw new Exception("Expected '{$this->value}' to contain '{$expected}'");
            }
            return $this;
        }

        public function toBe($expected): self
        {
            if ($this->value !== $expected) {
                throw new Exception("Expected '{$expected}', got '{$this->value}'");
            }
            return $this;
        }
    };
}

// =============================================================================
// Step 3: The Agent Implementation
// =============================================================================

final class Agent
{
    private string $name;
    private array $config;
    private array $messages = [];

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->config = $config;
    }

    public function prompt(string $message): string
    {
        // For demo, we'll use a mock response
        // In reality, this would call OpenAI/Anthropic API

        $this->messages[] = ['role' => 'user', 'content' => $message];

        // Simulate API call
        $response = $this->callLLM($message);

        $this->messages[] = ['role' => 'assistant', 'content' => $response];

        return $response;
    }

    private function callLLM(string $message): string
    {
        // Mock implementation
        if (str_contains($message, 'hello')) {
            return 'Hello! How can I help you today?';
        }
        if (str_contains($message, 'refund')) {
            return 'I can help you with your refund. Can you provide your order number?';
        }
        return 'I understand. How can I assist you further?';
    }
}

// =============================================================================
// Step 4: Usage Example
// =============================================================================

// Define an agent (this would be in agents/CustomerSupport.php)
agent('customer-support')
    ->provider('openai', ['model' => 'gpt-4'])
    ->systemPrompt('You are a helpful customer support agent');

// Define tests for the agent
it('responds politely to greetings', function ($agent): void {
    $response = $agent->prompt('hello');
    expect($response)->toContain('Hello');
});

it('handles refund requests', function ($agent): void {
    $response = $agent->prompt('I need a refund');
    expect($response)->toContain('refund');
    expect($response)->toContain('order number');
});

// =============================================================================
// Step 5: The CLI Runner
// =============================================================================

// This would be in vendor/bin/pagent
if ('cli' === php_sapi_name()) {
    // Simple autoloader
    require_once 'vendor/autoload.php';

    // Load all agent files
    foreach (glob('agents/*.php') as $file) {
        require_once $file;
    }

    // Run the tests
    Pagent::runTests();
}

// =============================================================================
// How to extend this:
// =============================================================================

/**
 * 1. Add Real LLM Providers:
 */
interface Provider
{
    public function complete(array $messages, array $options = []): array;
}

final class OpenAIProvider implements Provider
{
    private string $apiKey;

    public function __construct(array $options)
    {
        $this->apiKey = $options['api_key'] ?? getenv('OPENAI_API_KEY');
    }

    public function complete(array $messages, array $options = []): array
    {
        $client = new GuzzleHttp\Client();
        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
            'json' => [
                'model' => $options['model'] ?? 'gpt-4',
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? 0.7,
            ],
        ]);

        return json_decode($response->getBody(), true);
    }
}

/**
 * 2. Add Tool Support:
 */
trait HasTools
{
    private array $tools = [];

    public function registerTool(string $name, callable $function, string $description): void
    {
        $this->tools[$name] = [
            'function' => $function,
            'description' => $description,
        ];
    }

    public function executeTool(string $name, array $params): mixed
    {
        if ( ! isset($this->tools[$name])) {
            throw new Exception("Tool {$name} not found");
        }

        return call_user_func($this->tools[$name]['function'], $params);
    }
}

/**
 * 3. Add Context Management:
 */
trait HasContext
{
    private array $context = [];

    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

/**
 * 4. Add Evaluation Framework:
 */
final class Evaluator
{
    private array $metrics = [];

    public function evaluate(string $agentName, array $dataset): array
    {
        $agent = agent($agentName);
        $results = [];

        foreach ($dataset as $item) {
            $response = $agent->prompt($item['input']);
            $results[] = [
                'input' => $item['input'],
                'expected' => $item['expected'] ?? null,
                'actual' => $response,
                'metrics' => $this->calculateMetrics($response, $item['expected'] ?? ''),
            ];
        }

        return $results;
    }

    private function calculateMetrics(string $actual, string $expected): array
    {
        return [
            'similarity' => similar_text($actual, $expected) / max(mb_strlen($actual), mb_strlen($expected)),
            'length' => mb_strlen($actual),
            'response_time' => 0.123, // Would be actual timing
        ];
    }
}

/**
 * 5. Directory Structure:
 *
 * project/
 * ├── agents/
 * │   ├── Pagent.php           # Global configuration
 * │   ├── CustomerSupport.php  # Agent definitions
 * │   └── DataAnalyst.php
 * ├── tests/
 * │   ├── CustomerSupportTest.php
 * │   └── datasets/
 * │       └── support_tickets.json
 * ├── composer.json
 * └── pagent.yml               # Configuration file
 */
