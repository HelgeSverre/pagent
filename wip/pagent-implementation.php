<?php

/**
 * HOW PEST WORKS UNDER THE HOOD
 * 
 * Pest is essentially a layer on top of PHPUnit that provides:
 * 1. Global functions that register tests
 * 2. A test repository that collects all registered tests
 * 3. A custom test case that extends PHPUnit
 * 4. A runner that executes everything
 */

// =============================================================================
// PART 1: HOW PEST WORKS
// =============================================================================

namespace Pest\Core;

// When you call test() or it(), Pest stores it in a repository
class TestRepository
{
    private static array $tests = [];
    private static array $currentFile = null;
    
    public static function add(string $description, \Closure $closure, array $modifiers = [])
    {
        self::$tests[] = [
            'file' => self::$currentFile,
            'description' => $description,
            'closure' => $closure,
            'modifiers' => $modifiers, // skip, only, group, etc.
        ];
    }
    
    public static function getCurrentTests(): array
    {
        return self::$tests;
    }
}

// The global test() function just registers the test
function test(string $description, \Closure $closure = null)
{
    return new PendingTest($description, $closure);
}

class PendingTest
{
    private string $description;
    private ?\Closure $closure;
    private array $modifiers = [];
    
    public function __construct(string $description, ?\Closure $closure)
    {
        $this->description = $description;
        $this->closure = $closure;
    }
    
    public function skip($condition = true): self
    {
        $this->modifiers['skip'] = $condition;
        return $this;
    }
    
    public function with($dataset): self
    {
        $this->modifiers['dataset'] = $dataset;
        return $this;
    }
    
    public function __destruct()
    {
        // When the object is destroyed, register the test
        TestRepository::add($this->description, $this->closure, $this->modifiers);
    }
}

// Pest creates actual PHPUnit test cases dynamically
class DynamicTestCase extends \PHPUnit\Framework\TestCase
{
    private \Closure $testClosure;
    
    public function setTestClosure(\Closure $closure): void
    {
        $this->testClosure = $closure;
    }
    
    public function runTest(): mixed
    {
        // Bind $this to the closure so you can use $this->assertTrue(), etc.
        return $this->testClosure->bindTo($this, static::class)();
    }
}

// =============================================================================
// PART 2: IMPLEMENTING PAGENT - THE LLM FRAMEWORK
// =============================================================================

namespace Pagent\Core;

/**
 * Core Agent Repository - Similar to Pest's Test Repository
 */
class AgentRepository
{
    private static array $agents = [];
    private static array $behaviors = [];
    private static array $chains = [];
    
    public static function registerAgent(string $name, array $config): void
    {
        self::$agents[$name] = $config;
    }
    
    public static function registerBehavior(string $agentName, string $description, \Closure $test): void
    {
        self::$behaviors[$agentName][] = [
            'description' => $description,
            'test' => $test
        ];
    }
    
    public static function getAgent(string $name): ?Agent
    {
        if (!isset(self::$agents[$name])) {
            return null;
        }
        
        return new Agent($name, self::$agents[$name]);
    }
}

/**
 * The Agent Builder - Fluent API for configuration
 */
class AgentBuilder
{
    private string $name;
    private array $config = [];
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
    
    public function provider(string $class, array $options = []): self
    {
        $this->config['provider'] = [
            'class' => $class,
            'options' => $options
        ];
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
    
    public function tools(array $tools): self
    {
        $this->config['tools'] = $tools;
        return $this;
    }
    
    public function __destruct()
    {
        // Register when builder is destroyed (like Pest)
        AgentRepository::registerAgent($this->name, $this->config);
    }
}

/**
 * The actual Agent class that does the work
 */
class Agent
{
    private string $name;
    private array $config;
    private $provider;
    private array $context = [];
    private array $messages = [];
    private array $toolInstances = [];
    
    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->config = $config;
        $this->initializeProvider();
        $this->initializeTools();
    }
    
    private function initializeProvider(): void
    {
        $providerClass = $this->config['provider']['class'];
        $options = $this->config['provider']['options'];
        $this->provider = new $providerClass($options);
    }
    
    private function initializeTools(): void
    {
        foreach ($this->config['tools'] ?? [] as $toolClass) {
            $this->toolInstances[] = new $toolClass();
        }
    }
    
    public function prompt(string $message): Response
    {
        // Add to message history
        $this->messages[] = ['role' => 'user', 'content' => $message];
        
        // Prepare the full prompt
        $fullPrompt = $this->buildPrompt();
        
        // Call the LLM provider
        $response = $this->provider->complete([
            'messages' => $fullPrompt,
            'temperature' => $this->config['temperature'] ?? 0.7,
            'tools' => $this->getToolDefinitions(),
        ]);
        
        // Handle tool calls if any
        if ($response->hasToolCalls()) {
            $response = $this->executeTools($response);
        }
        
        // Add response to history
        $this->messages[] = ['role' => 'assistant', 'content' => $response->content];
        
        return new Response($response);
    }
    
    private function buildPrompt(): array
    {
        $messages = [];
        
        // System prompt
        if (isset($this->config['system_prompt'])) {
            $messages[] = ['role' => 'system', 'content' => $this->config['system_prompt']];
        }
        
        // Context
        if (!empty($this->context)) {
            $messages[] = [
                'role' => 'system', 
                'content' => 'Context: ' . json_encode($this->context)
            ];
        }
        
        // Conversation history
        return array_merge($messages, $this->messages);
    }
    
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }
}

/**
 * Response wrapper with expectation-style API
 */
class Response
{
    private $rawResponse;
    public string $content;
    public array $metadata;
    
    public function __construct($rawResponse)
    {
        $this->rawResponse = $rawResponse;
        $this->content = $rawResponse->content;
        $this->metadata = $rawResponse->metadata ?? [];
    }
    
    public function __toString(): string
    {
        return $this->content;
    }
}

/**
 * Global helper functions (like Pest's test(), it(), etc.)
 */
function agent(string $name): AgentBuilder|Agent
{
    // If agent exists, return it for use
    if ($existing = AgentRepository::getAgent($name)) {
        return $existing;
    }
    
    // Otherwise return builder for configuration
    return new AgentBuilder($name);
}

function it(string $description, \Closure $behavior): PendingBehavior
{
    return new PendingBehavior($description, $behavior);
}

function chain(string $name): ChainBuilder
{
    return new ChainBuilder($name);
}

/**
 * Expectation API for LLM responses
 */
class Expectation
{
    private $value;
    
    public function __construct($value)
    {
        $this->value = $value;
    }
    
    public function toContain($expected): self
    {
        if (is_array($expected)) {
            foreach ($expected as $item) {
                if (!str_contains($this->value, $item)) {
                    throw new \Exception("Expected to contain '{$item}'");
                }
            }
        } else {
            if (!str_contains($this->value, $expected)) {
                throw new \Exception("Expected to contain '{$expected}'");
            }
        }
        return $this;
    }
    
    public function toMatchTone(string $expectedTone): self
    {
        // Use a simple sentiment analyzer or another LLM call
        $analyzer = new ToneAnalyzer();
        $tone = $analyzer->analyze($this->value);
        
        if ($tone !== $expectedTone) {
            throw new \Exception("Expected tone '{$expectedTone}', got '{$tone}'");
        }
        
        return $this;
    }
    
    public function not(): Expectation
    {
        // Return a negated expectation
        return new NegatedExpectation($this->value);
    }
}

function expect($value): Expectation
{
    return new Expectation($value);
}

/**
 * Tool System Implementation
 */
abstract class Tool
{
    abstract public function getName(): string;
    abstract public function getDescription(): string;
    abstract public function getParameters(): array;
    abstract public function execute(array $params): mixed;
}

class WebSearch extends Tool
{
    public function getName(): string
    {
        return 'web_search';
    }
    
    public function getDescription(): string
    {
        return 'Search the web for information';
    }
    
    public function getParameters(): array
    {
        return [
            'query' => ['type' => 'string', 'required' => true]
        ];
    }
    
    public function execute(array $params): mixed
    {
        // Actual web search implementation
        $client = new \GuzzleHttp\Client();
        $response = $client->get('https://api.search.com', [
            'query' => ['q' => $params['query']]
        ]);
        
        return json_decode($response->getBody(), true);
    }
}

/**
 * The Test Runner - Executes behavior tests
 */
class BehaviorRunner
{
    public function run(string $agentName = null): void
    {
        $behaviors = AgentRepository::getBehaviors($agentName);
        
        foreach ($behaviors as $behavior) {
            try {
                // Create a fresh agent instance
                $agent = AgentRepository::getAgent($behavior['agent']);
                
                // Run the behavior test
                $behavior['test']($agent);
                
                echo "✓ {$behavior['description']}\n";
            } catch (\Exception $e) {
                echo "✗ {$behavior['description']}: {$e->getMessage()}\n";
            }
        }
    }
}

/**
 * Autoloader for Pagent files (similar to Pest.php)
 */
class Autoloader
{
    public static function load(string $directory): void
    {
        // Load all *Agent.php files
        $files = glob($directory . '/*Agent.php');
        foreach ($files as $file) {
            require_once $file;
        }
        
        // Load Pagent.php configuration file
        if (file_exists($directory . '/Pagent.php')) {
            require_once $directory . '/Pagent.php';
        }
    }
}

// =============================================================================
// PART 3: USAGE EXAMPLE
// =============================================================================

// In your agents/CustomerSupport.php file:

agent('customer-support')
    ->provider(OpenAI::class, ['model' => 'gpt-4'])
    ->systemPrompt('You are a helpful customer support agent')
    ->temperature(0.7)
    ->tools([WebSearch::class, Database::class]);

it('handles refunds appropriately', function () {
    $response = agent('customer-support')
        ->withContext(['order_id' => '12345'])
        ->prompt('I want a refund');
    
    expect($response->content)
        ->toContain('refund')
        ->toMatchTone('professional');
});

// The pagent CLI command would work like:
// ./vendor/bin/pagent run           # Run all behavior tests
// ./vendor/bin/pagent serve         # Start agent server
// ./vendor/bin/pagent evaluate      # Run evaluation suites

/**
 * How the CLI would bootstrap everything:
 */
class PagentCommand
{
    public function run(array $argv): void
    {
        // Load composer autoloader
        require 'vendor/autoload.php';
        
        // Load Pagent configuration
        Autoloader::load(getcwd() . '/agents');
        
        $command = $argv[1] ?? 'run';
        
        switch ($command) {
            case 'run':
                // Run behavior tests
                $runner = new BehaviorRunner();
                $runner->run();
                break;
                
            case 'serve':
                // Start HTTP server for agents
                $server = new AgentServer();
                $server->start();
                break;
                
            case 'evaluate':
                // Run evaluation suites
                $evaluator = new Evaluator();
                $evaluator->run();
                break;
        }
    }
}