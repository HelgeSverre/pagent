# Chapter 16: Multi-Agent Fundamentals

## Why Multiple Agents?

Most real-world AI applications require more than a single agent. A customer support system needs specialists for billing, technical issues, and account management. A content creation pipeline needs researchers, writers, and editors. A code review system needs analyzers, testers, and documenters.

Pagent provides three core primitives for multi-agent orchestration: **handoffs** for transferring conversations between agents, **delegation** for manager-worker patterns, and **pipelines** for sequential processing. Each primitive serves a distinct purpose and can be composed to build sophisticated multi-agent systems.

## The Agent Registry

Before orchestrating multiple agents, you need a way to manage them. Pagent's `Registry` class provides a simple global registry for storing and retrieving agents by name:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Pagent\Registry;
use function Pagent\agent;

// Create and register agents
$researcher = agent('researcher')
    ->provider('anthropic')
    ->system('You are a research assistant. Gather facts and sources.');

$writer = agent('writer')
    ->provider('openai')
    ->system('You are a content writer. Create engaging articles.');

$editor = agent('editor')
    ->provider('anthropic')
    ->system('You are an editor. Polish and improve content.');

// Registry automatically stores agents by name
Registry::get('researcher'); // Retrieve by name
Registry::has('researcher'); // Check existence
Registry::all();             // Get all agents
Registry::clear();           // Clear all agents
```

The registry is automatically populated when you create agents using the `agent()` function. You can also manually register agents using `Registry::set('name', $agent)`.

## Pattern 1: Handoffs

A **handoff** transfers a conversation from one agent to another, preserving the full conversation history. This is ideal for routing scenarios where different agents handle different types of requests.

### Basic Handoff

The simplest handoff transfers control to a new agent:

```php
<?php

declare(strict_types=1);

$support = agent('support')
    ->provider('anthropic')
    ->system('You are customer support. Route to billing or technical teams.');

$billing = agent('billing')
    ->provider('anthropic')
    ->system('You are the billing specialist. Help with payments and invoices.');

// User starts with support
$response = $support->prompt('I have a question about my invoice');

// Support decides to hand off to billing
$billingAgent = $support->handoff('billing', 'Customer needs billing assistance');

// Billing agent now has full conversation history
$answer = $billingAgent->prompt('What is your invoice number?');

echo $answer->content;
```

When the handoff occurs, the entire conversation history is transferred to the new agent with a special context message:

```
Previous conversation with support:

[user]: I have a question about my invoice
[assistant]: I'll transfer you to our billing specialist.

Handoff reason: Customer needs billing assistance
```

### Handoff with Decision Logic

Real-world systems need routing logic. Here's a customer support router that analyzes requests and hands off to the appropriate specialist:

```php
<?php

declare(strict_types=1);

function routeCustomerRequest(string $message): string
{
    $router = agent('router')
        ->provider('anthropic')
        ->system('Analyze customer requests and route to: billing, technical, or account');

    $technical = agent('technical')
        ->provider('anthropic')
        ->system('You are a technical support specialist. Help with technical issues.');

    $billing = agent('billing')
        ->provider('anthropic')
        ->system('You are a billing specialist. Help with payments and invoices.');

    $account = agent('account')
        ->provider('anthropic')
        ->system('You are an account specialist. Help with account settings.');

    // Router analyzes the request
    $analysis = $router->prompt("Route this request: {$message}\n\nRespond with only: billing, technical, or account");

    $route = trim(strtolower($analysis->content));

    // Hand off to the appropriate specialist
    $specialist = match ($route) {
        'billing' => $router->handoff('billing', 'Billing inquiry detected'),
        'technical' => $router->handoff('technical', 'Technical issue detected'),
        'account' => $router->handoff('account', 'Account management needed'),
        default => $router->handoff('technical', 'Default route'),
    };

    // Specialist handles the request
    $response = $specialist->prompt($message);

    return $response->content;
}

// Usage
echo routeCustomerRequest('I cannot log into my account');
// Routes to technical support

echo routeCustomerRequest('Why was I charged twice?');
// Routes to billing
```

### Multi-Hop Handoffs

Handoffs can chain multiple times. Consider a medical triage system:

```php
<?php

declare(strict_types=1);

$triage = agent('triage')
    ->provider('anthropic')
    ->system('You are a medical triage nurse. Assess urgency.');

$generalPractitioner = agent('gp')
    ->provider('anthropic')
    ->system('You are a general practitioner. Handle routine cases.');

$specialist = agent('specialist')
    ->provider('anthropic')
    ->system('You are a medical specialist. Handle complex cases.');

// Patient starts with triage
$response = $triage->prompt('I have chest pain and shortness of breath');

// Triage assesses severity
if (str_contains(strtolower($response->content), 'urgent')) {
    // High priority - go directly to specialist
    $currentAgent = $triage->handoff('specialist', 'Urgent symptoms detected');
} else {
    // Normal priority - go to GP first
    $currentAgent = $triage->handoff('gp', 'Routine care needed');

    // GP may escalate to specialist
    $gpAssessment = $currentAgent->prompt('Please assess the patient');

    if (str_contains(strtolower($gpAssessment->content), 'refer')) {
        $currentAgent = $currentAgent->handoff('specialist', 'GP referral');
    }
}

// Final specialist handles the case
$finalAdvice = $currentAgent->prompt('What are your recommendations?');
echo $finalAdvice->content;
```

## Pattern 2: Delegation

**Delegation** implements a manager-worker pattern where a manager agent assigns tasks to worker agents. Unlike handoffs, delegation maintains the manager as the primary agent and creates a structured workflow. Worker output is returned directly by default; manager review is explicit because it costs another provider call.

### Basic Delegation

The manager delegates a task and reviews the result:

```php
<?php

declare(strict_types=1);

$manager = agent('manager')
    ->provider('anthropic')
    ->system('You are a project manager. Delegate tasks and review results.');

$researcher = agent('researcher')
    ->provider('anthropic')
    ->system('You are a research assistant. Gather information thoroughly.');

// Manager delegates research task
$result = $manager->delegate('Research the history of PHP')
    ->to('researcher')
    ->review()
    ->execute();

echo "Task: {$result->task}\n";
echo "Worker: {$result->worker}\n";
echo "Worker Output: {$result->worker_output}\n";
echo "Manager Review: {$result->manager_review}\n";
```

The result object contains:

- `task` - The original task description
- `worker` - Name of the worker agent
- `worker_output` - The worker's response
- `manager` - Name of the manager agent
- `manager_review` - Manager's summary/review when `review()` was requested; otherwise an empty compatibility value
- `supervised` - Whether supervision was enabled

### Delegation with Supervision

Add a supervisor callback to review and provide feedback:

```php
<?php

declare(strict_types=1);

$manager = agent('manager')
    ->provider('anthropic')
    ->system('You are a content manager. Ensure high-quality output.');

$writer = agent('writer')
    ->provider('anthropic')
    ->system('You are a content writer.');

$result = $manager->delegate('Write a 200-word introduction to PHP')
    ->to('writer')
    ->supervise(function (string $output, string $task): bool|string {
        $wordCount = str_word_count($output);

        if ($wordCount < 150) {
            return 'Too short. Please expand to at least 200 words.';
        }

        if ($wordCount > 250) {
            return 'Too long. Please reduce to 200 words maximum.';
        }

        if (!str_contains(strtolower($output), 'php')) {
            return 'The topic is PHP. Please mention it explicitly.';
        }

        return true; // Approved
    })
    ->execute();

echo $result->worker_output;
```

The supervisor callback receives the worker's output and task description. It can return:

- `true` - Approve the output
- `false` - Reject the output (throws RuntimeException)
- `string` - Provide feedback; worker revises and resubmits

### Delegation with Callbacks

React to completion events:

```php
<?php

declare(strict_types=1);

use Pagent\Registry;

$manager = agent('manager')->provider('anthropic');
$researcher = agent('researcher')->provider('anthropic');

$results = [];

$manager->delegate('Research AI trends in 2025')
    ->to('researcher')
    ->onComplete(function ($result) use (&$results) {
        $results[] = $result;

        // Log to database
        logTaskCompletion([
            'worker' => $result->worker,
            'task' => $result->task,
            'duration' => time(),
        ]);

        // Send notification
        notifyManager("Task completed by {$result->worker}");
    })
    ->execute();
```

### Multi-Worker Delegation

Delegate to multiple workers and combine results:

```php
<?php

declare(strict_types=1);

function researchTopic(string $topic): array
{
    $manager = agent('manager')
        ->provider('anthropic')
        ->system('You are a research coordinator.');

    $researcher1 = agent('researcher-1')
        ->provider('anthropic')
        ->system('Research from academic sources.');

    $researcher2 = agent('researcher-2')
        ->provider('openai')
        ->system('Research from industry sources.');

    $results = [];

    // Delegate to multiple workers
    $results[] = $manager->delegate("Research {$topic} from academic perspective")
        ->to('researcher-1')
        ->execute();

    $results[] = $manager->delegate("Research {$topic} from industry perspective")
        ->to('researcher-2')
        ->execute();

    return $results;
}

$findings = researchTopic('Machine Learning in Healthcare');

foreach ($findings as $finding) {
    echo "[{$finding->worker}]\n";
    echo $finding->worker_output . "\n\n";
}
```

## Pattern 3: Pipelines

**Pipelines** create sequential processing chains where each agent transforms the output of the previous agent. This is ideal for multi-stage workflows like content creation, data processing, or code generation.

### Basic Pipeline

Create a simple three-stage pipeline:

```php
<?php

declare(strict_types=1);

use function Pagent\pipeline;

$researcher = agent('researcher')
    ->provider('anthropic')
    ->system('You are a researcher. Gather facts.');

$writer = agent('writer')
    ->provider('openai')
    ->system('You are a writer. Create engaging content.');

$editor = agent('editor')
    ->provider('anthropic')
    ->system('You are an editor. Polish and improve.');

$result = pipeline('content-creation')
    ->agent('researcher')
    ->agent('writer')
    ->agent('editor')
    ->run('Create an article about PHP 8.3 features');

echo $result; // Final edited content
```

The pipeline executes sequentially:

1. Researcher receives: "Create an article about PHP 8.3 features"
2. Writer receives: Researcher's output
3. Editor receives: Writer's output
4. Pipeline returns: Editor's final output

### Pipeline with Transformations

Transform data between stages:

```php
<?php

declare(strict_types=1);

use function Pagent\pipeline;

$dataCollector = agent('collector')
    ->provider('anthropic')
    ->system('Collect raw data points.');

$analyzer = agent('analyzer')
    ->provider('anthropic')
    ->system('Analyze data and find patterns.');

$reporter = agent('reporter')
    ->provider('openai')
    ->system('Create executive summaries.');

$result = pipeline('data-analysis')
    ->agent('collector')
    ->agent('analyzer', function (string $rawData): string {
        // Transform raw data into structured format
        $lines = explode("\n", $rawData);
        $structured = array_map(fn($line) => trim($line), $lines);
        $structured = array_filter($structured);

        return "Analyze these data points:\n" . implode("\n- ", $structured);
    })
    ->agent('reporter', function (string $analysis): string {
        // Extract key insights for reporting
        return "Summarize these insights into a 3-bullet executive summary:\n{$analysis}";
    })
    ->run('Collect data about customer satisfaction scores this quarter');

echo $result;
```

Each transformation function receives the previous stage's output and returns the input for the next stage.

### Pipeline Error Handling

Handle failures gracefully:

```php
<?php

declare(strict_types=1);

use function Pagent\pipeline;

$p = pipeline('error-handling-demo')
    ->agent('agent-1')
    ->agent('agent-2')
    ->agent('agent-3')
    ->onError(function (Exception $e, int $stage, string $agentName): string {
        // Log the error
        error_log("Pipeline failed at stage {$stage} (agent: {$agentName}): {$e->getMessage()}");

        // Return fallback result
        return "Pipeline failed at {$agentName}. Using fallback response.";
    });

$result = $p->run('Process this request');

// If any stage fails, the error handler provides fallback
echo $result;
```

Without an error handler, pipeline failures throw a `RuntimeException` with details about the failing stage.

### Inspecting Pipeline Results

Access intermediate results:

```php
<?php

declare(strict_types=1);

use function Pagent\pipeline;

$p = pipeline('inspectable');
$p->agent('stage-1');
$p->agent('stage-2');
$p->agent('stage-3');

$finalOutput = $p->run('Initial input');

// Inspect each stage
foreach ($p->getResults() as $result) {
    echo "Stage {$result['stage']}: {$result['agent']}\n";
    echo "Input: {$result['input']}\n";
    echo "Output: {$result['output']}\n";
    echo "Tokens: {$result['response']->usage['total_tokens']}\n\n";
}

echo "Final Output: {$finalOutput}\n";
```

The `getResults()` method returns an array with details for each completed stage:

- `stage` - Stage number (0-indexed)
- `agent` - Agent name
- `input` - Input sent to this stage
- `output` - Output from this stage
- `response` - Full AgentResponse object

## Shared Context and Memory

Multi-agent systems often need shared context. Pagent provides several approaches:

### Shared Memory

Multiple agents can share the same memory store:

```php
<?php

declare(strict_types=1);

// All agents share the same SQLite database
$researcher = agent('researcher')
    ->provider('anthropic')
    ->memory('Sqlite', ['path' => 'shared.db'])
    ->sessionId('project-alpha');

$writer = agent('writer')
    ->provider('openai')
    ->memory('Sqlite', ['path' => 'shared.db'])
    ->sessionId('project-alpha');

// Researcher's conversation is saved to shared memory
$researcher->prompt('Research topic X');

// Writer can see researcher's messages if they share sessionId
$writer->prompt('Write about what the researcher found');
```

This is useful for maintaining context across agent handoffs or allowing multiple agents to see the same conversation history.

### Agent Cloning

Clone an agent with the same configuration but fresh conversation:

```php
<?php

declare(strict_types=1);

$template = agent('template')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful assistant')
    ->tools(['calculator'])
    ->guard('pii');

// Create multiple instances with same config
$worker1 = $template->clone('worker-1');
$worker2 = $template->clone('worker-2');
$worker3 = $template->clone('worker-3');

// Each has independent conversation history
$worker1->prompt('Task 1');
$worker2->prompt('Task 2');
$worker3->prompt('Task 3');
```

Cloning copies configuration, tools, guards, and middleware, but creates a fresh message history. This is perfect for creating worker pools or parallel processing.

### Context Passing

Pass context explicitly between agents:

```php
<?php

declare(strict_types=1);

function collaborativeTask(string $input): string
{
    $researchAgent = agent('researcher')->provider('anthropic');
    $writerAgent = agent('writer')->provider('openai');

    // Research phase
    $research = $researchAgent->prompt($input);

    // Pass research to writer explicitly
    $article = $writerAgent->prompt(
        "Based on this research:\n\n{$research->content}\n\nWrite an article."
    );

    return $article->content;
}
```

This gives you explicit control over what information flows between agents.

## Lifecycle Management

Understanding agent lifecycle is crucial for multi-agent systems:

```php
<?php

declare(strict_types=1);

use Pagent\Registry;

// Create agents
$agent1 = agent('agent-1')->provider('anthropic');
$agent2 = agent('agent-2')->provider('openai');

// Check if agent exists
if (Registry::has('agent-1')) {
    $agent = Registry::get('agent-1');
}

// List all agents
$allAgents = Registry::all();
echo count($allAgents) . " agents registered\n";

// Clean up specific agent
Registry::set('agent-1', null); // Remove from registry

// Clear all agents
Registry::clear();
```

Agents are automatically garbage collected when no longer referenced, but the registry holds references until cleared.

## Practical Example: Content Creation Team

Let's build a complete multi-agent content creation system that combines all three patterns:

```php
<?php

declare(strict_types=1);

use function Pagent\pipeline;

class ContentCreationTeam
{
    private $manager;
    private $researcher;
    private $writer;
    private $editor;
    private $factChecker;

    public function __construct()
    {
        $this->manager = agent('manager')
            ->provider('anthropic')
            ->system('You are a content manager. Coordinate the team.');

        $this->researcher = agent('researcher')
            ->provider('anthropic')
            ->system('You are a researcher. Gather accurate information.');

        $this->writer = agent('writer')
            ->provider('openai')
            ->system('You are a creative writer. Write engaging content.');

        $this->editor = agent('editor')
            ->provider('anthropic')
            ->system('You are an editor. Improve clarity and flow.');

        $this->factChecker = agent('fact-checker')
            ->provider('anthropic')
            ->system('You are a fact checker. Verify accuracy.');
    }

    public function createArticle(string $topic): array
    {
        // Phase 1: Manager delegates research
        $researchResult = $this->manager->delegate("Research: {$topic}")
            ->to('researcher')
            ->supervise(function (string $output) {
                if (str_word_count($output) < 100) {
                    return 'Insufficient research. Provide more detail.';
                }
                return true;
            })
            ->execute();

        $research = $researchResult->worker_output;

        // Phase 2: Pipeline for content creation
        $draft = pipeline('content-pipeline')
            ->agent('writer', function () use ($research) {
                return "Write a 500-word article based on:\n{$research}";
            })
            ->agent('editor')
            ->agent('fact-checker', function (string $edited) {
                return "Verify facts in:\n{$edited}\nList any concerns.";
            })
            ->run($research);

        // Phase 3: Manager reviews and approves
        $approval = $this->manager->prompt(
            "Review this final article:\n\n{$draft}\n\nApprove or suggest changes."
        );

        return [
            'topic' => $topic,
            'research' => $research,
            'article' => $draft,
            'approval' => $approval->content,
        ];
    }
}

// Usage
$team = new ContentCreationTeam();
$result = $team->createArticle('Modern PHP Development Practices');

echo "Article: {$result['article']}\n";
echo "Status: {$result['approval']}\n";
```

This example demonstrates:

- **Delegation** for research task with supervision
- **Pipeline** for sequential content creation
- **Manager oversight** for final approval
- **Agent specialization** with distinct system prompts
- **Error handling** through supervision and validation

## Best Practices

**1. Keep Agent Responsibilities Clear**

Each agent should have a single, well-defined role:

```php
// Good: Clear, focused roles
$researcher = agent('researcher')->system('You research topics thoroughly.');
$writer = agent('writer')->system('You write engaging articles.');

// Avoid: Ambiguous or overlapping roles
$agent = agent('do-everything')->system('You do everything.');
```

**2. Use the Right Pattern**

- **Handoff**: When conversation ownership should transfer completely
- **Delegation**: When a manager needs to review worker output
- **Pipeline**: When sequential transformation is needed

**3. Handle Failures Gracefully**

Always consider what happens if an agent fails:

```php
pipeline('resilient')
    ->agent('stage-1')
    ->agent('stage-2')
    ->onError(fn($e) => "Fallback result")
    ->run('input');
```

**4. Monitor Resource Usage**

Track token usage across agents:

```php
$response = $agent->prompt('...');
$totalTokens = $response->usage['total_tokens'];

// Log or monitor cumulative usage
```

**5. Test Agent Interactions**

Use mock providers to test multi-agent flows:

```php
use function Pagent\mock;

$mockAgent = mock('test-agent', [
    'response' => 'Expected output',
]);

$result = pipeline('test')
    ->agent($mockAgent)
    ->run('input');

assert($result === 'Expected output');
```

## What's Next

You now understand the fundamentals of multi-agent orchestration: handoffs for conversation routing, delegation for manager-worker patterns, and pipelines for sequential processing. In the next chapter, we'll dive deeper into the **Pipeline Pattern**, exploring advanced techniques for building complex multi-stage workflows with error handling, transformations, and performance optimization.
