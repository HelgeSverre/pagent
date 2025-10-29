# How Pagent Works - Technical Explanation

## 📊 Evaluation System

### Purpose of the Reports Folder

The `examples/reports/` folder contains **generated evaluation reports** that are created when you run the evaluation framework.

**What it's for**:

- Testing agent quality systematically
- Comparing agent performance across datasets
- Generating shareable HTML/JSON/Markdown reports
- Tracking improvements over time

### How Evaluation Works

**Step 1: Create a Dataset**

```php
// From JSON file
$dataset = Dataset::fromJson('examples/datasets/support_tickets.json');

// Or from array
$dataset = Dataset::fromArray([
    ['input' => 'Question 1', 'expected' => 'Answer 1'],
    ['input' => 'Question 2', 'expected' => 'Answer 2'],
]);
```

**Step 2: Define Metrics**

```php
evaluate('support-bot')
    ->dataset($dataset)
    ->metric('keywords', new KeywordMetric(['help', 'assist', 'order']))
    ->metric('length', new LengthMetric(minLength: 20, maxLength: 200))
    ->metric('custom', fn($input, $output, $expected) => 0.85)
    ->run();
```

**Step 3: Run Evaluation**
The evaluator:

1. Loops through each item in the dataset
2. Calls `agent()->prompt($item['input'])`
3. Calculates each metric on the output
4. Stores results with scores

**Step 4: Generate Reports**

```php
$result = evaluate('bot')->dataset($data)->metric(...)->run();

$report = new Report($result);
$report->save('reports/evaluation.html'); // HTML with styling
$report->save('reports/evaluation.json'); // Raw data
$report->save('reports/evaluation.md');   // Markdown
```

**Example Report Structure**:

```json
{
  "agent": "support-bot",
  "dataset_size": 5,
  "summary": {
    "metrics": {
      "keywords": { "average": 0.75, "min": 0.5, "max": 1.0 },
      "length": { "average": 0.82, "min": 0.6, "max": 1.0 }
    }
  },
  "results": [
    {
      "input": "My order never arrived",
      "output": "I apologize for the delay...",
      "expected": "track order status",
      "metrics": { "keywords": 0.8, "length": 0.9 }
    }
  ]
}
```

**Use Cases**:

- Test if changing the system prompt improves quality
- Compare GPT-3.5 vs GPT-4 vs Claude on same tasks
- Track regression when updating agent configuration
- A/B testing different agent variations

---

## 🤝 Multi-Agent Orchestration

Pagent provides three orchestration patterns for coordinating multiple agents:

1. **Pipeline** - Sequential processing through multiple agents
2. **Handoff** - Transfer conversations between specialist agents
3. **Delegation** - Manager-worker pattern with supervision

**Note**: Advanced routing patterns (conditional router, swarm intelligence) are planned for future releases. See `DEVELOPMENT_ROADMAP.md` for details.

### 1. Pipeline (Sequential Processing)

**What it does**: Chains multiple agents sequentially, passing output from one to the next.

```php
pipeline('document-processor')
    ->agent('extractor')      // Step 1: Extract data
    ->agent('validator')      // Step 2: Validate it
    ->agent('formatter')      // Step 3: Format nicely
    ->run($document);
```

**How it works**:

1. Takes initial input
2. Calls first agent with input
3. Passes agent's output to next agent as input
4. Repeats for all agents in sequence
5. Returns final output

**With Transform Functions**:

```php
pipeline('analysis')
    ->agent('analyzer')
    ->agent('reporter', function($sentiment) {
        // Transform output before next agent
        return "The sentiment was: {$sentiment}. Explain why.";
    })
    ->run($text);
```

**Internal Flow**:

```
Input: "John Doe, age 30"
  ↓
[Extractor Agent]
  → Output: "Name: John Doe, Age: 30"
  ↓
[Validator Agent]
  → Output: "✓ Name: valid, Age: valid"
  ↓
[Formatter Agent]
  → Output: "Person: John Doe (30 years old)"
```

---

### 2. Agent Handoff (Conversation Transfer)

**What it does**: Transfers an ongoing conversation from one agent to another specialist.

```php
$support = agent('general-support');
$support->prompt('I have a legal question');

// Transfer to legal expert with full context
$legalAgent = $support->handoff('legal-expert', 'Customer needs legal help');
```

**How it works**:

1. Takes all conversation history from source agent
2. Packages it into a context message
3. Adds it to target agent's message history
4. Returns the target agent ready to continue

**What gets transferred**:

```php
// From source agent's message history:
[
  ['role' => 'user', 'content' => 'First question'],
  ['role' => 'assistant', 'content' => 'First answer'],
  ['role' => 'user', 'content' => 'Follow-up question'],
]

// Becomes in target agent:
[
  ['role' => 'user', 'content' => "Previous conversation with general-support:

    [user]: First question
    [assistant]: First answer
    [user]: Follow-up question

    Handoff reason: Customer needs legal help"]
]
```

**Use Cases**:

- Route customers to specialists (support → legal, support → technical)
- Escalate complex issues (junior → senior agent)
- Transfer between languages (English agent → Spanish agent)

---

### 3. Delegation (Manager-Worker Pattern)

**What it does**: A manager agent delegates work to a worker agent, reviews the result.

```php
agent('manager')->delegate('Build feature X')
    ->to('developer')
    ->supervise(fn($output) => str_contains($output, 'function'))
    ->onComplete(fn($result) => log($result))
    ->execute();
```

**How it works**:

**Step 1: Worker executes task**

```php
$workerResponse = $worker->prompt($task);
```

**Step 2: Supervisor reviews (optional)**

```php
if ($supervisor) {
    $review = $supervisor($workerResponse->content, $task);

    if ($review === false) {
        throw new Exception("Rejected");
    }

    if (is_string($review)) {
        // Supervisor provided feedback - ask worker to revise
        $workerResponse = $worker->prompt("Revise: {$review}");
    }
}
```

**Step 3: Manager reviews**

```php
$managerReview = $manager->prompt(
    "Task: {$task}\n" .
    "Worker completed it with: {$workerResponse->content}\n" .
    "Provide summary."
);
```

**Returns**:

```php
{
    'task' => 'Build feature X',
    'worker' => 'developer',
    'worker_output' => '...',
    'manager' => 'project-manager',
    'manager_review' => '...',
    'supervised' => true
}
```

---

### 4. Error Recovery in Pipelines

**What it does**: Catches errors in multi-agent pipelines and recovers gracefully.

```php
pipeline('data-processor')
    ->agent('step1')
    ->agent('step2')
    ->onError(function($error, $stageIndex, $agentName) {
        // Recover by returning default value
        return "Failed at {$agentName}, using fallback data";
    })
    ->run($input);
```

**How it works**:

**Without error handler**:

```php
try {
    foreach ($stages as $index => $stage) {
        $output = $agent->prompt($input);
    }
} catch (Exception $e) {
    // Re-throw with context
    throw new RuntimeException("Pipeline failed at stage {$index}");
}
```

**With error handler**:

```php
try {
    $output = $agent->prompt($input);
} catch (Exception $e) {
    // Call custom error handler
    return $errorHandler($e, $stageIndex, $agentName);
}
```

**Use Cases**:

- API rate limits → fallback to cached response
- Agent timeout → skip to next stage
- Validation failure → return partial results
- Network errors → retry or use default

---

## 🔄 Complete Example Flow

Here's how all three patterns work together:

```php
// 1. PIPELINE: Process document through specialists
$extractedData = pipeline('intake')
    ->agent('extractor')
    ->agent('classifier')
    ->onError(fn($e) => "extraction failed")
    ->run($document);

// 2. HANDOFF: Route to appropriate specialist based on classification
$generalAgent = agent('support');
$generalAgent->prompt($extractedData);

$specialist = match($category) {
    'legal' => $generalAgent->handoff('legal-expert'),
    'technical' => $generalAgent->handoff('tech-support'),
    default => $generalAgent,
};

// 3. DELEGATION: Specialist delegates complex work
$result = $specialist->delegate('Research case law')
    ->to('legal-researcher')
    ->supervise(fn($output) => strlen($output) > 100)
    ->execute();
```

---

## 📁 Reports Folder Summary

**Purpose**: Stores generated evaluation reports

**Contents**:

- `evaluation.html` - Styled HTML report for viewing in browser
- `evaluation.json` - Raw data for programmatic analysis
- `evaluation.md` - Markdown for documentation/GitHub

**When created**: Automatically when you call `$report->save('path')`

**Should it be committed?**:

- ❌ No - Add `examples/reports/` to `.gitignore`
- These are generated outputs, not source code
- Each developer will generate their own

**Typical workflow**:

1. Create dataset with test cases
2. Run evaluation
3. Generate report
4. Review metrics
5. Adjust agent configuration
6. Re-evaluate to measure improvement
7. Reports folder stores before/after comparisons

Let me add it to gitignore now.
