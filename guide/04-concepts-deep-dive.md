# Understanding Pagent: A Conceptual Deep Dive

**Explanation-Oriented Guide**

This guide explores the architecture, design decisions, and core concepts behind Pagent. If you want to understand _why_ things work the way they do, you're in the right place.

---

## Table of Contents

1. [Philosophy & Design Goals](#philosophy--design-goals)
2. [The Agent Registry Pattern](#the-agent-registry-pattern)
3. [Provider Abstraction Layer](#provider-abstraction-layer)
4. [Tool Calling Architecture](#tool-calling-architecture)
5. [Safety Guards System](#safety-guards-system)
6. [Orchestration Patterns](#orchestration-patterns)
7. [Evaluation Framework](#evaluation-framework)

---

## Philosophy & Design Goals

### Pest-Inspired Fluent API

Pagent borrows its ergonomic API design from [Pest](https://pestphp.com/), the elegant PHP testing framework. Instead of verbose configuration objects, you get chainable methods that read like natural language:

```php
agent('reviewer')
    ->provider(anthropic())
    ->system('You are a code reviewer')
    ->model('claude-3-5-sonnet-20241022')
    ->temperature(0.7);
```

**Why this matters:** Developers spend more time _reading_ code than writing it. A fluent API reduces cognitive load and makes intent crystal clear.

### Global State with Purpose

Unlike most modern PHP libraries that avoid global state, Pagent embraces a global agent registry. This is a deliberate choice:

```php
// Define once
agent('assistant')->provider(anthropic());

// Use anywhere in your application
$response = agent('assistant')->prompt('Hello');
```

**The tradeoff:** Global state can make testing harder, but it dramatically simplifies real-world usage. We mitigate testing challenges by providing `clearAgents()` for cleanup and a `Mock` provider for deterministic tests.

### Type Safety Where It Counts

Pagent uses PHP 8.3's type system extensively—strict types, union types, and return type declarations—but doesn't sacrifice developer experience for type purity. For example, response objects use `stdClass` with public properties instead of rigid DTOs because:

1. LLM responses are inherently dynamic
2. Different providers return different metadata
3. Adding new fields shouldn't break your code

---

## The Agent Registry Pattern

### How It Works

When you call `agent('name')`, Pagent checks a global `Registry` class:

```php
// First call: creates an AgentBuilder
$builder = agent('assistant'); // Returns AgentBuilder

// Second call: returns the registered Agent
$agent = agent('assistant'); // Returns Agent
```

### Builder vs. Agent

The `AgentBuilder` is destroyed when it goes out of scope, automatically registering the configured `Agent`:

```php
agent('bot')              // AgentBuilder created
    ->provider(anthropic())
    ->system('...');      // Builder configured
                          // Builder destroyed → Agent registered

agent('bot')->prompt('Hi'); // Retrieves Agent from registry
```

**Why split these?** The builder pattern allows flexible configuration without polluting the `Agent` class with dozens of setter methods. Once built, agents are immutable runtime objects.

### Registry Lifecycle

```
┌─────────────────────────────────────────────┐
│ agent('name')                               │
│   ├─ Registry::has('name')?                 │
│   │   ├─ Yes → Return Agent                 │
│   │   └─ No → Return new AgentBuilder       │
│   │                                          │
│   └─ AgentBuilder.__destruct()              │
│       └─ Registry::register($agent)         │
└─────────────────────────────────────────────┘
```

This ensures agents are:

- **Singleton per name** (no accidental duplicates)
- **Lazy-loaded** (only created when needed)
- **Globally accessible** (use anywhere)

---

## Provider Abstraction Layer

### The Provider Contract

All LLM integrations implement `Pagent\Contracts\Provider`:

```php
interface Provider
{
    public function prompt(string $prompt, array $options = []): object;
}
```

This single method hides enormous complexity:

- HTTP API calls
- Authentication
- Request/response formatting
- Error handling
- Token counting

### Why Abstraction Matters

Consider switching from Anthropic to OpenAI:

```php
// Before
agent('bot')->provider(anthropic());

// After
agent('bot')->provider(openai());
```

Your application code doesn't change. The provider handles:

| Aspect             | Anthropic                | OpenAI                                |
| ------------------ | ------------------------ | ------------------------------------- |
| **Auth Header**    | `x-api-key`              | `Authorization: Bearer`               |
| **Request Format** | `{"messages": [...]}`    | `{"messages": [...], "model": "..."}` |
| **System Prompt**  | Top-level `system` field | Message with `role: "system"`         |
| **Tool Calling**   | `tools` array            | `functions` array                     |

### Provider-Specific Features

Some features are provider-specific and intentionally _not_ abstracted:

```php
// Anthropic's Claude-specific feature
$response = anthropic()->prompt('...', [
    'thinking' => 'enabled', // Claude 3.5's thinking mode
]);
```

**Design principle:** Common operations are abstracted; unique features remain accessible.

---

## Tool Calling Architecture

### The Problem

LLMs can't natively execute code. Tool calling bridges this gap by:

1. Describing available functions to the LLM
2. Letting the LLM request function execution
3. Running the function and returning results

### How Pagent Handles It

When you register a tool:

```php
agent('calc')->tool('add', function (int $a, int $b): int {
    return $a + $b;
});
```

Pagent:

1. **Reflects the callable** to extract parameter names, types, and return types
2. **Generates provider-specific schemas** (Anthropic and OpenAI use different formats)
3. **Validates arguments at runtime** before executing the tool

### Schema Generation

Given this tool:

```php
function weather(string $city, ?string $units = 'metric'): array {
    // Fetch weather...
}
```

Pagent generates:

**For Anthropic:**

```json
{
  "name": "weather",
  "input_schema": {
    "type": "object",
    "properties": {
      "city": { "type": "string" },
      "units": { "type": "string" }
    },
    "required": ["city"]
  }
}
```

**For OpenAI:**

```json
{
  "name": "weather",
  "parameters": {
    "type": "object",
    "properties": {
      "city": { "type": "string" },
      "units": { "type": "string" }
    },
    "required": ["city"]
  }
}
```

### Execution Flow

```
User Prompt
    ↓
LLM decides to call tool
    ↓
Pagent validates arguments
    ↓
Execute callable
    ↓
Return result to LLM
    ↓
LLM generates final response
```

---

## Safety Guards System

### Layered Defense

Guards implement `Pagent\Contracts\Guard`:

```php
interface Guard
{
    public function validate(string $input): bool;
    public function name(): string;
    public function message(): string;
}
```

They run _before_ prompts reach the LLM:

```
User Input
    ↓
Guard 1 (PIIGuard)
    ↓
Guard 2 (ContentFilterGuard)
    ↓
Guard 3 (PromptInjectionGuard)
    ↓
✓ All passed → Send to LLM
```

### Why Pre-Flight Checks?

1. **Cost savings:** Invalid prompts don't waste API credits
2. **Compliance:** PII never leaves your system
3. **Security:** Injection attempts are blocked immediately

### Guard Design

Each guard is self-contained:

```php
class PIIGuard implements Guard
{
    private array $patterns = [
        '/\b\d{3}-\d{2}-\d{4}\b/',  // SSN
        '/\b[\w.%+-]+@[\w.-]+\.[A-Z]{2,}\b/i',  // Email
    ];

    public function validate(string $input): bool
    {
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return false;
            }
        }
        return true;
    }
}
```

**Extensibility:** Add custom guards by implementing the `Guard` interface.

---

## Orchestration Patterns

### Why Multi-Agent Systems?

Complex tasks often exceed a single agent's capabilities. Orchestration patterns solve this by coordinating specialized agents.

### Pattern 1: Pipeline (Sequential Processing)

```php
pipeline('content')
    ->agent('researcher')  // Gathers information
    ->agent('writer')      // Drafts content
    ->agent('editor')      // Polishes output
    ->run('Topic: AI agents');
```

**When to use:** Linear workflows where each step builds on the previous.

### Pattern 2: Handoff (Context Transfer)

```php
$agent1 = agent('support')->prompt('My order is late');
$agent2 = $agent1->handoff()
    ->to('shipping-specialist')
    ->because('Customer needs shipping help')
    ->transfer();
```

**When to use:** Escalation or specialization (e.g., L1 → L2 support).

### Pattern 3: Delegation (Manager-Worker)

```php
agent('manager')->delegate('Research PHP trends')
    ->to('researcher')
    ->supervise(fn($output) => strlen($output) > 100)
    ->execute();
```

**When to use:** Task decomposition where a coordinator oversees workers.

### Design Insight: Shared Context

All patterns preserve conversation history:

```
Agent A: "User prefers JSON responses"
    ↓
[Handoff includes message history]
    ↓
Agent B: "I'll format as JSON based on earlier preference"
```

---

## Evaluation Framework

### The Metrics Problem

How do you measure if "Write a poem" was successful? Metrics provide objective criteria:

```php
evaluate('poet')
    ->dataset($testCases)
    ->metric('keyword', new KeywordMetric(['love', 'heart']))
    ->metric('length', new LengthMetric(min: 50, max: 200))
    ->run();
```

### Metric Types

| Metric         | Purpose                 | Example                       |
| -------------- | ----------------------- | ----------------------------- |
| **Keyword**    | Check presence of terms | Customer support responses    |
| **Length**     | Validate output size    | Tweet generators (≤280 chars) |
| **Similarity** | Compare to reference    | Translation quality           |

### Dataset Abstraction

Datasets can come from multiple sources:

```php
Dataset::fromArray([...]);     // In-memory
Dataset::fromJson('tests.json'); // JSON file
Dataset::fromCsv('tests.csv');   // CSV file
```

**Why this matters:** Test cases should live in version control, not hardcoded in your tests.

### Report Generation

Reports are generated as objects, then exported:

```php
$report = $evaluator->run();
$report->toHtml();  // Interactive dashboard
$report->toJson();  // Machine-readable
$report->toMarkdown(); // Documentation
```

**Design goal:** Evaluation results are first-class data, not just console output.

---

## Conclusion

Pagent's design prioritizes:

1. **Developer ergonomics** over architectural purity
2. **Flexibility** over rigid abstractions
3. **Practical utility** over theoretical completeness

By understanding these concepts, you can extend Pagent to fit your unique requirements—whether that's custom providers, domain-specific guards, or novel orchestration patterns.

---

**Next:** See the [API Reference](05-api-reference.md) for complete technical documentation.
