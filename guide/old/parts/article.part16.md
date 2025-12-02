# Chapter 16: Multi-Agent Fundamentals

## What You'll Learn

In this chapter, you'll master multi-agent orchestration in Pagent, enabling sophisticated workflows where multiple specialized agents collaborate to solve complex problems. By the end, you'll be able to:

- Create and manage agent hierarchies with clear responsibilities
- Implement communication protocols between agents
- Build pipelines for sequential processing
- Handle agent handoffs and delegation patterns
- Share context and memory across agent teams
- Manage agent lifecycles and error recovery

## Prerequisites

Before starting this chapter, you should have completed:

- Chapter 1: Getting Started
- Chapter 2: Basic Agent Creation
- Chapter 3: Provider Configuration
- Chapter 4: Message Management
- Chapter 5: Tool Functions
- Chapter 6: System Prompts
- Chapter 7: Middleware
- Chapter 8: State Management
- Chapter 9: Memory Systems

**Time Estimate:** 60-75 minutes

**Final Result:** A complete multi-agent research system with specialized agents working together

## Understanding Multi-Agent Systems

Multi-agent systems represent a paradigm shift from single, monolithic AI assistants to teams of specialized agents that collaborate to achieve complex goals. Think of it like a well-organized company where different departments handle specific responsibilities, communicating and coordinating to deliver results.

In Pagent, multi-agent orchestration enables you to:

- Divide complex tasks into manageable pieces
- Create specialized agents with focused expertise
- Build fault-tolerant systems with fallback strategies
- Scale processing through parallel execution
- Maintain separation of concerns

## Creating Agent Hierarchies

Let's start by creating a simple manager-worker hierarchy:

```php
use function Pagent\agent;

// Create a project manager agent
agent('project-manager')
    ->provider('openai')
    ->system('You are a project manager who delegates tasks to specialists.
             Break down complex requests into subtasks and coordinate the team.');

// Create specialized worker agents
agent('researcher')
    ->provider('openai')
    ->system('You are a research specialist. Find and summarize information on topics.');

agent('writer')
    ->provider('openai')
    ->system('You are a technical writer. Create clear, structured documentation.');

agent('reviewer')
    ->provider('openai')
    ->system('You are a quality reviewer. Check work for accuracy and completeness.');
```

These agents now exist in the registry, ready to collaborate. Each has a specific role and expertise area, similar to departments in an organization.

### Understanding Agent Relationships

Agents can interact through several relationship patterns:

1. **Hierarchical**: Manager agents delegate to workers
2. **Sequential**: Pipeline agents process in order
3. **Parallel**: Multiple agents work simultaneously
4. **Collaborative**: Agents share information bidirectionally

## Implementing the Manager-Worker Pattern

The manager-worker pattern is fundamental for task delegation:

```php
$manager = agent('project-manager');

// Manager delegates a complex task
$result = $manager->delegate('Create a technical guide about PHP arrays')
    ->to('researcher')  // First, research the topic
    ->to('writer')      // Then, write the guide
    ->to('reviewer')    // Finally, review the output
    ->supervise(function ($output, $task): bool {
        // Manager validates the work
        echo "[Manager] Reviewing output...\n";
        return strlen($output) > 100 && str_contains($output, 'PHP');
    })
    ->onComplete(function ($result): void {
        echo "[Manager] Task completed successfully!\n";
    })
    ->execute();

echo "Task: {$result->task}\n";
echo "Final Worker: {$result->worker}\n";
echo "Output: {$result->worker_output}\n";
echo "Manager Review: {$result->manager_review}\n";
```

The delegation flow works as follows:

1. Manager receives the main task
2. Work passes to each agent in sequence
3. Each agent builds on the previous output
4. Manager supervises and validates the final result

### Advanced Delegation Features

You can enhance delegation with additional controls:

```php
$result = $manager->delegate('Complex analysis task')
    ->to('analyst')
    ->withContext([
        'priority' => 'high',
        'deadline' => '2024-12-01',
        'format' => 'markdown'
    ])
    ->maxAttempts(3)  // Retry up to 3 times on failure
    ->timeout(30)     // 30 second timeout
    ->onError(function ($error, $worker): void {
        echo "[Error] Worker {$worker} failed: {$error->getMessage()}\n";
    })
    ->execute();
```

## Building Agent Pipelines

Pipelines enable sequential processing where each agent transforms the output for the next:

```php
use function Pagent\pipeline;

// Create a document processing pipeline
$result = pipeline('document-processor')
    ->agent('extractor')     // Extract key information
    ->agent('validator')     // Validate and correct data
    ->agent('formatter')     // Format the final output
    ->run($rawDocument);

echo "Pipeline result: {$result}\n";
```

### Pipeline Transformations

You can transform data between pipeline stages:

```php
$result = pipeline('sentiment-analyzer')
    ->agent('text-cleaner')
    ->agent('sentiment-detector', function ($cleanedText) {
        // Transform output for next agent
        return "Analyze sentiment of: {$cleanedText}";
    })
    ->agent('report-generator', function ($sentiment) {
        return [
            'instruction' => 'Generate report',
            'sentiment' => $sentiment,
            'format' => 'executive_summary'
        ];
    })
    ->run($userReview);
```

Each transformation function receives the previous agent's output and prepares input for the next agent, enabling complex data flows.

### Pipeline Error Recovery

Robust pipelines handle failures gracefully:

```php
$pipeline = pipeline('resilient-processor')
    ->agent('parser')
    ->agent('analyzer')
    ->agent('reporter')
    ->onError(function ($error, $stage, $agentName) {
        // Custom error handling
        error_log("Pipeline failed at stage {$stage} ({$agentName})");

        // Return fallback result
        return "Pipeline interrupted - partial results available";
    })
    ->run($input);
```

## Agent Communication Protocols

Effective multi-agent systems require clear communication protocols. Let's implement a collaborative research team:

```php
// Define communication protocol through shared context
class ResearchContext {
    public array $sources = [];
    public array $findings = [];
    public string $topic;
    public string $status = 'initializing';

    public function addSource(string $source): void {
        $this->sources[] = $source;
    }

    public function addFinding(string $finding): void {
        $this->findings[] = $finding;
    }
}

// Create research team with shared context
$context = new ResearchContext();
$context->topic = 'Quantum Computing Basics';

agent('lead-researcher')
    ->provider('openai')
    ->system('You lead research projects. Coordinate the team and synthesize findings.')
    ->withContext($context);

agent('data-collector')
    ->provider('openai')
    ->system('You collect and verify data sources.')
    ->withContext($context);

agent('analyst')
    ->provider('openai')
    ->system('You analyze collected data and identify patterns.')
    ->withContext($context);
```

### Implementing Message Passing

Agents can communicate through structured messages:

```php
class AgentMessage {
    public function __construct(
        public string $from,
        public string $to,
        public string $type,
        public mixed $content,
        public array $metadata = []
    ) {}
}

class MessageBus {
    private array $messages = [];
    private array $subscribers = [];

    public function publish(AgentMessage $message): void {
        $this->messages[] = $message;

        // Notify subscribers
        foreach ($this->subscribers[$message->to] ?? [] as $callback) {
            $callback($message);
        }
    }

    public function subscribe(string $agentName, callable $callback): void {
        $this->subscribers[$agentName][] = $callback;
    }
}

// Usage
$bus = new MessageBus();

$bus->subscribe('analyst', function (AgentMessage $msg) {
    echo "Analyst received: {$msg->type} from {$msg->from}\n";
    // Process message
});

// Agent sends message
$bus->publish(new AgentMessage(
    from: 'data-collector',
    to: 'analyst',
    type: 'data_ready',
    content: ['sources' => 10, 'records' => 1000]
));
```

## Managing Shared Context

Shared context enables agents to maintain common understanding:

```php
use Pagent\Memory\ContextManager;

class SharedAgentMemory {
    private ContextManager $contextManager;
    private array $sharedFacts = [];
    private array $agentContributions = [];

    public function __construct() {
        $this->contextManager = new ContextManager(
            maxTokens: 4000,
            strategy: 'sliding'
        );
    }

    public function addFact(string $agentName, string $fact): void {
        $this->sharedFacts[] = [
            'agent' => $agentName,
            'fact' => $fact,
            'timestamp' => time()
        ];

        $this->agentContributions[$agentName][] = $fact;
    }

    public function getRecentFacts(int $limit = 10): array {
        return array_slice($this->sharedFacts, -$limit);
    }

    public function getAgentContributions(string $agentName): array {
        return $this->agentContributions[$agentName] ?? [];
    }

    public function pruneContext(array $messages): array {
        return $this->contextManager->prune($messages);
    }
}

// Create agents with shared memory
$sharedMemory = new SharedAgentMemory();

agent('researcher-1')
    ->provider('openai')
    ->use(function ($response) use ($sharedMemory) {
        // Store findings in shared memory
        $sharedMemory->addFact('researcher-1', $response->content);
        return $response;
    });
```

## Handling Agent Handoffs

Agent handoffs enable smooth transitions between specialists:

```php
// Customer support system with escalation
agent('level-1-support')
    ->provider('openai')
    ->system('You are first-line support. Handle basic queries.
             For complex issues, indicate need for escalation.');

agent('level-2-support')
    ->provider('openai')
    ->system('You are advanced support. Handle technical issues.');

agent('specialist')
    ->provider('openai')
    ->system('You are a product specialist. Handle specific product queries.');

// Implement handoff logic
$support = agent('level-1-support');
$response = $support->prompt('My API integration is failing with error 403');

if (str_contains($response->content, 'escalate') ||
    str_contains($response->content, 'technical')) {

    echo "Escalating to Level 2 Support...\n";
    $level2 = $support->handoff('level-2-support',
        'Customer has technical API issue - error 403');

    $solution = $level2->prompt('Please help with the 403 error');
    echo "Level 2 Response: {$solution->content}\n";
}
```

### Implementing Handoff Context Preservation

When handing off between agents, preserve conversation context:

```php
class HandoffManager {
    private array $handoffHistory = [];

    public function transferWithContext(
        Agent $from,
        string $to,
        string $reason
    ): Agent {
        // Capture current context
        $context = [
            'from' => $from->getName(),
            'to' => $to,
            'reason' => $reason,
            'conversation' => $from->getMessages(),
            'timestamp' => now()
        ];

        $this->handoffHistory[] = $context;

        // Transfer to new agent with context
        $targetAgent = agent($to);

        // Inject conversation history
        foreach ($context['conversation'] as $message) {
            if ($message['role'] === 'user') {
                $targetAgent->addMessage($message['role'], $message['content']);
            }
        }

        // Add handoff context
        $targetAgent->addMessage('system',
            "Handoff from {$from->getName()}. Reason: {$reason}");

        return $targetAgent;
    }
}
```

## Multi-Stage Processing Pipeline

Let's build a complete multi-stage document processing system:

```php
class DocumentProcessor {
    private array $stages = [];
    private array $results = [];

    public function addStage(string $name, callable $processor): self {
        $this->stages[$name] = $processor;
        return $this;
    }

    public function process(string $document): array {
        $current = $document;

        foreach ($this->stages as $stage => $processor) {
            echo "Processing stage: {$stage}\n";

            try {
                $current = $processor($current);
                $this->results[$stage] = [
                    'success' => true,
                    'output' => $current
                ];
            } catch (\Exception $e) {
                $this->results[$stage] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];

                // Continue with last successful output
                if (isset($this->results[array_key_last($this->results)]['output'])) {
                    $current = $this->results[array_key_last($this->results)]['output'];
                }
            }
        }

        return $this->results;
    }
}

// Configure document processor with agents
$processor = new DocumentProcessor();

$processor
    ->addStage('extraction', function ($doc) {
        return agent('extractor')->prompt($doc)->content;
    })
    ->addStage('validation', function ($data) {
        return agent('validator')->prompt($data)->content;
    })
    ->addStage('enrichment', function ($data) {
        return agent('enricher')->prompt($data)->content;
    })
    ->addStage('formatting', function ($data) {
        return agent('formatter')->prompt($data)->content;
    });

$results = $processor->process($rawDocument);
```

## Distributed Task Processing

For parallel processing, implement a task distributor:

```php
class TaskDistributor {
    private array $workers = [];
    private array $taskQueue = [];
    private array $results = [];

    public function addWorker(string $agentName): self {
        $this->workers[] = $agentName;
        return $this;
    }

    public function addTask(string $id, mixed $task): self {
        $this->taskQueue[$id] = $task;
        return $this;
    }

    public function distribute(): array {
        $workerCount = count($this->workers);
        $taskChunks = array_chunk($this->taskQueue,
            ceil(count($this->taskQueue) / $workerCount), true);

        foreach ($taskChunks as $index => $chunk) {
            if (isset($this->workers[$index])) {
                $worker = agent($this->workers[$index]);

                foreach ($chunk as $taskId => $task) {
                    $result = $worker->prompt($task)->content;
                    $this->results[$taskId] = [
                        'worker' => $this->workers[$index],
                        'result' => $result
                    ];
                }
            }
        }

        return $this->results;
    }
}

// Usage
$distributor = new TaskDistributor();
$distributor
    ->addWorker('analyzer-1')
    ->addWorker('analyzer-2')
    ->addWorker('analyzer-3')
    ->addTask('task-1', 'Analyze market trends')
    ->addTask('task-2', 'Review competitor data')
    ->addTask('task-3', 'Evaluate customer feedback');

$results = $distributor->distribute();
```

## Agent Lifecycle Management

Proper lifecycle management ensures efficient resource usage:

```php
class AgentLifecycleManager {
    private array $activeAgents = [];
    private array $idleAgents = [];
    private array $metrics = [];

    public function spawn(string $agentName, array $config = []): Agent {
        if (isset($this->idleAgents[$agentName])) {
            // Reuse idle agent
            $agent = $this->idleAgents[$agentName];
            unset($this->idleAgents[$agentName]);
        } else {
            // Create new agent
            $agent = agent($agentName);

            if (isset($config['provider'])) {
                $agent->provider($config['provider']);
            }
            if (isset($config['system'])) {
                $agent->system($config['system']);
            }
        }

        $this->activeAgents[$agentName] = $agent;
        $this->metrics[$agentName]['spawned'] = time();

        return $agent;
    }

    public function release(string $agentName): void {
        if (isset($this->activeAgents[$agentName])) {
            $agent = $this->activeAgents[$agentName];

            // Clear conversation but keep configuration
            $agent->forget();

            $this->idleAgents[$agentName] = $agent;
            unset($this->activeAgents[$agentName]);

            $this->metrics[$agentName]['released'] = time();
        }
    }

    public function terminate(string $agentName): void {
        unset($this->activeAgents[$agentName]);
        unset($this->idleAgents[$agentName]);
        $this->metrics[$agentName]['terminated'] = time();
    }

    public function getMetrics(): array {
        return $this->metrics;
    }
}
```

## Practical Example: Collaborative Research Team

Let's build a complete multi-agent research system:

```php
// Initialize research team
function createResearchTeam(): array {
    // Team coordinator
    agent('coordinator')
        ->provider('openai')
        ->system('You coordinate research teams. Break down topics into research questions.');

    // Subject matter experts
    agent('historian')
        ->provider('openai')
        ->system('You are a historian. Provide historical context and evolution of topics.');

    agent('scientist')
        ->provider('openai')
        ->system('You are a scientist. Explain technical and scientific aspects.');

    agent('analyst')
        ->provider('openai')
        ->system('You are a data analyst. Provide statistics and trends.');

    // Content creators
    agent('synthesizer')
        ->provider('openai')
        ->system('You synthesize information from multiple sources into coherent summaries.');

    agent('editor')
        ->provider('openai')
        ->system('You edit and polish content for clarity and accuracy.');

    return [
        'coordinator', 'historian', 'scientist',
        'analyst', 'synthesizer', 'editor'
    ];
}

// Research workflow
class ResearchWorkflow {
    private array $team;
    private array $research = [];

    public function __construct() {
        $this->team = createResearchTeam();
    }

    public function research(string $topic): string {
        // Step 1: Coordinator breaks down the topic
        $coordinator = agent('coordinator');
        $questions = $coordinator->prompt(
            "Break down this topic into 3 research questions: {$topic}"
        )->content;

        $this->research['questions'] = $questions;

        // Step 2: Parallel research by experts
        $expertFindings = [];
        foreach (['historian', 'scientist', 'analyst'] as $expert) {
            $agent = agent($expert);
            $findings = $agent->prompt(
                "Research this topic and provide insights: {$topic}\n" .
                "Focus on these questions: {$questions}"
            )->content;

            $expertFindings[$expert] = $findings;
        }

        $this->research['expert_findings'] = $expertFindings;

        // Step 3: Synthesize findings
        $synthesizer = agent('synthesizer');
        $synthesis = $synthesizer->prompt(
            "Synthesize these research findings:\n" .
            json_encode($expertFindings, JSON_PRETTY_PRINT)
        )->content;

        $this->research['synthesis'] = $synthesis;

        // Step 4: Final editing
        $editor = agent('editor');
        $final = $editor->prompt(
            "Edit and polish this research summary: {$synthesis}"
        )->content;

        $this->research['final'] = $final;

        return $final;
    }

    public function getResearchLog(): array {
        return $this->research;
    }
}

// Execute research
$workflow = new ResearchWorkflow();
$result = $workflow->research('The impact of artificial intelligence on education');
echo "Research Complete:\n{$result}\n";
```

## Testing Multi-Agent Systems

Always test agent interactions thoroughly:

```php
use function Pagent\mock;

// Test with mock agents
function testMultiAgentWorkflow() {
    // Create mock agents for testing
    mock('test-manager')
        ->respondsWith('Task delegated to worker')
        ->assertPrompted('Manage this task');

    mock('test-worker')
        ->respondsWith('Task completed')
        ->assertPrompted('Execute delegated task');

    // Test delegation
    $manager = agent('test-manager');
    $result = $manager->delegate('Manage this task')
        ->to('test-worker')
        ->execute();

    assert($result->worker_output === 'Task completed');
}

// Test pipeline error handling
function testPipelineErrorRecovery() {
    mock('failing-agent')
        ->failsWith('Service unavailable');

    $result = pipeline('test-pipeline')
        ->agent('working-agent')
        ->agent('failing-agent')
        ->agent('backup-agent')
        ->onError(fn() => 'Pipeline recovered')
        ->run('test input');

    assert($result === 'Pipeline recovered');
}
```

## Summary

In this chapter, you've learned the fundamentals of multi-agent orchestration in Pagent. You can now:

- Create specialized agents with clear responsibilities
- Implement manager-worker delegation patterns
- Build sequential processing pipelines
- Handle agent handoffs and context preservation
- Share memory and context across agent teams
- Manage agent lifecycles efficiently

Multi-agent systems enable you to tackle complex problems by dividing them into manageable pieces, each handled by specialized agents. This approach provides better modularity, scalability, and maintainability compared to monolithic solutions.

## Next Steps

Now that you understand multi-agent fundamentals:

1. **Experiment with Patterns**: Try different orchestration patterns for your use cases
2. **Build Complex Workflows**: Combine multiple patterns in sophisticated systems
3. **Optimize Performance**: Profile and optimize agent interactions
4. **Add Monitoring**: Implement telemetry for multi-agent systems
5. **Scale Up**: Explore distributed agent architectures

In the next chapter, we'll explore advanced orchestration patterns including consensus mechanisms, voting systems, and autonomous agent networks.
