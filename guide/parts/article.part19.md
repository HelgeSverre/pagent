# Chapter 19: Delegation Pattern

In complex AI applications, a single agent often can't handle every task effectively. Some tasks require specialized expertise, different system prompts, or distinct tool sets. The Delegation pattern solves this by allowing a manager agent to delegate specific tasks to specialized worker agents, review their work, and aggregate results.

This chapter explores Pagent's built-in delegation system. You'll learn how to distribute work across multiple agents, implement quality control through supervision, handle delegation workflows programmatically, and build scalable multi-agent architectures.

## Understanding the Delegation Pattern

Delegation in Pagent follows a manager-worker model. A manager agent receives a high-level task, identifies work that can be delegated, assigns it to a specialized worker agent, and reviews the results before synthesizing a final response.

### Real-World Analogy

Think of delegation like a software development team:

1. **Product Manager** receives a feature request: "Build a user authentication system"
2. **Manager delegates** to a backend developer: "Implement JWT authentication"
3. **Worker** (backend dev) builds the implementation
4. **Tech Lead reviews** (optional supervision): "Add input validation"
5. **Worker revises** based on feedback
6. **Manager reviews** final work and integrates it into the product roadmap

Each participant has specialized skills. The manager orchestrates, workers execute, supervisors ensure quality, and everyone works within their domain of expertise.

## How Pagent Implements Delegation

Pagent's delegation system is built on three core components:

1. **Delegation class** - Fluent API for configuring and executing delegations
2. **Agent::delegate()** - Entry point method on every agent
3. **resolveAgent()** - Helper function for looking up agents from the Registry

Let's examine the implementation:

```php
// From src/Agent.php:707-710
public function delegate(string $task): Orchestration\Delegation
{
    return new Orchestration\Delegation($this, $task);
}
```

This simple method creates a new `Delegation` instance, passing the current agent as the manager and the task description. The `Delegation` class then provides a fluent API for configuring the delegation.

### The Delegation Class

The `Delegation` class manages the entire delegation lifecycle:

```php
// From src/Orchestration/Delegation.php:14-30
final class Delegation
{
    private Agent $manager;
    private Agent $worker;
    private string $task;
    private ?Closure $supervisor = null;
    private ?Closure $onComplete = null;

    public function __construct(Agent $manager, string $task)
    {
        $this->manager = $manager;
        $this->task = $task;
    }
}
```

The delegation holds references to:

- **Manager** - The agent delegating the task
- **Worker** - The agent executing the task (assigned via `to()`)
- **Task** - String description of what needs to be done
- **Supervisor** - Optional quality control callback
- **onComplete** - Optional callback executed after delegation finishes

## Basic Delegation Workflow

Let's start with the simplest possible delegation:

```php
<?php

use function Pagent\agent;

// Create a manager agent
$manager = agent('project-manager')
    ->provider(anthropic())
    ->system('You are a project manager coordinating a development team.')
    ->build();

// Create a worker agent
$worker = agent('backend-developer')
    ->provider(anthropic())
    ->system('You are a senior backend developer specializing in PHP.')
    ->build();

// Delegate a task
$result = $manager->delegate('Implement a JWT authentication middleware')
    ->to('backend-developer')
    ->execute();

// Inspect the result
echo "Task: {$result->task}\n";
echo "Worker: {$result->worker}\n";
echo "Worker Output:\n{$result->worker_output}\n\n";
echo "Manager Review:\n{$result->manager_review}\n";
```

### What Happens During Execution

When you call `execute()`, the delegation follows this sequence:

```php
// From src/Orchestration/Delegation.php:59-101
public function execute(): object
{
    if (! isset($this->worker)) {
        throw new RuntimeException('No worker agent assigned for delegation');
    }

    // Worker executes the task
    $workerResponse = $this->worker->prompt($this->task);

    // Supervisor reviews if provided
    if ($this->supervisor) {
        $review = ($this->supervisor)($workerResponse->content, $this->task);

        if ($review === false) {
            throw new RuntimeException("Supervisor rejected worker output");
        }

        if (is_string($review)) {
            // Supervisor provided feedback, ask worker to revise
            $workerResponse = $this->worker->prompt("Please revise based on this feedback: {$review}");
        }
    }

    // Manager reviews the result
    $managerPrompt = "Task: {$this->task}\n\nWorker ({$this->worker->getName()}) completed it with:\n{$workerResponse->content}\n\nProvide a brief summary.";
    $managerReview = $this->manager->prompt($managerPrompt);

    $result = (object) [
        'task' => $this->task,
        'worker' => $this->worker->getName(),
        'worker_output' => $workerResponse->content,
        'manager' => $this->manager->getName(),
        'manager_review' => $managerReview->content,
        'supervised' => $this->supervisor !== null,
    ];

    // Call completion callback if provided
    if ($this->onComplete) {
        ($this->onComplete)($result);
    }

    return $result;
}
```

The execution flow:

1. **Validate** - Ensure a worker agent was assigned
2. **Execute** - Worker agent processes the task via `prompt()`
3. **Supervise** (optional) - Supervisor callback reviews worker output
4. **Revise** (if needed) - Worker revises based on supervisor feedback
5. **Review** - Manager agent reviews the final worker output
6. **Package** - Results are wrapped in a result object
7. **Callback** (optional) - onComplete handler is invoked

## Agent Resolution with to()

The `to()` method accepts either an agent name (string) or an Agent instance:

```php
// From src/Orchestration/Delegation.php:32-43
public function to(string|Agent $worker): self
{
    $this->worker = resolveAgent($worker);

    if (! $this->worker instanceof Agent) {
        $name = is_string($worker) ? $worker : 'unknown';
        throw new RuntimeException("Worker agent '{$name}' not found");
    }

    return $this;
}
```

The `resolveAgent()` helper function looks up agent names in the Registry:

```php
// From src/functions.php:111-114
function resolveAgent(string|Agent $agent): Agent
{
    return is_string($agent) ? \agent($agent) : $agent;
}
```

This allows flexible agent assignment:

```php
// Option 1: Pass agent name (looks up in Registry)
$result = $manager->delegate('Task')
    ->to('backend-developer')
    ->execute();

// Option 2: Pass Agent instance directly
$worker = agent('temp-worker')->provider(anthropic())->build();
$result = $manager->delegate('Task')
    ->to($worker)
    ->execute();
```

Using names is cleaner when agents are registered globally. Passing instances directly is useful for ad-hoc delegations or when you want to create specialized worker configurations.

## Supervision and Quality Control

The `supervise()` method adds a quality control layer before the manager reviews the work:

```php
// From src/Orchestration/Delegation.php:45-50
public function supervise(?Closure $supervisor = null): self
{
    $this->supervisor = $supervisor;
    return $this;
}
```

The supervisor callback receives the worker output and task, and can return:

- **`true`** - Accept the work, proceed to manager review
- **`false`** - Reject the work, throw exception
- **`string`** - Provide feedback, worker revises

### Example: Code Quality Supervision

```php
<?php

use function Pagent\agent;

$manager = agent('tech-lead')
    ->provider(anthropic())
    ->system('You are a technical lead reviewing code implementations.')
    ->build();

$developer = agent('junior-dev')
    ->provider(anthropic())
    ->system('You are a junior developer implementing features.')
    ->build();

$result = $manager->delegate('Write a function to validate email addresses')
    ->to('junior-dev')
    ->supervise(function (string $output, string $task): bool|string {
        // Check for security best practices
        if (!str_contains($output, 'filter_var') && !str_contains($output, 'regex')) {
            return 'Please use either filter_var() with FILTER_VALIDATE_EMAIL or a robust regex pattern.';
        }

        // Check for input sanitization
        if (!str_contains($output, 'trim') && !str_contains($output, 'sanitize')) {
            return 'Add input sanitization to handle whitespace and unexpected characters.';
        }

        // Check for return type
        if (!str_contains($output, ': bool')) {
            return 'Add explicit return type declaration ": bool" to the function.';
        }

        // All checks passed
        return true;
    })
    ->execute();

echo $result->worker_output;
```

The supervisor acts as an automated code reviewer, enforcing quality standards before the tech lead (manager) provides final approval.

### When Supervisors Reject Work

If a supervisor returns `false`, the delegation throws an exception:

```php
// From src/Orchestration/Delegation.php:72-74
if ($review === false) {
    throw new RuntimeException("Supervisor rejected worker output for task: {$this->task}");
}
```

This is useful for hard requirements:

```php
$result = $manager->delegate('Generate a privacy policy')
    ->to('legal-writer')
    ->supervise(function (string $output, string $task): bool {
        $requiredSections = ['data collection', 'data usage', 'user rights', 'gdpr'];

        foreach ($requiredSections as $section) {
            if (!stripos($output, $section)) {
                // Critical requirement not met - reject completely
                return false;
            }
        }

        return true;
    })
    ->execute();
```

If any required section is missing, the delegation fails immediately with a clear error message.

## Completion Callbacks

The `onComplete()` method registers a callback that executes after delegation finishes successfully:

```php
// From src/Orchestration/Delegation.php:52-57
public function onComplete(Closure $callback): self
{
    $this->onComplete = $callback;
    return $this;
}
```

This is perfect for logging, notifications, or triggering downstream workflows:

```php
<?php

use function Pagent\agent;

$manager = agent('product-manager')->provider(anthropic())->build();
$designer = agent('ui-designer')->provider(anthropic())->build();

$result = $manager->delegate('Design a user dashboard layout')
    ->to('ui-designer')
    ->onComplete(function (object $result): void {
        // Log to database
        DB::table('delegations')->insert([
            'task' => $result->task,
            'worker' => $result->worker,
            'manager' => $result->manager,
            'completed_at' => now(),
        ]);

        // Notify team
        Slack::send("#design-team", "Dashboard design completed by {$result->worker}");

        // Trigger next step
        Queue::push(new ImplementDesignJob($result->worker_output));
    })
    ->execute();
```

The callback receives the complete result object with all delegation metadata.

## Practical Delegation Patterns

Let's explore real-world delegation scenarios.

### Pattern 1: Task Decomposition

Break down complex tasks into specialized sub-tasks:

```php
<?php

use function Pagent\agent;

class ContentCreationPipeline
{
    private Agent $editor;
    private Agent $researcher;
    private Agent $writer;
    private Agent $reviewer;

    public function __construct()
    {
        $this->editor = agent('editor')
            ->provider(anthropic())
            ->system('You are an editorial director planning content strategy.')
            ->build();

        $this->researcher = agent('researcher')
            ->provider(anthropic())
            ->system('You are a research specialist gathering factual information.')
            ->build();

        $this->writer = agent('writer')
            ->provider(anthropic())
            ->system('You are a technical writer creating educational content.')
            ->build();

        $this->reviewer = agent('reviewer')
            ->provider(anthropic())
            ->system('You are a content quality reviewer ensuring accuracy.')
            ->build();
    }

    public function createArticle(string $topic): string
    {
        // Step 1: Research the topic
        $researchResult = $this->editor->delegate("Research key facts and statistics about: {$topic}")
            ->to($this->researcher)
            ->execute();

        // Step 2: Write the article using research
        $writingResult = $this->editor->delegate(
            "Write a 1000-word article about {$topic} using this research:\n\n{$researchResult->worker_output}"
        )
            ->to($this->writer)
            ->execute();

        // Step 3: Review for quality
        $reviewResult = $this->editor->delegate("Review this article for accuracy and clarity")
            ->to($this->reviewer)
            ->supervise(function (string $output) {
                // Ensure review is substantial
                return str_word_count($output) > 50
                    ? true
                    : 'Please provide more detailed feedback on the article.';
            })
            ->execute();

        return $writingResult->worker_output;
    }
}

$pipeline = new ContentCreationPipeline();
$article = $pipeline->createArticle('Quantum Computing Applications');
```

### Pattern 2: Parallel Delegation

Delegate multiple independent tasks and aggregate results:

```php
<?php

use function Pagent\agent;

class AnalysisDashboard
{
    private Agent $coordinator;

    public function __construct()
    {
        $this->coordinator = agent('coordinator')
            ->provider(anthropic())
            ->system('You are coordinating multiple analysis tasks.')
            ->build();
    }

    public function analyzeSales(string $quarter): array
    {
        $results = [];

        // Create specialized analysts
        $regionalAnalyst = agent('regional-analyst')
            ->provider(anthropic())
            ->system('You analyze sales by geographic region.')
            ->build();

        $productAnalyst = agent('product-analyst')
            ->provider(anthropic())
            ->system('You analyze sales by product category.')
            ->build();

        $trendAnalyst = agent('trend-analyst')
            ->provider(anthropic())
            ->system('You identify sales trends over time.')
            ->build();

        // Delegate analysis tasks in parallel (sequentially executed but independent)
        $results['regional'] = $this->coordinator
            ->delegate("Analyze {$quarter} sales by region")
            ->to($regionalAnalyst)
            ->execute();

        $results['product'] = $this->coordinator
            ->delegate("Analyze {$quarter} sales by product")
            ->to($productAnalyst)
            ->execute();

        $results['trends'] = $this->coordinator
            ->delegate("Identify {$quarter} sales trends")
            ->to($trendAnalyst)
            ->execute();

        return $results;
    }
}

$dashboard = new AnalysisDashboard();
$analysis = $dashboard->analyzeSales('Q4 2024');

foreach ($analysis as $type => $result) {
    echo ucfirst($type) . " Analysis:\n";
    echo $result->worker_output . "\n\n";
}
```

Note: While these delegations execute sequentially in the current implementation, they're logically independent and could be parallelized in future versions or with custom execution strategies.

### Pattern 3: Hierarchical Delegation

Workers can become managers and delegate their own sub-tasks:

```php
<?php

use function Pagent\agent;

$cto = agent('cto')
    ->provider(anthropic())
    ->system('You are a CTO overseeing technical architecture.')
    ->build();

$techLead = agent('tech-lead')
    ->provider(anthropic())
    ->system('You are a technical lead managing implementation details.')
    ->build();

$developer = agent('developer')
    ->provider(anthropic())
    ->system('You are a developer implementing features.')
    ->build();

// CTO delegates to tech lead
$result = $cto->delegate('Design and implement a caching layer')
    ->to($techLead)
    ->onComplete(function (object $result) use ($techLead, $developer): void {
        // Tech lead delegates implementation to developer
        $techLead->delegate('Implement the caching layer based on this design: ' . $result->worker_output)
            ->to($developer)
            ->supervise(function (string $output): bool|string {
                if (!str_contains($output, 'Redis') && !str_contains($output, 'Memcached')) {
                    return 'Please use either Redis or Memcached for the cache backend.';
                }
                return true;
            })
            ->execute();
    })
    ->execute();
```

Each level of the hierarchy can enforce its own quality standards and coordination logic.

## Result Structure

Every delegation returns a structured result object:

```php
$result = $manager->delegate('Task')->to('worker')->execute();

// Available properties:
$result->task;            // string - Original task description
$result->worker;          // string - Worker agent name
$result->worker_output;   // string - Worker's response content
$result->manager;         // string - Manager agent name
$result->manager_review;  // string - Manager's review/summary
$result->supervised;      // bool - Whether supervision was used
```

This structured format makes it easy to:

- **Log delegations** - Store complete audit trail
- **Display progress** - Show who did what
- **Chain workflows** - Pass outputs to next stage
- **Aggregate results** - Combine outputs from multiple delegations

## Best Practices for Delegation

### 1. Specialized System Prompts

Give each agent clear expertise:

```php
// Generic agents
$manager = agent('manager')->provider(anthropic())->build();
$worker = agent('worker')->provider(anthropic())->build();

// Specialized agents
$manager = agent('security-lead')
    ->provider(anthropic())
    ->system('You are a security architect reviewing implementations for vulnerabilities.')
    ->build();

$worker = agent('security-engineer')
    ->provider(anthropic())
    ->system('You implement security controls following OWASP guidelines.')
    ->build();
```

Specialized system prompts improve output quality and create clear separation of concerns.

### 2. Descriptive Task Strings

Be specific about what you want:

```php
// Vague
$result = $manager->delegate('Do something with authentication')->to('worker')->execute();

// Specific
$result = $manager->delegate(
    'Implement JWT-based authentication middleware that validates tokens, ' .
    'checks expiration, and extracts user claims. Include error handling for ' .
    'invalid or expired tokens.'
)->to('worker')->execute();
```

Clear task descriptions help workers understand requirements and supervisors verify completion.

### 3. Use Supervision for Critical Work

Add supervision when quality matters:

```php
$result = $manager->delegate('Generate SQL migration for user_profiles table')
    ->to('database-engineer')
    ->supervise(function (string $output): bool|string {
        // Check for common SQL mistakes
        if (str_contains($output, 'DROP TABLE') && !str_contains($output, 'IF EXISTS')) {
            return 'Add IF EXISTS check before DROP TABLE to prevent accidental data loss.';
        }

        if (!str_contains($output, 'CONSTRAINT') && !str_contains($output, 'INDEX')) {
            return 'Add appropriate constraints and indexes for data integrity and performance.';
        }

        return true;
    })
    ->execute();
```

Supervision catches mistakes before the manager reviews, saving API calls and improving reliability.

### 4. Chain Delegations Through Callbacks

Use `onComplete()` to trigger multi-step workflows:

```php
$manager->delegate('Design database schema')
    ->to('architect')
    ->onComplete(function (object $designResult) use ($manager): void {
        $manager->delegate("Implement this schema: {$designResult->worker_output}")
            ->to('developer')
            ->onComplete(function (object $implResult) use ($manager): void {
                $manager->delegate("Write tests for: {$implResult->worker_output}")
                    ->to('qa-engineer')
                    ->execute();
            })
            ->execute();
    })
    ->execute();
```

This creates a pipeline where each delegation triggers the next, passing context forward.

## Delegation vs. Other Orchestration Patterns

Pagent provides multiple orchestration patterns. When should you use delegation?

**Use Delegation when:**

- You have clearly defined roles (manager/worker)
- Quality review is important (supervision)
- You need visibility into who did what (result tracking)
- Tasks require specialized expertise
- You want programmatic workflow control

**Use Handoff when:**

- Transferring control permanently between agents
- Agents represent stages in a linear workflow
- No review or aggregation needed
- Simple sequential processing

**Use Pipeline when:**

- Processing data through multiple transformations
- Each stage modifies the same content
- Order matters and is fixed
- No supervision needed between stages

Delegation provides the most structure and control, making it ideal for complex multi-agent scenarios where accountability and quality matter.

## Performance Considerations

Delegation involves multiple LLM calls:

1. Worker processes task (1 LLM call)
2. Supervisor optionally requests revision (1 LLM call if feedback provided)
3. Manager reviews result (1 LLM call)

That's potentially 3 LLM calls per delegation. For cost-sensitive applications:

- **Skip supervision** when quality is less critical
- **Use cheaper models** for manager review (GPT-4o-mini vs GPT-4)
- **Batch delegations** when possible to amortize overhead
- **Cache results** if tasks are repeated

## What's Next?

You now understand Pagent's delegation pattern:

- Manager-worker model with quality control
- Fluent API for configuring delegations
- Supervision with accept/reject/feedback options
- Completion callbacks for workflow orchestration
- Real-world patterns for task decomposition
- Best practices for specialized agents

In **Chapter 20: Building Multi-Agent Systems**, we'll explore:

- Combining delegation, handoff, and pipeline patterns
- Agent registry and lifecycle management
- Implementing agent communication protocols
- Building scalable agent architectures
- Testing multi-agent systems

**Key Takeaways:**

- Delegation follows a manager-worker-supervisor model
- Use `to()` to assign tasks to worker agents by name or instance
- Supervisors can accept, reject, or provide feedback on work
- Completion callbacks support workflow orchestration
- Results are structured objects with delegation metadata
- Specialized system prompts separate responsibilities
- Supervision adds an additional model call
- Delegation provides more structure than handoff or pipeline patterns

Continue to [Chapter 20: Evaluation Framework](./article.part20.md) →
