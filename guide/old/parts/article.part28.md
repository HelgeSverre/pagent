# Part 28: Building Complex Systems

## What You'll Learn

By the end of this chapter, you'll be able to:
- Design scalable agent architectures for enterprise applications
- Implement event-driven patterns for agent communication
- Create plugin systems that extend agent capabilities
- Build extensible frameworks that others can build upon
- Develop agent marketplaces for sharing and discovering agents

**Prerequisites**: All previous chapters, especially Part 23 (Patterns and Best Practices) and Part 27 (Real-world Applications)

**Time Estimate**: 45-60 minutes

**Final Result**: A complete enterprise agent system with plugin architecture and event-driven orchestration

## Understanding Complex Agent Systems

Before diving into code, let's understand what makes a system "complex" in the context of AI agents:

1. **Multiple Agent Types**: Different agents for different purposes
2. **Inter-agent Communication**: Agents working together
3. **Plugin Architecture**: Extensible capabilities
4. **Event-driven Coordination**: Asynchronous, decoupled communication
5. **Resource Management**: Efficient use of API calls and memory
6. **Error Recovery**: Resilient to failures
7. **Observability**: Monitoring and debugging at scale

## Section 1: Designing Agent Architectures

Let's start by designing a scalable agent architecture. We'll create a system that manages different types of agents for an enterprise application.

### The Agent Registry Pattern

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem;

use Pagent\Agent;
use Pagent\AgentBuilder;

final class AgentRegistry
{
    private array $agents = [];
    private array $capabilities = [];
    private array $metadata = [];

    public function register(
        string $name,
        callable $factory,
        array $capabilities = [],
        array $metadata = []
    ): void {
        $this->agents[$name] = $factory;
        $this->capabilities[$name] = $capabilities;
        $this->metadata[$name] = $metadata;
    }

    public function create(string $name, array $config = []): Agent
    {
        if (! isset($this->agents[$name])) {
            throw new \InvalidArgumentException("Agent '{$name}' not registered");
        }

        $factory = $this->agents[$name];
        $agent = $factory($config);

        if (! $agent instanceof Agent) {
            throw new \RuntimeException("Factory must return Agent instance");
        }

        return $agent;
    }

    public function findByCapability(string $capability): array
    {
        $matches = [];

        foreach ($this->capabilities as $name => $caps) {
            if (in_array($capability, $caps, true)) {
                $matches[] = $name;
            }
        }

        return $matches;
    }

    public function getMetadata(string $name): array
    {
        return $this->metadata[$name] ?? [];
    }
}
```

### Agent Factory Pattern

Now let's create factories for different agent types:

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem\Factories;

use Pagent\Agent;
use Pagent\Tool;

final class AgentFactory
{
    public static function createAnalyst(array $config = []): Agent
    {
        return agent()
            ->withProvider($config['provider'] ?? 'anthropic')
            ->withModel($config['model'] ?? 'claude-3-5-sonnet-20241022')
            ->withSystemPrompt('You are a data analyst expert...')
            ->withTools([
                Tool::create('analyze_data', 'Analyze dataset patterns')
                    ->withParameters(['dataset', 'metrics'])
                    ->using(fn($args) => DataAnalyzer::analyze($args)),
                Tool::create('generate_report', 'Generate analysis report')
                    ->withParameters(['analysis', 'format'])
                    ->using(fn($args) => ReportGenerator::generate($args)),
            ])
            ->withMaxTokens($config['max_tokens'] ?? 4000)
            ->make();
    }

    public static function createOrchestrator(array $config = []): Agent
    {
        return agent()
            ->withProvider($config['provider'] ?? 'openai')
            ->withModel($config['model'] ?? 'gpt-4-turbo-preview')
            ->withSystemPrompt('You orchestrate work between multiple agents...')
            ->withTools([
                Tool::create('delegate_task', 'Delegate to specialist agent')
                    ->withParameters(['agent_type', 'task', 'context'])
                    ->using(fn($args) => self::delegateTask($args)),
                Tool::create('aggregate_results', 'Combine agent outputs')
                    ->withParameters(['results', 'format'])
                    ->using(fn($args) => self::aggregateResults($args)),
            ])
            ->make();
    }

    public static function createValidator(array $config = []): Agent
    {
        return agent()
            ->withProvider($config['provider'] ?? 'anthropic')
            ->withModel($config['model'] ?? 'claude-3-haiku-20240307')
            ->withSystemPrompt('You validate and verify outputs...')
            ->withMaxTokens($config['max_tokens'] ?? 2000)
            ->make();
    }

    private static function delegateTask(array $args): array
    {
        // Implementation for task delegation
        return ['status' => 'delegated', 'agent' => $args['agent_type']];
    }

    private static function aggregateResults(array $args): array
    {
        // Implementation for result aggregation
        return ['aggregated' => true, 'count' => count($args['results'])];
    }
}
```

### Registering Agents

```php
// Bootstrap the agent system
$registry = new AgentRegistry();

// Register different agent types
$registry->register(
    'analyst',
    [AgentFactory::class, 'createAnalyst'],
    ['data_analysis', 'reporting', 'visualization'],
    ['tier' => 'specialist', 'cost' => 'high']
);

$registry->register(
    'orchestrator',
    [AgentFactory::class, 'createOrchestrator'],
    ['coordination', 'delegation', 'aggregation'],
    ['tier' => 'controller', 'cost' => 'medium']
);

$registry->register(
    'validator',
    [AgentFactory::class, 'createValidator'],
    ['validation', 'verification', 'quality_check'],
    ['tier' => 'support', 'cost' => 'low']
);

// Find agents by capability
$dataAgents = $registry->findByCapability('data_analysis');
// Returns: ['analyst']

// Create agent instance
$analyst = $registry->create('analyst', ['max_tokens' => 8000]);
```

## Section 2: Event-Driven Patterns

Event-driven architecture allows agents to communicate asynchronously and react to system events.

### Event System Implementation

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem\Events;

final class EventBus
{
    private array $listeners = [];
    private array $history = [];
    private bool $recording = false;

    public function subscribe(string $event, callable $listener): string
    {
        $id = uniqid('listener_', true);
        $this->listeners[$event][] = [
            'id' => $id,
            'callback' => $listener,
        ];

        return $id;
    }

    public function unsubscribe(string $event, string $id): void
    {
        if (! isset($this->listeners[$event])) {
            return;
        }

        $this->listeners[$event] = array_filter(
            $this->listeners[$event],
            fn($listener) => $listener['id'] !== $id
        );
    }

    public function emit(string $event, array $data = []): void
    {
        $eventData = [
            'event' => $event,
            'data' => $data,
            'timestamp' => microtime(true),
        ];

        if ($this->recording) {
            $this->history[] = $eventData;
        }

        if (! isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            try {
                $listener['callback']($eventData);
            } catch (\Throwable $e) {
                // Log error but don't stop other listeners
                error_log("Event listener error: {$e->getMessage()}");
            }
        }
    }

    public function startRecording(): void
    {
        $this->recording = true;
        $this->history = [];
    }

    public function stopRecording(): array
    {
        $this->recording = false;
        return $this->history;
    }
}
```

### Agent Event Orchestrator

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem;

use App\AgentSystem\Events\EventBus;
use Pagent\Agent;

final class AgentOrchestrator
{
    private EventBus $eventBus;
    private AgentRegistry $registry;
    private array $runningTasks = [];

    public function __construct(EventBus $eventBus, AgentRegistry $registry)
    {
        $this->eventBus = $eventBus;
        $this->registry = $registry;

        $this->setupEventHandlers();
    }

    private function setupEventHandlers(): void
    {
        // Handle task requests
        $this->eventBus->subscribe('task.requested', function($event) {
            $this->handleTaskRequest($event['data']);
        });

        // Handle task completion
        $this->eventBus->subscribe('task.completed', function($event) {
            $this->handleTaskCompletion($event['data']);
        });

        // Handle errors
        $this->eventBus->subscribe('task.failed', function($event) {
            $this->handleTaskFailure($event['data']);
        });
    }

    public function executeWorkflow(array $workflow): array
    {
        $workflowId = uniqid('workflow_', true);
        $results = [];

        $this->eventBus->emit('workflow.started', [
            'id' => $workflowId,
            'steps' => count($workflow),
        ]);

        foreach ($workflow as $step) {
            $taskId = $this->dispatchTask($step, $workflowId);
            $results[$step['name']] = $this->waitForTask($taskId);
        }

        $this->eventBus->emit('workflow.completed', [
            'id' => $workflowId,
            'results' => $results,
        ]);

        return $results;
    }

    private function dispatchTask(array $task, string $workflowId): string
    {
        $taskId = uniqid('task_', true);

        $this->runningTasks[$taskId] = [
            'status' => 'pending',
            'task' => $task,
            'workflow' => $workflowId,
        ];

        $this->eventBus->emit('task.dispatched', [
            'id' => $taskId,
            'type' => $task['agent'],
            'workflow' => $workflowId,
        ]);

        // Execute task asynchronously
        $this->executeTask($taskId, $task);

        return $taskId;
    }

    private function executeTask(string $taskId, array $task): void
    {
        try {
            $agent = $this->registry->create($task['agent'], $task['config'] ?? []);

            $this->runningTasks[$taskId]['status'] = 'running';

            $result = $agent->ask($task['prompt']);

            $this->runningTasks[$taskId]['status'] = 'completed';
            $this->runningTasks[$taskId]['result'] = $result;

            $this->eventBus->emit('task.completed', [
                'id' => $taskId,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->runningTasks[$taskId]['status'] = 'failed';
            $this->runningTasks[$taskId]['error'] = $e->getMessage();

            $this->eventBus->emit('task.failed', [
                'id' => $taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function waitForTask(string $taskId): mixed
    {
        while ($this->runningTasks[$taskId]['status'] === 'pending' ||
               $this->runningTasks[$taskId]['status'] === 'running') {
            usleep(100000); // 100ms
        }

        if ($this->runningTasks[$taskId]['status'] === 'failed') {
            throw new \RuntimeException($this->runningTasks[$taskId]['error']);
        }

        return $this->runningTasks[$taskId]['result'];
    }

    private function handleTaskRequest(array $data): void
    {
        // Find suitable agent for the task
        $agents = $this->registry->findByCapability($data['capability']);

        if (empty($agents)) {
            $this->eventBus->emit('task.rejected', [
                'reason' => 'No agent with required capability',
                'capability' => $data['capability'],
            ]);
            return;
        }

        // Dispatch to first available agent
        $this->dispatchTask([
            'agent' => $agents[0],
            'prompt' => $data['prompt'],
            'config' => $data['config'] ?? [],
        ], $data['workflow_id'] ?? 'standalone');
    }

    private function handleTaskCompletion(array $data): void
    {
        // Log completion
        error_log("Task {$data['id']} completed");

        // Trigger dependent tasks if any
        $this->eventBus->emit('task.dependencies.check', [
            'completed_task' => $data['id'],
        ]);
    }

    private function handleTaskFailure(array $data): void
    {
        // Implement retry logic
        $task = $this->runningTasks[$data['id']]['task'] ?? null;

        if ($task && ($task['retries'] ?? 0) < 3) {
            $task['retries'] = ($task['retries'] ?? 0) + 1;

            $this->eventBus->emit('task.retry', [
                'original_id' => $data['id'],
                'attempt' => $task['retries'],
            ]);

            $this->dispatchTask($task, $task['workflow_id'] ?? 'standalone');
        }
    }
}
```

## Section 3: Plugin Architecture

Let's create a plugin system that allows extending agent capabilities dynamically.

### Plugin Interface

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem\Plugins;

use Pagent\Agent;

interface PluginInterface
{
    public function getName(): string;
    public function getVersion(): string;
    public function getDependencies(): array;
    public function initialize(array $config = []): void;
    public function extend(Agent $agent): Agent;
    public function getProvidedCapabilities(): array;
}
```

### Plugin Manager

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem\Plugins;

final class PluginManager
{
    private array $plugins = [];
    private array $loaded = [];
    private array $capabilities = [];

    public function register(PluginInterface $plugin): void
    {
        $name = $plugin->getName();

        if (isset($this->plugins[$name])) {
            throw new \RuntimeException("Plugin '{$name}' already registered");
        }

        $this->plugins[$name] = $plugin;

        // Track capabilities
        foreach ($plugin->getProvidedCapabilities() as $capability) {
            $this->capabilities[$capability][] = $name;
        }
    }

    public function load(string $name, array $config = []): void
    {
        if (isset($this->loaded[$name])) {
            return; // Already loaded
        }

        if (! isset($this->plugins[$name])) {
            throw new \InvalidArgumentException("Plugin '{$name}' not found");
        }

        $plugin = $this->plugins[$name];

        // Load dependencies first
        foreach ($plugin->getDependencies() as $dependency) {
            $this->load($dependency);
        }

        // Initialize plugin
        $plugin->initialize($config);
        $this->loaded[$name] = true;
    }

    public function extend(Agent $agent, array $plugins): Agent
    {
        foreach ($plugins as $pluginName) {
            if (! isset($this->plugins[$pluginName])) {
                throw new \InvalidArgumentException("Plugin '{$pluginName}' not found");
            }

            if (! isset($this->loaded[$pluginName])) {
                $this->load($pluginName);
            }

            $agent = $this->plugins[$pluginName]->extend($agent);
        }

        return $agent;
    }

    public function findByCapability(string $capability): array
    {
        return $this->capabilities[$capability] ?? [];
    }

    public function getAllPlugins(): array
    {
        return array_keys($this->plugins);
    }

    public function isLoaded(string $name): bool
    {
        return isset($this->loaded[$name]);
    }
}
```

### Example Plugin: Code Analysis

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem\Plugins;

use Pagent\Agent;
use Pagent\Tool;

final class CodeAnalysisPlugin implements PluginInterface
{
    private array $config = [];

    public function getName(): string
    {
        return 'code_analysis';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return []; // No dependencies
    }

    public function initialize(array $config = []): void
    {
        $this->config = array_merge([
            'languages' => ['php', 'javascript', 'python'],
            'max_file_size' => 1048576, // 1MB
        ], $config);
    }

    public function extend(Agent $agent): Agent
    {
        return $agent
            ->withTools([
                Tool::create('analyze_code', 'Analyze code quality and patterns')
                    ->withParameters(['code', 'language'])
                    ->using([$this, 'analyzeCode']),

                Tool::create('suggest_refactoring', 'Suggest code improvements')
                    ->withParameters(['code', 'language'])
                    ->using([$this, 'suggestRefactoring']),

                Tool::create('detect_patterns', 'Detect design patterns')
                    ->withParameters(['code'])
                    ->using([$this, 'detectPatterns']),
            ])
            ->withContext([
                'plugin' => $this->getName(),
                'capabilities' => $this->getProvidedCapabilities(),
            ]);
    }

    public function getProvidedCapabilities(): array
    {
        return [
            'code_analysis',
            'refactoring',
            'pattern_detection',
        ];
    }

    public function analyzeCode(array $args): array
    {
        $code = $args['code'];
        $language = $args['language'] ?? 'unknown';

        // Perform analysis
        $metrics = [
            'lines' => substr_count($code, "\n") + 1,
            'complexity' => $this->calculateComplexity($code),
            'language' => $language,
        ];

        return [
            'status' => 'analyzed',
            'metrics' => $metrics,
            'issues' => $this->findIssues($code),
        ];
    }

    public function suggestRefactoring(array $args): array
    {
        // Implementation for refactoring suggestions
        return [
            'suggestions' => [
                'Extract method for lines 10-25',
                'Consider using dependency injection',
                'Add type declarations',
            ],
        ];
    }

    public function detectPatterns(array $args): array
    {
        // Implementation for pattern detection
        return [
            'patterns' => [
                'Factory' => 2,
                'Singleton' => 1,
                'Observer' => 3,
            ],
        ];
    }

    private function calculateComplexity(string $code): int
    {
        // Simplified complexity calculation
        $complexity = 1;
        $complexity += substr_count($code, 'if');
        $complexity += substr_count($code, 'for');
        $complexity += substr_count($code, 'while');
        $complexity += substr_count($code, 'switch');

        return $complexity;
    }

    private function findIssues(string $code): array
    {
        $issues = [];

        // Check for common issues
        if (strpos($code, 'eval(') !== false) {
            $issues[] = 'Use of eval() detected';
        }

        if (strpos($code, 'var_dump(') !== false) {
            $issues[] = 'Debug statement found';
        }

        return $issues;
    }
}
```

### Using Plugins with Agents

```php
// Initialize plugin manager
$pluginManager = new PluginManager();

// Register plugins
$pluginManager->register(new CodeAnalysisPlugin());
$pluginManager->register(new DatabasePlugin());
$pluginManager->register(new ValidationPlugin());

// Load plugin with configuration
$pluginManager->load('code_analysis', [
    'languages' => ['php', 'typescript'],
]);

// Create agent with plugins
$agent = agent()
    ->withProvider('anthropic')
    ->withModel('claude-3-5-sonnet-20241022')
    ->make();

// Extend agent with plugins
$enhancedAgent = $pluginManager->extend($agent, ['code_analysis']);

// Use enhanced agent
$result = $enhancedAgent->ask('Analyze this PHP code and suggest improvements');
```

## Section 4: Agent Marketplace

Let's build a simple agent marketplace where users can share and discover agents.

### Marketplace Repository

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem\Marketplace;

final class MarketplaceRepository
{
    private array $agents = [];
    private array $ratings = [];
    private array $downloads = [];

    public function publish(array $agentDefinition): string
    {
        $id = uniqid('agent_', true);

        $this->agents[$id] = array_merge($agentDefinition, [
            'id' => $id,
            'published_at' => time(),
            'updated_at' => time(),
            'version' => $agentDefinition['version'] ?? '1.0.0',
            'downloads' => 0,
            'rating' => 0.0,
            'ratings_count' => 0,
        ]);

        return $id;
    }

    public function search(array $criteria = []): array
    {
        $results = $this->agents;

        // Filter by capability
        if (isset($criteria['capability'])) {
            $results = array_filter($results, function($agent) use ($criteria) {
                return in_array($criteria['capability'], $agent['capabilities'] ?? [], true);
            });
        }

        // Filter by provider
        if (isset($criteria['provider'])) {
            $results = array_filter($results, function($agent) use ($criteria) {
                return $agent['provider'] === $criteria['provider'];
            });
        }

        // Sort by popularity
        if ($criteria['sort'] ?? null === 'popular') {
            uasort($results, function($a, $b) {
                return $b['downloads'] <=> $a['downloads'];
            });
        }

        // Sort by rating
        if ($criteria['sort'] ?? null === 'rating') {
            uasort($results, function($a, $b) {
                return $b['rating'] <=> $a['rating'];
            });
        }

        return array_values($results);
    }

    public function download(string $id): array
    {
        if (! isset($this->agents[$id])) {
            throw new \InvalidArgumentException("Agent '{$id}' not found");
        }

        // Increment download counter
        $this->agents[$id]['downloads']++;
        $this->downloads[$id][] = time();

        return $this->agents[$id];
    }

    public function rate(string $id, int $rating): void
    {
        if (! isset($this->agents[$id])) {
            throw new \InvalidArgumentException("Agent '{$id}' not found");
        }

        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException("Rating must be between 1 and 5");
        }

        $this->ratings[$id][] = $rating;

        // Update average rating
        $ratings = $this->ratings[$id];
        $this->agents[$id]['rating'] = array_sum($ratings) / count($ratings);
        $this->agents[$id]['ratings_count'] = count($ratings);
    }

    public function getStats(string $id): array
    {
        if (! isset($this->agents[$id])) {
            throw new \InvalidArgumentException("Agent '{$id}' not found");
        }

        return [
            'downloads' => $this->agents[$id]['downloads'],
            'rating' => $this->agents[$id]['rating'],
            'ratings_count' => $this->agents[$id]['ratings_count'],
            'last_download' => end($this->downloads[$id] ?? []) ?: null,
        ];
    }
}
```

### Marketplace Client

```php
<?php

declare(strict_types=1);

namespace App\AgentSystem\Marketplace;

use App\AgentSystem\AgentRegistry;
use Pagent\Agent;

final class MarketplaceClient
{
    private MarketplaceRepository $repository;
    private AgentRegistry $registry;

    public function __construct(
        MarketplaceRepository $repository,
        AgentRegistry $registry
    ) {
        $this->repository = $repository;
        $this->registry = $registry;
    }

    public function installAgent(string $id): void
    {
        $definition = $this->repository->download($id);

        // Register agent in local registry
        $this->registry->register(
            $definition['name'],
            $this->createFactory($definition),
            $definition['capabilities'] ?? [],
            $definition['metadata'] ?? []
        );
    }

    private function createFactory(array $definition): callable
    {
        return function(array $config = []) use ($definition): Agent {
            return agent()
                ->withProvider($definition['provider'])
                ->withModel($definition['model'])
                ->withSystemPrompt($definition['system_prompt'])
                ->withTools($this->buildTools($definition['tools'] ?? []))
                ->withMaxTokens($config['max_tokens'] ?? $definition['max_tokens'] ?? 4000)
                ->make();
        };
    }

    private function buildTools(array $toolDefinitions): array
    {
        $tools = [];

        foreach ($toolDefinitions as $toolDef) {
            $tool = Tool::create($toolDef['name'], $toolDef['description']);

            if (isset($toolDef['parameters'])) {
                $tool->withParameters($toolDef['parameters']);
            }

            // Tools from marketplace use safe sandboxed execution
            $tool->using(function($args) use ($toolDef) {
                return $this->executeSandboxed($toolDef['code'], $args);
            });

            $tools[] = $tool;
        }

        return $tools;
    }

    private function executeSandboxed(string $code, array $args): mixed
    {
        // In production, this would run in a secure sandbox
        // For now, we'll just evaluate the code (NOT SAFE FOR PRODUCTION)
        return eval($code);
    }
}
```

## Putting It All Together

Let's create a complete enterprise system using all these components:

```php
// Initialize core components
$eventBus = new EventBus();
$registry = new AgentRegistry();
$pluginManager = new PluginManager();
$marketplace = new MarketplaceRepository();

// Setup orchestrator
$orchestrator = new AgentOrchestrator($eventBus, $registry);

// Register base agents
$registry->register('analyst', [AgentFactory::class, 'createAnalyst']);
$registry->register('validator', [AgentFactory::class, 'createValidator']);

// Install agents from marketplace
$marketplaceClient = new MarketplaceClient($marketplace, $registry);

// Publish an agent to marketplace
$agentId = $marketplace->publish([
    'name' => 'sentiment_analyzer',
    'provider' => 'openai',
    'model' => 'gpt-4',
    'system_prompt' => 'You are a sentiment analysis expert...',
    'capabilities' => ['sentiment_analysis', 'emotion_detection'],
    'tools' => [],
]);

// Search and install agents
$sentimentAgents = $marketplace->search(['capability' => 'sentiment_analysis']);
$marketplaceClient->installAgent($sentimentAgents[0]['id']);

// Register and load plugins
$pluginManager->register(new CodeAnalysisPlugin());
$pluginManager->load('code_analysis');

// Create workflow
$workflow = [
    [
        'name' => 'analyze',
        'agent' => 'analyst',
        'prompt' => 'Analyze the quarterly sales data',
    ],
    [
        'name' => 'validate',
        'agent' => 'validator',
        'prompt' => 'Validate the analysis results',
    ],
    [
        'name' => 'sentiment',
        'agent' => 'sentiment_analyzer',
        'prompt' => 'Analyze customer feedback sentiment',
    ],
];

// Execute workflow with event monitoring
$eventBus->startRecording();
$results = $orchestrator->executeWorkflow($workflow);
$events = $eventBus->stopRecording();

// Process results
foreach ($results as $step => $result) {
    echo "Step {$step}: " . $result . "\n";
}

// Review events
foreach ($events as $event) {
    echo "Event: {$event['event']} at " . date('H:i:s', (int)$event['timestamp']) . "\n";
}
```

## Troubleshooting Complex Systems

### Common Issues and Solutions

1. **Agent Communication Failures**
   - Implement circuit breakers for failing agents
   - Add retry logic with exponential backoff
   - Use event bus for async communication

2. **Plugin Conflicts**
   - Version management for plugins
   - Dependency resolution
   - Isolated plugin contexts

3. **Performance Bottlenecks**
   - Cache agent responses
   - Implement request batching
   - Use lighter models for simple tasks

4. **Debugging Complex Workflows**
   - Comprehensive event logging
   - Workflow visualization tools
   - Step-by-step execution mode

## Summary

You've learned how to build complex agent systems with:
- Scalable agent architectures using registries and factories
- Event-driven patterns for decoupled communication
- Plugin systems for extensible capabilities
- Marketplace functionality for agent sharing
- Enterprise-grade orchestration and workflow management

These patterns enable you to build production-ready AI systems that can scale with your organization's needs.

## Next Steps

In the next chapter, we'll explore testing strategies for complex agent systems, including:
- Unit testing agent behaviors
- Integration testing workflows
- Mocking LLM responses
- Performance testing at scale

Continue to Part 29: Testing Agent Systems →