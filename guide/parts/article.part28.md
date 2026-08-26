# Chapter 28: Building Complex Systems

**Learning Objectives:**

- Design scalable agent architectures using extension interfaces
- Implement event-driven patterns for agent coordination
- Create reusable extension patterns via interface composition
- Build maintainable multi-agent systems with orchestration
- Develop agent management strategies for production deployments

---

## Why Architecture Matters

When you move beyond single-agent prototypes into production systems, architecture becomes critical. A chatbot handling 10 requests a day has different needs than a multi-agent workflow processing thousands of documents. Poor architecture leads to brittle code, difficult testing, and maintenance nightmares.

Pagent's design philosophy embraces **interface-based extensibility** over built-in plugins. Rather than providing a plugin system with its own lifecycle and conventions, Pagent exposes clean contracts that you implement directly. This approach gives you maximum flexibility while maintaining type safety and testability.

Think of it like building with LEGO blocks—Pagent provides the core pieces (Agent, Provider, Tool, Guard, Middleware), and you combine them to create complex systems. The patterns in this chapter show you how.

## Understanding Extension Points

Pagent's architecture centers on six key interfaces that define extension points. Each interface represents a specific concern in the agent lifecycle:

```php
<?php

namespace Pagent\Contracts;

// 1. Provider - LLM integration
interface Provider {
    public function prompt(string $message, array $options = []): object;
}

// 2. Tool - provider-neutral agent capabilities
interface Tool {
    public function getName(): string;
    public function getDescription(): string;
    public function getInputSchema(): array;
    public function execute(array $arguments): mixed;
}

// 3. Guard - Safety and validation
interface Guard {
    public function check(string $input, string $output): bool;
    public function getName(): string;
    public function getViolationMessage(): string;
}

// 4. Middleware - Request/response transformation
interface Middleware {
    public function before(string $message, array $options): array;
    public function after(object $response): object;
}

// 5. Memory - Conversation persistence
interface Memory {
    public function load(string $sessionId): array;
    public function save(string $sessionId, array $messages): void;
    public function delete(string $sessionId): void;
    public function exists(string $sessionId): bool;
    public function prune(string $sessionId, int $maxMessages): array;
}

// 6. Metric - Performance evaluation
interface Metric {
    public function getName(): string;
    public function calculate(string $input, string $output, mixed $expected = null): float;
    public function getDescription(): string;
}
```

These interfaces aren't just abstractions—they're architectural boundaries. Each one defines a clear responsibility and enables dependency injection, testing, and composition.

## Pattern 1: Composable Extensions

The simplest pattern is building composable extension classes that implement Pagent's interfaces. Let's create a domain-specific extension bundle for a customer support agent:

```php
<?php

declare(strict_types=1);

namespace App\Extensions\Support;

use Pagent\Agent;
use Pagent\Contracts\Guard;
use Pagent\Contracts\Tool;
use Pagent\Contracts\Middleware;

/**
 * Bundled extensions for customer support agents
 */
final class SupportExtensions
{
    public function __construct(
        private readonly DatabaseConnection $db,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Apply all support extensions to an agent
     */
    public function install(Agent $agent): void
    {
        // Add tools
        $agent->tool($this->createTicketTool());
        $agent->tool($this->createKnowledgeBaseTool());
        $agent->tool($this->createEscalationTool());

        // Add guards
        $agent->guard($this->createPIIGuard());
        $agent->guard($this->createSentimentGuard());

        // Add middleware
        $agent->middleware($this->createLoggingMiddleware());
        $agent->middleware($this->createCachingMiddleware());
    }

    private function createTicketTool(): Tool
    {
        return new class($this->db) implements Tool {
            public function __construct(
                private readonly DatabaseConnection $db
            ) {}

            public function getName(): string
            {
                return 'create_ticket';
            }

            public function getDescription(): string
            {
                return 'Create a support ticket in the system';
            }

            public function execute(array $params): mixed
            {
                $ticketId = $this->db->insert('tickets', [
                    'title' => $params['title'],
                    'description' => $params['description'],
                    'priority' => $params['priority'] ?? 'medium',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                return [
                    'ticket_id' => $ticketId,
                    'status' => 'created',
                    'url' => "https://tickets.example.com/{$ticketId}",
                ];
            }

            public function getInputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Short ticket title'],
                        'description' => ['type' => 'string', 'description' => 'Detailed issue description'],
                        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                    ],
                    'required' => ['title', 'description'],
                ];
            }
        };
    }

    private function createPIIGuard(): Guard
    {
        return new class implements Guard {
            private const PII_PATTERNS = [
                '/\b\d{3}-\d{2}-\d{4}\b/', // SSN
                '/\b\d{16}\b/',             // Credit card
                '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', // Email
            ];

            public function check(string $input, string $output): bool
            {
                foreach (self::PII_PATTERNS as $pattern) {
                    if (preg_match($pattern, $output)) {
                        return false;
                    }
                }
                return true;
            }

            public function getName(): string
            {
                return 'pii_protection';
            }

            public function getViolationMessage(): string
            {
                return 'Response contains potentially sensitive information';
            }
        };
    }

    private function createLoggingMiddleware(): Middleware
    {
        return new class($this->logger) implements Middleware {
            public function __construct(
                private readonly LoggerInterface $logger
            ) {}

            public function before(string $message, array $options): array
            {
                $this->logger->info('Agent request', [
                    'message' => $message,
                    'timestamp' => microtime(true),
                ]);

                return [$message, $options];
            }

            public function after(object $response): object
            {
                $this->logger->info('Agent response', [
                    'content_length' => strlen($response->content),
                    'timestamp' => microtime(true),
                ]);

                return $response;
            }
        };
    }

    private function createKnowledgeBaseTool(): Tool
    {
        // Implementation similar to createTicketTool()...
        return new KnowledgeBaseTool($this->db);
    }

    private function createEscalationTool(): Tool
    {
        // Implementation for escalating to human agents...
        return new EscalationTool($this->db);
    }

    private function createSentimentGuard(): Guard
    {
        // Implementation for checking response sentiment...
        return new SentimentGuard();
    }

    private function createCachingMiddleware(): Middleware
    {
        // Implementation for caching common responses...
        return new CachingMiddleware($this->cache);
    }
}
```

Usage is straightforward:

```php
use function Pagent\agent;

$agent = agent('support-agent')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful customer support agent')
    ->build();

// Apply extensions bundle
$extensions = new SupportExtensions($db, $cache, $logger);
$extensions->install($agent);

// Agent now has all support capabilities
$response = $agent->prompt('I need to reset my password');
```

This pattern keeps extension logic organized, testable, and reusable across multiple agents. You can create different bundles for different domains—`MarketingExtensions`, `AnalyticsExtensions`, `ModeratorExtensions`—each encapsulating domain-specific tools, guards, and middleware.

## Pattern 2: Event-Driven Architecture

For complex systems with multiple agents and external integrations, event-driven patterns provide loose coupling and scalability. While Pagent doesn't include a built-in event system, middleware provides natural hooks for event dispatch:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use Pagent\Agent;
use Pagent\Contracts\Middleware;

interface EventDispatcher
{
    public function dispatch(string $event, array $data): void;
}

/**
 * Middleware that dispatches events during agent lifecycle
 */
final class EventMiddleware implements Middleware
{
    private Agent $agent;

    public function __construct(
        private readonly EventDispatcher $dispatcher,
    ) {}

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function before(string $message, array $options): array
    {
        $this->dispatcher->dispatch('agent.request.before', [
            'agent' => $this->agent->getName(),
            'message' => $message,
            'options' => $options,
            'timestamp' => microtime(true),
        ]);

        return [$message, $options];
    }

    public function after(object $response): object
    {
        $this->dispatcher->dispatch('agent.response.after', [
            'agent' => $this->agent->getName(),
            'response' => $response,
            'timestamp' => microtime(true),
        ]);

        // Check for tool calls
        if (isset($response->content) && str_contains($response->content, 'tool_use')) {
            $this->dispatcher->dispatch('agent.tool.called', [
                'agent' => $this->agent->getName(),
                'response' => $response,
            ]);
        }

        return $response;
    }
}

/**
 * Simple in-memory event dispatcher
 */
final class SimpleEventDispatcher implements EventDispatcher
{
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, array $data): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener($data);
        }
    }
}
```

Now you can build reactive systems where components respond to agent events:

```php
<?php

use App\Events\SimpleEventDispatcher;
use App\Events\EventMiddleware;

$dispatcher = new SimpleEventDispatcher();

// Register event listeners
$dispatcher->listen('agent.request.before', function (array $data) {
    // Log to analytics
    Analytics::track('agent_request', $data);
});

$dispatcher->listen('agent.tool.called', function (array $data) {
    // Send notification to monitoring system
    Monitor::toolUsed($data['agent'], $data['response']);
});

$dispatcher->listen('agent.response.after', function (array $data) use ($db) {
    // Store interaction for training data
    $db->insert('interactions', [
        'agent' => $data['agent'],
        'response_length' => strlen($data['response']->content),
        'timestamp' => $data['timestamp'],
    ]);
});

// Create agents with event middleware
$agent = agent('chatbot')
    ->provider('anthropic')
    ->build();

$eventMiddleware = new EventMiddleware($dispatcher);
$eventMiddleware->setAgent($agent);
$agent->middleware($eventMiddleware);

// All agent interactions now trigger events
$response = $agent->prompt('Hello!');
// Events fired:
// 1. agent.request.before
// 2. agent.response.after
```

This pattern enables powerful integrations—logging, monitoring, caching, rate limiting, A/B testing—all without modifying agent code. The agent remains focused on its core responsibility while infrastructure concerns live in event listeners.

## Pattern 3: Multi-Agent Orchestration

Complex workflows often require multiple specialized agents working together. Pagent's orchestration primitives—`Pipeline`, `Handoff`, and `Delegation`—provide the foundation for building these systems.

### Agent Registry for Global Management

The `Registry` class enables global agent management:

```php
<?php

use function Pagent\agent;
use Pagent\Registry;

// Create specialized agents
agent('researcher')
    ->provider('anthropic')
    ->system('You are a research assistant. Gather facts and cite sources.')
    ->build();

agent('writer')
    ->provider('anthropic')
    ->system('You are a creative writer. Transform research into engaging prose.')
    ->build();

agent('editor')
    ->provider('anthropic')
    ->system('You are an editor. Improve clarity, grammar, and structure.')
    ->build();

// Access agents anywhere in your application
$researcher = Registry::get('researcher');
$writer = Registry::get('writer');
$editor = Registry::get('editor');

// Check if agents exist
if (Registry::has('researcher')) {
    $response = Registry::get('researcher')->prompt('Research PHP agents');
}

// Get all registered agents
$allAgents = Registry::all();
foreach ($allAgents as $name => $agent) {
    echo "Agent: {$name}\n";
}
```

### Building Complex Workflows with Pipelines

The `Pipeline` class chains agents for sequential processing:

```php
<?php

use Pagent\Orchestration\Pipeline;

$contentPipeline = new Pipeline('article-production');

$contentPipeline
    ->agent('researcher', fn($topic) => "Research this topic thoroughly: {$topic}")
    ->agent('writer', fn($research) => "Write an article based on: {$research}")
    ->agent('editor', fn($draft) => "Edit and improve: {$draft}")
    ->onError(function ($exception, $stageIndex, $agentName) {
        // Handle pipeline failures gracefully
        Log::error("Pipeline failed at stage {$stageIndex} ({$agentName})", [
            'error' => $exception->getMessage(),
        ]);

        return "Pipeline failed. Please try again.";
    });

$article = $contentPipeline->run('The Future of PHP Agents');

// Inspect pipeline results
foreach ($contentPipeline->getResults() as $result) {
    echo "Stage {$result['stage']} ({$result['agent']}):\n";
    echo "Output length: " . strlen($result['output']) . " chars\n\n";
}
```

The transform closures adapt output from one agent into appropriate input for the next. This enables complex data transformations while maintaining clean agent interfaces.

### Dynamic Agent Handoffs

The `Handoff` class transfers conversations between agents based on context:

```php
<?php

use Pagent\Orchestration\Handoff;

$supportAgent = agent('support')
    ->provider('anthropic')
    ->system('You are tier-1 support. Handle basic questions or escalate.')
    ->build();

$response = $supportAgent->prompt('My account was hacked!');

// Check if escalation needed
if (str_contains(strtolower($response->content), 'security')) {
    $handoff = new Handoff($supportAgent);

    $securityAgent = $handoff
        ->to('security-specialist')
        ->because('Security incident requiring specialized handling')
        ->transfer();

    // Security agent has full context from support conversation
    $finalResponse = $securityAgent->prompt('Please investigate this issue');
}
```

### Supervised Delegation

The `Delegation` class implements manager-worker patterns with optional supervision:

```php
<?php

use Pagent\Orchestration\Delegation;

$manager = agent('project-manager')
    ->provider('anthropic')
    ->system('You are a project manager reviewing deliverables.')
    ->build();

$coder = agent('coder')
    ->provider('anthropic')
    ->system('You are a software engineer.')
    ->build();

$delegation = new Delegation($manager, 'Implement user authentication');

$result = $delegation
    ->to($coder)
    ->supervise(function (string $output, string $task) {
        // Quality check the work
        $hasTests = str_contains($output, 'test');
        $hasDocumentation = str_contains($output, 'documentation');

        if (!$hasTests) {
            return 'Please include unit tests for the implementation.';
        }

        if (!$hasDocumentation) {
            return 'Please add documentation for the authentication flow.';
        }

        return true; // Approved
    })
    ->onComplete(function ($result) {
        // Log completion
        Log::info("Task completed: {$result->task}", [
            'worker' => $result->worker,
            'supervised' => $result->supervised,
        ]);
    })
    ->review()
    ->execute();

echo "Manager review: {$result->manager_review}\n";
```

## Pattern 4: Scaling and Production Concerns

Production deployments require considering performance, reliability, and maintainability.

### Connection Pooling for Providers

Custom providers can implement connection pooling for high-throughput scenarios:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Pagent\Contracts\Provider;

final class PooledAnthropicProvider implements Provider
{
    private array $connectionPool = [];

    private int $maxConnections = 10;

    private int $currentConnection = 0;

    public function __construct(
        private readonly string $apiKey,
    ) {
        // Initialize connection pool
        for ($i = 0; $i < $this->maxConnections; $i++) {
            $this->connectionPool[$i] = new AnthropicClient($this->apiKey);
        }
    }

    public function prompt(string $message, array $options = []): object
    {
        // Round-robin connection selection
        $connection = $this->connectionPool[$this->currentConnection];
        $this->currentConnection = ($this->currentConnection + 1) % $this->maxConnections;

        return $connection->sendRequest($message, $options);
    }
}
```

### Circuit Breaker Pattern

Protect against cascading failures in multi-agent systems:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Contracts\Middleware;
use RuntimeException;

final class CircuitBreakerMiddleware implements Middleware
{
    private int $failures = 0;

    private bool $open = false;

    private ?float $openedAt = null;

    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $timeout = 60,
    ) {}

    public function before(string $message, array $options): array
    {
        if ($this->open) {
            if (microtime(true) - $this->openedAt > $this->timeout) {
                // Reset circuit after timeout
                $this->open = false;
                $this->failures = 0;
            } else {
                throw new RuntimeException('Circuit breaker is open');
            }
        }

        return [$message, $options];
    }

    public function after(object $response): object
    {
        // Reset failure count on success
        $this->failures = 0;
        return $response;
    }

    public function onError(): void
    {
        $this->failures++;

        if ($this->failures >= $this->threshold) {
            $this->open = true;
            $this->openedAt = microtime(true);
        }
    }
}
```

### Agent Factory Pattern

Centralize agent configuration for consistency:

```php
<?php

declare(strict_types=1);

namespace App\Factories;

use Pagent\Agent;
use function Pagent\agent;

final class AgentFactory
{
    public function __construct(
        private readonly array $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function createSupportAgent(): Agent
    {
        $agent = agent('support-' . uniqid())
            ->provider($this->config['provider'])
            ->model($this->config['models']['support'])
            ->temperature(0.7)
            ->system($this->config['prompts']['support'])
            ->build();

        $this->applyStandardExtensions($agent);

        return $agent;
    }

    public function createAnalystAgent(): Agent
    {
        $agent = agent('analyst-' . uniqid())
            ->provider($this->config['provider'])
            ->model($this->config['models']['analyst'])
            ->temperature(0.2) // Lower temperature for analytical tasks
            ->system($this->config['prompts']['analyst'])
            ->build();

        $this->applyStandardExtensions($agent);

        return $agent;
    }

    private function applyStandardExtensions(Agent $agent): void
    {
        // Apply logging, monitoring, guards, etc.
        $agent->middleware(new LoggingMiddleware($this->logger));
        $agent->guard(new ContentPolicyGuard());
    }
}
```

## Key Takeaways

Building complex agent systems requires thoughtful architecture. Pagent's interface-based approach gives you the flexibility to:

1. **Compose extensions** through clean interface implementations
2. **Implement event-driven patterns** using middleware as event hooks
3. **Orchestrate multi-agent workflows** with Pipeline, Handoff, and Delegation
4. **Scale to production** with pooling, circuit breakers, and factories

Remember: interfaces over plugins, composition over inheritance, and clear boundaries over tight coupling. These principles will serve you well as your agent systems grow from prototypes to production.

In the next chapter, we'll explore testing strategies for complex agent architectures, ensuring your systems remain reliable as they evolve.
