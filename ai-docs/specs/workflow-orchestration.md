# Workflow Orchestration Specification

**Goal:** Enable complex multi-agent patterns with a clean, fluent API.

**Status:** Partially Implemented

- ✅ **Chain** - Simple sequential execution (v0.5.x)
- ✅ **Pipeline** - Named steps with transforms (v0.5.x)
- ✅ **WorkflowResult/StepResult** - Shared result classes (v0.5.x)
- ⏸️ **Workflow** - Branching logic (Proposed for v0.6.0)
- ⏸️ **Graph** - Full graph-based DAG (Proposed for v0.7.0)
- ⏸️ **Parallel** - Parallel execution (Proposed for v0.7.0)

**Location:** This document lives in `ai-docs/specs/` as the authoritative technical specification for workflow patterns.

---

## Core Patterns to Support

1. **Sequential Pipeline** - Chain agents in sequence
2. **Parallel Execution** - Run multiple agents simultaneously
3. **Conditional Routing** - Route to agents based on conditions
4. **Handoff Pattern** - Agent delegates to another agent
5. **Supervisor/Worker** - Orchestrator delegates subtasks

---

## API Design Options

### Option 1: Fluent Workflow Builder

```php
workflow('customer-support')
    ->start('intake')
    ->then('classifier')
    ->branch(fn($result) => [
        'technical' => 'tech-support',
        'billing' => 'billing-support',
        'general' => 'general-support'
    ])
    ->finally('summary')
    ->run('User question here');

// Parallel execution
workflow('research')
    ->parallel([
        'web-search' => agent('searcher'),
        'db-query' => agent('db-agent'),
        'api-call' => agent('api-agent')
    ])
    ->merge(fn($results) => /* combine results */)
    ->run('Research topic');
```

### Option 2: Declarative Graph

```php
$workflow = workflow()
    ->node('intake', agent('intake'))
    ->node('classifier', agent('classifier'))
    ->node('tech', agent('tech-support'))
    ->node('billing', agent('billing-support'))

    ->edge('intake', 'classifier')
    ->edge('classifier', 'tech', when: fn($r) => $r->category === 'technical')
    ->edge('classifier', 'billing', when: fn($r) => $r->category === 'billing')

    ->start('intake')
    ->run('User input');
```

### Option 3: Simple Chain API

```php
// Sequential
chain()
    ->add(agent('step1'))
    ->add(agent('step2'))
    ->add(agent('step3'))
    ->run('Input');

// Parallel with collect
collect([
    agent('agent1')->prompt('Task A'),
    agent('agent2')->prompt('Task B'),
    agent('agent3')->prompt('Task C'),
])->summarize();
```

### Option 4: Agent Delegation (Built into Agent)

```php
agent('supervisor')
    ->tool('delegate', 'Delegate task to specialist', function(string $task, string $specialist) {
        return agent($specialist)->prompt($task)->content;
    })
    ->prompt('Complex task requiring multiple specialists');

// With explicit handoff
agent('triage')
    ->onComplete(fn($result) =>
        agent($result->nextAgent)->prompt($result->handoffMessage)
    )
    ->prompt('Customer inquiry');
```

---

## Recommended Approach: Hybrid (Option 1 + 4)

### Phase 1: Agent Delegation (Simplest)

Start with tools-based delegation (already works):

```php
agent('manager')
    ->system('You coordinate work between specialists')
    ->tool('ask_researcher', 'Get research from specialist', function(string $query) {
        return agent('researcher')->prompt($query)->content;
    })
    ->tool('ask_coder', 'Get code from specialist', function(string $spec) {
        return agent('coder')->prompt($spec)->content;
    })
    ->prompt('Build a web scraper for product prices');
```

**Pros:** Works today, no new API  
**Cons:** Manual wiring, no automatic orchestration

### Phase 2: Pipeline Builder

Add simple sequential workflows:

```php
use Pagent\Workflow\Pipeline;

$result = Pipeline::create()
    ->step('research', agent('researcher'))
    ->step('draft', agent('writer'))
    ->step('review', agent('editor'))
    ->run('Write article about PHP agents');

// Access intermediate results
$research = $result->step('research')->output;
$draft = $result->step('draft')->output;
```

**API:**

```php
class Pipeline
{
    public function step(string $name, Agent $agent): self;
    public function transform(string $name, callable $fn): self; // Data transformation
    public function run(string $input): PipelineResult;
}

class PipelineResult
{
    public function step(string $name): StepResult;
    public mixed $final; // Final output
}
```

### Phase 3: Conditional Routing

Add branching logic:

```php
Pipeline::create()
    ->step('classify', agent('classifier'))
    ->branch(fn($result) => match($result->category) {
        'technical' => Pipeline::create()->step('tech', agent('tech-support')),
        'billing' => Pipeline::create()->step('billing', agent('billing')),
        'general' => Pipeline::create()->step('general', agent('general')),
    })
    ->run('Customer inquiry');
```

### Phase 4: Parallel Execution

Run multiple agents concurrently:

```php
use Pagent\Workflow\Parallel;

$results = Parallel::run([
    'web' => fn() => agent('web-searcher')->prompt($query),
    'db' => fn() => agent('db-agent')->prompt($query),
    'api' => fn() => agent('api-caller')->prompt($query),
]);

// Merge results
$summary = agent('summarizer')->prompt(
    json_encode($results)
);
```

**Note:** PHP doesn't have true async, so "parallel" means:

- Multi-process (pcntl_fork) - Linux/Mac only
- Or sequential with parallel API for future async support
- Document limitation clearly

---

## Alternative: Graph-Based (More Complex)

```php
use Pagent\Workflow\Graph;

$workflow = Graph::create()
    ->node('start', agent('intake'))
    ->node('classify', agent('classifier'))
    ->node('tech', agent('tech-support'))
    ->node('billing', agent('billing'))
    ->node('end', agent('summarizer'))

    ->connect('start', 'classify')
    ->connect('classify', 'tech', when: fn($r) => $r->type === 'technical')
    ->connect('classify', 'billing', when: fn($r) => $r->type === 'billing')
    ->connect('tech', 'end')
    ->connect('billing', 'end')

    ->execute('start', 'User question');
```

**Pros:** Maximum flexibility, visual representation  
**Cons:** Complex implementation, harder to reason about

---

## Implementation Plan

### v0.5.1 - Basic Delegation (0 hours - already works)

Document current pattern:

```php
// examples/delegation.php
agent('coordinator')
    ->tool('delegate_research', 'Ask researcher', fn($q) =>
        agent('researcher')->prompt($q)->content
    )
    ->prompt('Find and summarize PHP agent libraries');
```

### v0.6.0 - Pipeline Builder (4-6 hours)

```php
// src/Workflow/Pipeline.php
// src/Workflow/PipelineResult.php
// tests/Unit/PipelineTest.php
// examples/workflow-pipeline.php
```

**Features:**

- Sequential step execution
- Named steps with intermediate results
- Transform steps (pure functions)
- Error handling per step

### v0.7.0 - Conditional + Parallel (6-8 hours)

**Conditional:**

```php
->branch(fn($result) => ...)
->branchIf(condition: fn($r) => ..., then: ..., else: ...)
```

**Parallel:**

```php
Parallel::run([...]) // Sequential fallback
Parallel::runAsync([...]) // Requires amphp/parallel or ReactPHP
```

### v1.0.0 - Graph Workflows (10-15 hours)

Full graph-based orchestration with cycle detection, visualization, and state management.

---

## Quick Prototype: Pipeline

```php
namespace Pagent\Workflow;

class Pipeline
{
    protected array $steps = [];

    public static function create(): self
    {
        return new self();
    }

    public function step(string $name, Agent|callable $handler): self
    {
        $this->steps[] = ['name' => $name, 'handler' => $handler, 'type' => 'agent'];
        return $this;
    }

    public function transform(string $name, callable $fn): self
    {
        $this->steps[] = ['name' => $name, 'handler' => $fn, 'type' => 'transform'];
        return $this;
    }

    public function run(string $input): PipelineResult
    {
        $results = [];
        $current = $input;

        foreach ($this->steps as $step) {
            if ($step['type'] === 'agent') {
                $response = $step['handler']->prompt($current);
                $current = $response->content;
            } else {
                $current = $step['handler']($current);
            }

            $results[$step['name']] = $current;
        }

        return new PipelineResult($results, $current);
    }
}

class PipelineResult
{
    public function __construct(
        protected array $steps,
        public mixed $final
    ) {}

    public function step(string $name): mixed
    {
        return $this->steps[$name] ?? null;
    }
}
```

**Usage:**

```php
$result = Pipeline::create()
    ->step('research', agent('researcher'))
    ->transform('extract_facts', fn($text) => /* parse facts */)
    ->step('write', agent('writer'))
    ->run('Topic: PHP Agents');

echo $result->final; // Final article
echo $result->step('research'); // Raw research
```

---

## Questions to Decide

1. **Start simple (Pipeline) or full graph?**  
   → Recommend: Pipeline first (v0.6.0), Graph later (v1.0.0)

2. **Parallel execution strategy?**  
   → Recommend: Sequential with parallel API, document async as future enhancement

3. **State management?**  
   → Recommend: Stateless pipelines, state stored in PipelineResult

4. **Visualization/debugging?**  
   → Recommend: JSON export of pipeline structure, HTML visualization later

5. **Error handling?**  
   → Recommend: Each step can ->onError(...), pipeline continues or halts based on config

---

## Example Use Cases

### Multi-Agent Research

```php
Pipeline::create()
    ->step('search', agent('searcher'))
    ->step('filter', agent('filter'))
    ->step('summarize', agent('summarizer'))
    ->step('cite', agent('citation-formatter'))
    ->run('Research: PHP async libraries');
```

### Customer Support Triage

```php
Pipeline::create()
    ->step('intake', agent('intake'))
    ->step('classify', agent('classifier'))
    ->branch(fn($r) => match($r->category) {
        'tech' => agent('tech-support'),
        'billing' => agent('billing'),
        default => agent('general')
    })
    ->step('log', agent('logger'))
    ->run('Customer message');
```

### Code Generation Pipeline

```php
Pipeline::create()
    ->step('plan', agent('architect'))
    ->step('implement', agent('coder'))
    ->step('test', agent('test-writer'))
    ->step('review', agent('reviewer'))
    ->run('Build a REST API for blog posts');
```

---

---

## How It Actually Works: Detailed Execution Model

### Anatomy of a Pipeline Step

```php
Pipeline::create()
    ->step('intake', agent('intake'))
    ->step('classify', agent('classifier'))
    ->run('Customer message: My invoice is wrong');
```

**What happens:**

1. **Step name (`'intake'`)** is just a label for accessing results later
2. **Agent (`agent('intake')`)** is a pre-configured agent from the registry
3. **Input** flows sequentially: `'Customer message'` → intake → classifier

**Detailed execution:**

```php
// Step 1: 'intake' step
$intakeAgent = agent('intake'); // Get registered agent
$intakeResponse = $intakeAgent->prompt('Customer message: My invoice is wrong');
$step1Output = $intakeResponse->content;
// e.g., "User has billing issue regarding invoice accuracy"

// Step 2: 'classify' step
$classifierAgent = agent('classifier'); // Get registered agent
$classifyResponse = $classifierAgent->prompt($step1Output); // Uses previous output!
$step2Output = $classifyResponse->content;
// e.g., JSON: {"category": "billing", "urgency": "high", "summary": "..."}

// Return PipelineResult
return new PipelineResult(
    steps: [
        'intake' => $step1Output,
        'classify' => $step2Output,
    ],
    final: $step2Output
);
```

### Agent Configuration: Where Do Instructions Come From?

**Option 1: Pre-configured agents (Recommended)**

```php
// Define agents once with their system prompts
agent('intake')
    ->system('You are an intake specialist. Extract key details from customer messages.')
    ->temperature(0.3);

agent('classifier')
    ->system('You are a classifier. Categorize support tickets as: tech, billing, or general. Return JSON: {"category": "...", "urgency": "...", "summary": "..."}')
    ->temperature(0.1);

agent('tech-support')
    ->system('You are a technical support specialist. Solve technical issues.')
    ->temperature(0.5);

// Use in pipeline
Pipeline::create()
    ->step('intake', agent('intake'))        // Uses pre-configured agent
    ->step('classify', agent('classifier'))  // Uses pre-configured agent
    ->branch(fn($r) => json_decode($r)->category === 'tech'
        ? agent('tech-support')
        : agent('general')
    )
    ->run('My app crashes on startup');
```

**Option 2: Inline configuration (Quick prototyping)**

```php
Pipeline::create()
    ->step('extract', agent('extractor')->system('Extract invoice details as JSON'))
    ->step('validate', agent('validator')->system('Validate extracted data'))
    ->run('Invoice #12345, Amount: $500, Due: 2025-01-15');
```

**Option 3: Reusable specialized agents**

```php
// Define reusable agents
agent('fact_extractor')
    ->system('Extract structured facts from unstructured text. Return JSON array of facts.')
    ->temperature(0.2);

agent('invoice_detail_extractor')
    ->system('Extract invoice details: number, amount, date, items. Return JSON.')
    ->temperature(0.1);

// Reuse in different workflows
$invoiceWorkflow = Pipeline::create()
    ->step('extract', agent('invoice_detail_extractor'))
    ->step('validate', agent('validator'))
    ->run($invoiceText);

$emailWorkflow = Pipeline::create()
    ->step('extract_facts', agent('fact_extractor'))
    ->step('summarize', agent('summarizer'))
    ->run($emailText);
```

### Do Agents See Step Names?

**No, by default.** Step names are metadata for you, not the AI.

But you _could_ inject context:

```php
// Option A: Pipeline injects step context (future feature)
Pipeline::create()
    ->step('research', agent('researcher'))
    ->withContext(true) // Adds "You are at step: research" to prompt
    ->run('Topic');

// Option B: Manual context injection
Pipeline::create()
    ->step('research', agent('researcher'))
    ->transform('add_context', fn($text) => "Step: analysis\n\n$text")
    ->step('analyze', agent('analyzer'))
    ->run('Data');
```

### Pipeline Output Structure

```php
$result = Pipeline::create()
    ->step('intake', agent('intake'))
    ->step('classify', agent('classifier'))
    ->step('respond', agent('responder'))
    ->run('Customer message');

// Access results
echo $result->final; // Final output from 'respond' step
echo $result->step('intake'); // Output from 'intake' step
echo $result->step('classify'); // Output from 'classify' step

// Full structure
$result = [
    'steps' => [
        'intake' => [
            'output' => 'Extracted customer issue...',
            'agent' => 'intake',
            'input' => 'Customer message',
            'metadata' => [
                'tokens' => 150,
                'duration' => 1.2,
                'timestamp' => '2025-01-12 10:30:00'
            ]
        ],
        'classify' => [
            'output' => '{"category": "billing", ...}',
            'agent' => 'classifier',
            'input' => 'Extracted customer issue...',
            'metadata' => [...]
        ],
        'respond' => [
            'output' => 'Dear customer, regarding your billing issue...',
            'agent' => 'responder',
            'input' => '{"category": "billing", ...}',
            'metadata' => [...]
        ]
    ],
    'final' => 'Dear customer, regarding your billing issue...',
    'metadata' => [
        'total_tokens' => 450,
        'total_duration' => 3.8,
        'steps_executed' => 3
    ]
];
```

### Branching: How Does It Choose?

```php
Pipeline::create()
    ->step('classify', agent('classifier'))
    ->branch(fn($result) => match(json_decode($result->content)->category) {
        'tech' => agent('tech-support'),
        'billing' => agent('billing'),
        default => agent('general')
    })
    ->run('My invoice is wrong');
```

**Execution:**

1. `classifier` agent returns: `{"category": "billing", "urgency": "high"}`
2. Branch callable receives full result object
3. It parses the JSON, extracts `category`
4. Returns `agent('billing')` based on match
5. Pipeline continues with billing agent

**With transform step for cleaner parsing:**

```php
Pipeline::create()
    ->step('classify', agent('classifier'))
    ->transform('parse', fn($text) => json_decode($text, true))
    ->branch(fn($data) => match($data['category']) {
        'tech' => agent('tech-support'),
        'billing' => agent('billing'),
        default => agent('general')
    })
    ->run('My invoice is wrong');
```

### Combining Multiple Agent Outputs

**Option 1: Flatten/merge in final step**

```php
use Pagent\Workflow\Parallel;

$results = Parallel::run([
    'invoice' => fn() => agent('invoice_extractor')->prompt($text),
    'vendor' => fn() => agent('vendor_extractor')->prompt($text),
    'items' => fn() => agent('item_extractor')->prompt($text),
]);

// Results structure
$results = [
    'invoice' => ['number' => 'INV-001', 'date' => '2025-01-12'],
    'vendor' => ['name' => 'Acme Corp', 'id' => 'V123'],
    'items' => [['sku' => 'A1', 'qty' => 5], ['sku' => 'B2', 'qty' => 3]]
];

// Merge with combiner agent
$merged = agent('merger')->prompt(
    "Combine this data into a single invoice object:\n" . json_encode($results)
);
```

**Option 2: Collector pattern**

```php
$result = Pipeline::create()
    ->parallel([
        'facts' => agent('fact_extractor'),
        'sentiment' => agent('sentiment_analyzer'),
        'entities' => agent('entity_extractor')
    ])
    ->collect() // Returns all results keyed by step name
    ->run($text);

// Access
$result->step('facts'); // Facts from fact_extractor
$result->step('sentiment'); // Sentiment score
$result->step('entities'); // Extracted entities
$result->combined; // All results as array
```

**Option 3: Custom merger**

```php
Pipeline::create()
    ->step('extract_invoice', agent('invoice_extractor'))
    ->step('extract_items', agent('item_extractor'))
    ->merge(fn($steps) => [
        'invoice' => json_decode($steps['extract_invoice'], true),
        'items' => json_decode($steps['extract_items'], true),
    ])
    ->run($text);
```

### Reusable Agent Patterns

```php
// Define reusable specialized agents
agent('json_extractor')
    ->system('Extract data as valid JSON. No markdown, no explanations.')
    ->temperature(0.1);

agent('fact_checker')
    ->system('Verify facts. Return true/false for each claim.')
    ->temperature(0.2);

agent('summarizer')
    ->system('Summarize in 2-3 sentences.')
    ->temperature(0.5);

// Reuse across workflows
$workflow1 = Pipeline::create()
    ->step('extract', agent('json_extractor'))
    ->step('verify', agent('fact_checker'))
    ->run($invoiceText);

$workflow2 = Pipeline::create()
    ->step('extract', agent('json_extractor')) // Same agent!
    ->step('summarize', agent('summarizer'))
    ->run($reportText);

// Composition: specialized extractors
agent('invoice_extractor')
    ->extends('json_extractor') // Inherits base config
    ->system('Extract invoice: number, amount, date, vendor'); // Adds specifics

agent('receipt_extractor')
    ->extends('json_extractor')
    ->system('Extract receipt: store, date, items, total');
```

### Full Example: Invoice Processing

```php
// Step 1: Define reusable agents
agent('ocr')
    ->system('Extract text from image descriptions')
    ->temperature(0.1);

agent('invoice_parser')
    ->system('Parse invoice text into JSON: {number, date, vendor, items[], total}')
    ->temperature(0.1);

agent('validator')
    ->system('Validate invoice data. Check: totals match, dates valid, required fields present. Return: {valid: bool, errors: []}')
    ->temperature(0.1);

agent('categorizer')
    ->system('Categorize invoice items by department: office, tech, travel, etc.')
    ->temperature(0.2);

agent('approver')
    ->system('Determine approval needed based on amount and category. Return: {approved: bool, requires_review: bool, assigned_to: string}')
    ->temperature(0.3);

// Step 2: Build workflow
$result = Pipeline::create()
    ->step('ocr', agent('ocr'))
    ->step('parse', agent('invoice_parser'))
    ->step('validate', agent('validator'))
    ->branch(fn($r) => json_decode($r)->valid
        ? Pipeline::create()
            ->step('categorize', agent('categorizer'))
            ->step('approve', agent('approver'))
        : Pipeline::create()
            ->step('flag_errors', agent('error_handler'))
    )
    ->run($invoiceImage);

// Step 3: Access results
$parsed = json_decode($result->step('parse'), true);
$validation = json_decode($result->step('validate'), true);

if ($validation['valid']) {
    $approval = json_decode($result->final, true);
    echo "Approved: {$approval['approved']}";
} else {
    echo "Errors: " . implode(', ', $validation['errors']);
}
```

### Output Object API (Proposed)

```php
class PipelineResult
{
    public readonly mixed $final;           // Final output
    public readonly array $steps;           // All step outputs
    public readonly PipelineMetadata $meta; // Execution metadata

    // Access by step name
    public function step(string $name): StepResult;

    // Get all outputs as array
    public function toArray(): array;

    // Get specific field from final output (if JSON)
    public function get(string $key, mixed $default = null): mixed;

    // Check if step exists
    public function has(string $step): bool;

    // Export for debugging
    public function export(): array;
}

class StepResult
{
    public readonly string $name;
    public readonly mixed $output;
    public readonly mixed $input;
    public readonly string $agent;
    public readonly StepMetadata $meta;

    // Parse JSON output
    public function json(): array;

    // Get specific field (if JSON)
    public function get(string $key, mixed $default = null): mixed;
}
```

**Usage:**

```php
$result = Pipeline::create()
    ->step('extract', agent('extractor'))
    ->step('validate', agent('validator'))
    ->run($text);

// Simple access
echo $result->final;

// Structured access
$extractedData = $result->step('extract')->json();
$isValid = $result->step('validate')->get('valid', false);

// Metadata
echo "Tokens used: {$result->meta->totalTokens}";
echo "Duration: {$result->meta->duration}s";

// Full export for logging
$log = $result->export();
```

---

---

## Implementation Strategy: Support All Patterns

**Why not all of them?** Different patterns suit different use cases:

- **Chain** → Simple sequential tasks
- **Pipeline** → Named steps with intermediate access
- **Workflow** → Complex branching logic
- **Graph** → Maximum flexibility, visual representation
- **Delegation** → Already works, just document it

### Shared Foundation

All patterns share common infrastructure:

```php
// Core abstractions
interface WorkflowExecutor {
    public function run(mixed $input): WorkflowResult;
}

class WorkflowResult {
    public readonly mixed $final;
    public readonly array $steps;
    public readonly Metadata $meta;
}

// Different facades, same engine
Chain::create()      → ChainExecutor → WorkflowResult
Pipeline::create()   → PipelineExecutor → WorkflowResult
Workflow::create()   → WorkflowExecutor → WorkflowResult
Graph::create()      → GraphExecutor → WorkflowResult
```

### Phased Rollout

**v0.5.1 - Foundation (2-3 hours)**

Core abstractions + simplest implementation:

```php
// src/Workflow/WorkflowResult.php
// src/Workflow/StepResult.php
// src/Workflow/Metadata.php
// src/Workflow/Chain.php (simplest pattern)
// tests/Unit/ChainTest.php
// examples/simple-chain.php
```

**Chain API:**

```php
use Pagent\Workflow\Chain;

$result = Chain::create()
    ->add(agent('step1'))
    ->add(agent('step2'))
    ->add(agent('step3'))
    ->run('Input');

echo $result->final;
```

**v0.6.0 - Pipeline + Workflow (4-6 hours)**

Named steps + branching:

```php
// src/Workflow/Pipeline.php
// src/Workflow/Workflow.php
// tests/Unit/PipelineTest.php
// tests/Unit/WorkflowTest.php
// examples/pipeline-workflow.php
```

**Pipeline API:**

```php
use Pagent\Workflow\Pipeline;

$result = Pipeline::create()
    ->step('research', agent('researcher'))
    ->step('draft', agent('writer'))
    ->step('review', agent('editor'))
    ->run($topic);

echo $result->step('research'); // Access intermediate
```

**Workflow API:**

```php
use Pagent\Workflow\Workflow;

$result = Workflow::create()
    ->start(agent('intake'))
    ->then(agent('classify'))
    ->branch(fn($r) => match(json_decode($r)->type) {
        'tech' => agent('tech-support'),
        'billing' => agent('billing'),
    })
    ->run($message);
```

**v0.7.0 - Graph + Parallel (6-8 hours)**

Full graph-based + parallel execution:

```php
// src/Workflow/Graph.php
// src/Workflow/Parallel.php
// src/Workflow/GraphVisualizer.php
// tests/Unit/GraphTest.php
// examples/complex-graph.php
```

**Graph API:**

```php
use Pagent\Workflow\Graph;

$workflow = Graph::create()
    ->node('start', agent('intake'))
    ->node('classify', agent('classifier'))
    ->node('tech', agent('tech'))
    ->node('billing', agent('billing'))
    ->node('merge', agent('merger'))

    ->edge('start', 'classify')
    ->edge('classify', 'tech', when: fn($r) => $r->type === 'tech')
    ->edge('classify', 'billing', when: fn($r) => $r->type === 'billing')
    ->edge('tech', 'merge')
    ->edge('billing', 'merge')

    ->run('start', $input);

// Visualize
$workflow->visualize('workflow.html');
```

**Parallel API:**

```php
use Pagent\Workflow\Parallel;

// Sequential fallback (default)
$results = Parallel::run([
    'facts' => fn() => agent('fact_extractor')->prompt($text),
    'sentiment' => fn() => agent('sentiment')->prompt($text),
    'entities' => fn() => agent('entities')->prompt($text),
]);

// True parallel (requires ext-pcntl or amphp)
$results = Parallel::runAsync([...]);
```

### File Structure

```
src/Workflow/
├── Chain.php                 # Simple sequential
├── Pipeline.php              # Named steps
├── Workflow.php              # Branching logic
├── Graph.php                 # Full graph
├── Parallel.php              # Parallel execution
├── WorkflowResult.php        # Shared result
├── StepResult.php            # Individual step
├── Metadata.php              # Execution metadata
├── Contracts/
│   ├── WorkflowExecutor.php
│   └── StepHandler.php
└── Support/
    ├── GraphVisualizer.php
    └── StepTransformer.php

tests/Unit/Workflow/
├── ChainTest.php
├── PipelineTest.php
├── WorkflowTest.php
├── GraphTest.php
└── ParallelTest.php

examples/
├── 08-simple-chain.php
├── 09-pipeline-steps.php
├── 10-workflow-branching.php
├── 11-graph-visualization.php
└── 12-parallel-execution.php
```

### API Comparison: Same Task, Different Styles

**Task:** Process customer inquiry → classify → route to specialist → respond

**Chain (Simplest):**

```php
Chain::create()
    ->add(agent('intake'))
    ->add(agent('classifier'))
    ->add(agent('specialist'))
    ->add(agent('responder'))
    ->run($inquiry);
```

**Pipeline (Named Steps):**

```php
Pipeline::create()
    ->step('intake', agent('intake'))
    ->step('classify', agent('classifier'))
    ->step('route', agent('specialist'))
    ->step('respond', agent('responder'))
    ->run($inquiry);

// Can access: $result->step('classify')
```

**Workflow (Branching):**

```php
Workflow::create()
    ->start(agent('intake'))
    ->then(agent('classifier'))
    ->branch(fn($r) => match($r->category) {
        'tech' => agent('tech-specialist'),
        'billing' => agent('billing-specialist'),
    })
    ->then(agent('responder'))
    ->run($inquiry);
```

**Graph (Full Control):**

```php
Graph::create()
    ->node('intake', agent('intake'))
    ->node('classify', agent('classifier'))
    ->node('tech', agent('tech-specialist'))
    ->node('billing', agent('billing-specialist'))
    ->node('respond', agent('responder'))

    ->edge('intake', 'classify')
    ->edge('classify', 'tech', when: fn($r) => $r->category === 'tech')
    ->edge('classify', 'billing', when: fn($r) => $r->category === 'billing')
    ->edge('tech', 'respond')
    ->edge('billing', 'respond')

    ->run('intake', $inquiry);
```

### When to Use Each Pattern

| Pattern      | Use When                  | Complexity    | Flexibility        |
| ------------ | ------------------------- | ------------- | ------------------ |
| **Chain**    | Simple A→B→C flow         | ⭐ Low        | ⭐ Low             |
| **Pipeline** | Need intermediate results | ⭐⭐ Medium   | ⭐⭐ Medium        |
| **Workflow** | Conditional branching     | ⭐⭐⭐ Medium | ⭐⭐⭐ High        |
| **Graph**    | Complex DAGs, cycles, viz | ⭐⭐⭐⭐ High | ⭐⭐⭐⭐ Very High |
| **Parallel** | Independent tasks         | ⭐⭐ Medium   | ⭐⭐⭐ High        |

### Development Plan

**Week 1: Foundation (v0.5.1)**

- [ ] Shared abstractions (WorkflowResult, StepResult, Metadata)
- [ ] Chain implementation
- [ ] Unit tests
- [ ] Example + docs

**Week 2: Core Patterns (v0.6.0)**

- [ ] Pipeline implementation
- [ ] Workflow implementation (with branching)
- [ ] Transform steps
- [ ] Error handling
- [ ] Examples + docs

**Week 3: Advanced (v0.7.0)**

- [ ] Graph implementation
- [ ] Parallel execution (sequential fallback)
- [ ] GraphVisualizer (Mermaid export)
- [ ] Async support (optional dependency)
- [ ] Examples + docs

**Estimated Total: 12-17 hours**

### Quick Win: Start with Chain (Today)

Simplest implementation, validates shared abstractions:

```php
namespace Pagent\Workflow;

class Chain
{
    protected array $steps = [];

    public static function create(): self
    {
        return new self();
    }

    public function add(Agent $agent): self
    {
        $this->steps[] = $agent;
        return $this;
    }

    public function run(mixed $input): WorkflowResult
    {
        $current = $input;
        $stepResults = [];

        foreach ($this->steps as $index => $agent) {
            $startTime = microtime(true);
            $response = $agent->prompt($current);
            $duration = microtime(true) - $startTime;

            $stepResults[] = new StepResult(
                name: "step_{$index}",
                output: $response->content,
                input: $current,
                agent: $agent->name ?? "agent_{$index}",
                meta: new StepMetadata(
                    tokens: $response->usage?->total_tokens ?? 0,
                    duration: $duration
                )
            );

            $current = $response->content;
        }

        return new WorkflowResult(
            final: $current,
            steps: $stepResults,
            meta: new WorkflowMetadata(
                totalTokens: array_sum(array_column($stepResults, 'tokens')),
                duration: array_sum(array_column($stepResults, 'duration')),
                stepsExecuted: count($stepResults)
            )
        );
    }
}
```

Want to implement Chain now to validate the design?

---

## Next Steps

1. ✅ **Decision made** - Implement all patterns!
2. **Implement Chain** - Validate shared abstractions (2-3 hours)
3. **Add Pipeline + Workflow** - Named steps + branching (4-6 hours)
4. **Add Graph + Parallel** - Advanced features (6-8 hours)
5. **Document patterns** - Examples for each style
6. **Plan v0.6.0** - Schedule workflow features
