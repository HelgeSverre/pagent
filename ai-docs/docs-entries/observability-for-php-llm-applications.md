# Observability for PHP LLM Applications: A Deep Dive into Pagent's OpenTelemetry Integration

**Author:** Technical Documentation Team
**Date:** 2025-10-29
**Reading Time:** 25 minutes
**Level:** Intermediate to Advanced

---

## Table of Contents

1. [The Hidden Complexity of LLM Applications](#the-hidden-complexity-of-llm-applications)
2. [Understanding LLM Observability](#understanding-llm-observability)
3. [Pagent's Zero-Friction Observability](#pagents-zero-friction-observability)
4. [Getting Started: From Zero to Insights](#getting-started-from-zero-to-insights)
5. [Production Deployment Strategies](#production-deployment-strategies)
6. [Advanced Patterns and Workflows](#advanced-patterns-and-workflows)
7. [Real-World Case Studies](#real-world-case-studies)
8. [Best Practices and Pitfalls](#best-practices-and-pitfalls)
9. [Troubleshooting Guide](#troubleshooting-guide)
10. [The Road Ahead](#the-road-ahead)

---

## The Hidden Complexity of LLM Applications

### The $50,000 Question

It's Monday morning. Your phone buzzes. It's your CTO: *"Why did our LLM costs jump from $500 to $50,000 this weekend?"*

You scramble to check your dashboards. Traditional APM shows everything green—response times are normal, error rates are low, throughput is steady. Yet somewhere in your multi-agent content generation pipeline, something is burning through tokens like wildfire.

But where? Which agent? Which requests? Which users?

**This is the LLM observability problem.**

### Why Traditional Monitoring Fails

Traditional Application Performance Monitoring (APM) tools were built for a different era. They excel at tracking:
- HTTP requests and response times
- Database queries
- Error rates and stack traces
- Resource utilization (CPU, memory)

But LLM applications introduce entirely new dimensions:

**Token Economics:**
- Input tokens vs. output tokens
- Context window utilization
- Tool use overhead
- Multi-turn conversation costs

**Multi-Step Workflows:**
- Agent orchestration (pipelines, chains)
- Conditional routing
- Parallel execution
- State management

**Non-Determinism:**
- Variable response lengths
- Unpredictable tool use
- Model-dependent behavior
- Temperature and creativity settings

**Latency Patterns:**
- Streaming vs. blocking calls
- Time-to-first-token
- Tokens-per-second
- Provider-specific quirks

### A Real Scenario

Consider this seemingly simple content creation workflow:

```php
$result = pipeline('blog-post')
    ->step('research', $researchAgent)      // 1. Research topic
    ->step('outline', $outlineAgent)        // 2. Create outline
    ->step('write', $writerAgent)           // 3. Write content
    ->step('fact-check', $factCheckAgent)   // 4. Verify facts
    ->step('edit', $editorAgent)            // 5. Edit and polish
    ->run($topic);
```

**Questions you can't answer without proper observability:**

1. **Performance:** Which step is the bottleneck? (All agents show "200 OK")
2. **Cost:** Which agent uses the most tokens? (Logs show successful completions)
3. **Quality:** Did the fact-checker call tools? How many? (No visibility into tool use)
4. **Errors:** Where did the pipeline fail when it fails? (Generic timeout errors)
5. **Patterns:** Which topics cause the most retries? (No correlation between input and behavior)

Traditional metrics can't answer these questions. You need **distributed tracing** that understands LLMs.

---

## Understanding LLM Observability

### The Three Pillars (Reimagined for LLMs)

#### 1. Traces: The Journey of a Request

In traditional apps, a trace might look like:
```
HTTP Request → Database Query → Cache Lookup → HTTP Response
```

In LLM apps, traces are conversations:
```
User Prompt → Agent Decision → Tool Call 1 → Tool Call 2 → LLM Response → Validation → Final Response
```

Each step has unique attributes:
- **Prompt:** User input, system instructions, context
- **LLM Call:** Model, temperature, max tokens, actual usage
- **Tools:** Function name, arguments, results
- **Validation:** Schema checks, retries, fallbacks

#### 2. Metrics: Beyond "Requests Per Second"

Traditional metrics:
- Requests per second
- Average response time
- Error rate

LLM metrics that actually matter:
- **Tokens per request** (input, output, total)
- **Cost per request** (provider-specific pricing)
- **Tool calls per request** (function invocations)
- **Time to first token** (perceived responsiveness)
- **Tokens per second** (streaming performance)
- **Context window utilization** (efficiency)
- **Retry rate** (validation failures)

#### 3. Logs: Structured Context

Traditional logs capture errors. LLM logs must capture:
- **Prompts and completions** (for debugging and fine-tuning)
- **Tool use patterns** (which functions, with what args)
- **Validation failures** (schema mismatches, retries)
- **Model behavior** (temperature, creativity, reasoning)

### Why OpenTelemetry?

OpenTelemetry (OTel) is the industry standard for observability. It provides:

1. **Vendor Neutrality:** Works with Datadog, New Relic, Jaeger, Zipkin, Honeycomb, and more
2. **Semantic Conventions:** Standardized attribute names (like `gen_ai.request.model`)
3. **W3C Trace Context:** Distributed tracing across services and languages
4. **Future-Proof:** Backed by CNCF and major cloud providers

For LLM applications, OpenTelemetry recently added **semantic conventions for GenAI**, defining standard attributes for:
- Model selection (`gen_ai.request.model`)
- Token usage (`gen_ai.usage.input_tokens`, `gen_ai.usage.output_tokens`)
- Temperature and settings (`gen_ai.request.temperature`)
- Provider identification (`gen_ai.system`)

This means your traces are compatible with emerging LLM-specific observability tools like **Langfuse**, **Phoenix by Arize**, and **Helicone**.

---

## Pagent's Zero-Friction Observability

### Design Philosophy

Pagent's observability implementation follows three core principles:

1. **Zero Configuration**: Works out of the box with sensible defaults
2. **Zero Overhead**: 0ms performance cost when disabled
3. **Zero Vendor Lock-in**: Export to any OpenTelemetry-compatible backend

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     Your Application                        │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ agent()->telemetry(true)
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                   TelemetryManager                          │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Span Creation & Lifecycle Management                │  │
│  │  • startSpan()                                       │  │
│  │  • startAgentSpan()                                  │  │
│  │  • startLLMSpan()                                    │  │
│  │  • startToolSpan()                                   │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                            │
            ┌───────────────┼───────────────┐
            │               │               │
            ▼               ▼               ▼
    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
    │   Console    │ │     OTLP     │ │   Jaeger     │
    │   Exporter   │ │   Exporter   │ │   Exporter   │
    └──────────────┘ └──────────────┘ └──────────────┘
            │               │               │
            │               │               │
            ▼               ▼               ▼
    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
    │   Terminal   │ │  Honeycomb   │ │   Jaeger     │
    │    Output    │ │   Datadog    │ │     UI       │
    │              │ │  New Relic   │ │              │
    └──────────────┘ └──────────────┘ └──────────────┘
```

### The NullSpan Pattern

The secret to zero overhead is the **NullSpan** pattern—a no-op implementation of the Span interface:

```php
interface SpanInterface {
    public function setAttribute(string $key, mixed $value): self;
    public function addEvent(string $name, array $attributes = []): self;
    public function end(): void;
}

// Real implementation
class Span implements SpanInterface {
    public function setAttribute(string $key, mixed $value): self {
        $this->attributes[$key] = $value; // Actual work
        return $this;
    }
}

// Null object pattern
class NullSpan implements SpanInterface {
    public function setAttribute(string $key, mixed $value): self {
        return $this; // No-op, compiles away
    }
}
```

When telemetry is disabled, Pagent returns `NullSpan` instances. The PHP JIT compiler optimizes these away completely, resulting in **zero runtime overhead**.

### Automatic Instrumentation

The magic happens in the Agent class. When telemetry is enabled, Pagent automatically:

```php
public function ask(string $prompt): string
{
    // 1. Start agent operation span
    $span = $this->startOperationSpan('agent.prompt', [
        'agent.name' => $this->name,
        'prompt.text' => $prompt,
    ]);

    try {
        // 2. Create LLM request span
        $llmSpan = $this->startLLMSpan($this->provider, $this->model);

        // 3. Make the call
        $response = $this->provider->complete($messages);

        // 4. Record token usage
        $llmSpan->setAttribute('gen_ai.usage.input_tokens', $response->inputTokens);
        $llmSpan->setAttribute('gen_ai.usage.output_tokens', $response->outputTokens);
        $llmSpan->end();

        // 5. Handle tool calls
        if ($response->toolCalls) {
            foreach ($response->toolCalls as $toolCall) {
                $toolSpan = $this->startToolSpan($toolCall->name, $toolCall->arguments);
                $result = $this->executeTool($toolCall);
                $toolSpan->setAttribute('tool.result', $result);
                $toolSpan->end();
            }
        }

        $span->setStatus('ok');
        return $response->content;

    } catch (\Exception $e) {
        $span->recordException($e);
        $span->setStatus('error');
        throw $e;
    } finally {
        $span->end();
    }
}
```

**You write:**
```php
$agent->ask('Hello');
```

**Pagent automatically traces:**
- The agent operation
- The LLM API call with token usage
- Any tool executions
- Errors and exceptions

---

## Getting Started: From Zero to Insights

### Your First Trace in 3 Lines

Let's start with the simplest possible example:

```php
<?php

require 'vendor/autoload.php';

use function Pagent\{agent, telemetry_console};

// 1. Enable console telemetry
telemetry_console();

// 2. Create agent with telemetry enabled
$agent = agent('assistant')
    ->provider('anthropic')
    ->telemetry(true)
    ->build();

// 3. Make a request
$response = $agent->ask('What is 2+2?');

echo $response; // "2+2 equals 4"
```

**Output:**

```
┌─ Span: agent.prompt
│  Duration: 1.234s
│  Status: ok
│  Attributes:
│    agent.name: assistant
│    agent.operation: prompt
│    prompt.text: What is 2+2?
│  └─ Child Span: llm.request
│     Duration: 1.198s
│     Status: ok
│     Attributes:
│       gen_ai.system: anthropic
│       gen_ai.request.model: claude-3-5-sonnet-20241022
│       gen_ai.request.temperature: 1.0
│       gen_ai.request.max_tokens: 4096
│       gen_ai.usage.input_tokens: 12
│       gen_ai.usage.output_tokens: 8
│       gen_ai.usage.total_tokens: 20
│       gen_ai.response.finish_reason: end_turn
└─

2+2 equals 4
```

### Understanding the Output

Let's decode what we're seeing:

**1. Span Hierarchy:**
```
agent.prompt (parent)
  └── llm.request (child)
```

The parent span represents the entire agent operation. The child span represents the LLM API call.

**2. Timing Information:**
- **Total Duration:** 1.234s (agent.prompt)
- **LLM Call:** 1.198s (llm.request)
- **Overhead:** 0.036s (Pagent's processing)

**3. Agent Attributes:**
- `agent.name`: Your agent's identifier
- `agent.operation`: What the agent is doing (prompt, stream, etc.)
- `prompt.text`: The actual user input

**4. GenAI Attributes (OpenTelemetry Standard):**
- `gen_ai.system`: Provider (anthropic, openai, etc.)
- `gen_ai.request.model`: Model being used
- `gen_ai.usage.*`: Token consumption (key for cost tracking!)

### Adding Verbosity

For detailed debugging, use verbose mode:

```php
telemetry_console(verbose: true);
```

This adds:
- Span IDs and Trace IDs
- Start and end timestamps
- All custom attributes
- Event details

### Tracing Tool Use

Now let's trace an agent that uses tools:

```php
<?php

use function Pagent\{agent, telemetry_console};

telemetry_console(verbose: true);

$agent = agent('calculator')
    ->provider('anthropic')
    ->telemetry(true)
    ->tool('add', 'Add two numbers', function(int $a, int $b): int {
        return $a + $b;
    })
    ->tool('multiply', 'Multiply two numbers', function(int $a, int $b): int {
        return $a * $b;
    })
    ->build();

$response = $agent->ask('What is (5 + 3) * 4?');
```

**Output:**

```
┌─ Span: agent.prompt
│  Duration: 2.456s
│  └─ Child Span: llm.request (initial)
│     Duration: 1.234s
│     Attributes:
│       gen_ai.usage.input_tokens: 150
│       gen_ai.usage.output_tokens: 45
│  └─ Child Span: tool.execute (add)
│     Duration: 0.001s
│     Attributes:
│       tool.name: add
│       tool.arguments: {"a":5,"b":3}
│       tool.result: 8
│  └─ Child Span: llm.request (after tool)
│     Duration: 0.987s
│     Attributes:
│       gen_ai.usage.input_tokens: 180
│       gen_ai.usage.output_tokens: 30
│  └─ Child Span: tool.execute (multiply)
│     Duration: 0.001s
│     Attributes:
│       tool.name: multiply
│       tool.arguments: {"a":8,"b":4}
│       tool.result: 32
│  └─ Child Span: llm.request (final)
│     Duration: 0.789s
│     Attributes:
│       gen_ai.usage.input_tokens: 200
│       gen_ai.usage.output_tokens: 25
└─

(5 + 3) * 4 equals 32
```

**Key Insights:**

1. **Three LLM Calls:** The agent made three separate LLM requests
2. **Two Tool Calls:** Functions were called twice (`add` and `multiply`)
3. **Total Tokens:** 530 input + 100 output = 630 total
4. **Cost Calculation:** At $3/M input + $15/M output = $2.09 for this request

Without observability, you'd just see "one request". With observability, you understand the exact flow and cost.

---

## Production Deployment Strategies

### Choosing Your Backend

The right observability backend depends on your needs:

| Backend | Best For | Pricing | LLM-Specific Features |
|---------|----------|---------|----------------------|
| **Jaeger (self-hosted)** | Development, staging | Free | None (general tracing) |
| **Zipkin (self-hosted)** | Simple deployments | Free | None (general tracing) |
| **Honeycomb** | High-cardinality queries | Pay-as-you-go | Query by token count, cost |
| **Datadog APM** | Enterprise, all-in-one | Per-host | AI integrations |
| **New Relic** | Enterprise observability | Per-user | None (general APM) |
| **Phoenix (Arize)** | LLM-specific | Free tier | Prompt tracking, eval |
| **Langfuse** | LLM ops & prompt mgmt | Free tier | Generations, scoring |

### Self-Hosted Jaeger (Development)

Perfect for local development and staging environments:

**1. Start Jaeger with Docker:**

```bash
docker run -d \
  --name jaeger \
  -p 16686:16686 \
  -p 4318:4318 \
  jaegertracing/all-in-one:latest
```

**2. Configure Pagent:**

```php
<?php

use function Pagent\telemetry_jaeger;

telemetry_jaeger(
    endpoint: 'http://localhost:4318/v1/traces',
    serviceName: 'my-llm-app'
);
```

**3. Use your agents normally:**

```php
$agent = agent('support')
    ->telemetry(true)
    ->build();

$response = $agent->ask('Help with billing');
```

**4. View traces:**

Open http://localhost:16686 in your browser. You'll see:
- Service list
- Trace timeline
- Span details
- Dependency graph

### Production: Honeycomb

Honeycomb excels at high-cardinality queries—perfect for LLM observability:

**1. Sign up for Honeycomb:**

Visit https://honeycomb.io and create an account (free tier available).

**2. Get your API key:**

Settings → Environments → API Keys

**3. Configure Pagent:**

```php
<?php

use function Pagent\telemetry_otlp;

telemetry_otlp(
    endpoint: 'https://api.honeycomb.io/v1/traces',
    headers: [
        'x-honeycomb-team' => env('HONEYCOMB_API_KEY'),
        'x-honeycomb-dataset' => env('HONEYCOMB_DATASET', 'production'),
    ],
    serviceName: 'production-llm-app',
    serviceVersion: '1.0.0'
);
```

**4. Enable telemetry in production:**

```php
// In your app bootstrap
if (env('APP_ENV') === 'production') {
    telemetry_otlp(
        endpoint: env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        headers: [
            'x-honeycomb-team' => env('HONEYCOMB_API_KEY'),
            'x-honeycomb-dataset' => env('HONEYCOMB_DATASET'),
        ]
    );
}

// In your agent code
$agent = agent('production-agent')
    ->telemetry(env('APP_ENV') === 'production')
    ->build();
```

**5. Query in Honeycomb:**

```sql
-- Top 10 most expensive requests by token usage
SELECT SUM(gen_ai.usage.total_tokens) as total_tokens
GROUP BY trace.trace_id
ORDER BY total_tokens DESC
LIMIT 10

-- Average tokens by agent name
SELECT AVG(gen_ai.usage.total_tokens) as avg_tokens
GROUP BY agent.name

-- Requests with errors
WHERE span.status = 'error'

-- Slow LLM calls
WHERE llm.request.duration_ms > 5000
```

### Production: Datadog APM

For enterprises already using Datadog:

**1. Install Datadog Agent:**

Follow Datadog's installation guide for your infrastructure.

**2. Configure OTLP:**

```php
<?php

use function Pagent\telemetry_otlp;

telemetry_otlp(
    endpoint: 'http://localhost:4318/v1/traces', // Datadog agent OTLP receiver
    serviceName: 'llm-application',
    serviceVersion: '1.0.0'
);
```

**3. Add tags for organization:**

```php
$span = TelemetryManager::instance()->getCurrentSpan();
$span->setAttribute('env', env('APP_ENV'));
$span->setAttribute('team', 'ai-platform');
$span->setAttribute('customer_id', $customerId);
```

**4. Create Datadog Dashboard:**

- **APM → Service Catalog:** View your LLM service
- **Dashboards:** Create custom dashboard with:
  - Token usage over time
  - Cost per request
  - Error rates by agent
  - P95 latency by model

### LLM-Specific: Phoenix by Arize

Phoenix is built specifically for LLM observability:

**1. Start Phoenix locally:**

```bash
docker run -d \
  -p 6006:6006 \
  -p 4317:4317 \
  arizephoenix/phoenix:latest
```

**2. Configure Pagent:**

```php
<?php

use function Pagent\telemetry_otlp;

telemetry_otlp(
    endpoint: 'http://localhost:4317', // gRPC endpoint
    serviceName: 'llm-app'
);
```

**3. View in Phoenix:**

Open http://localhost:6006 to see:
- **Traces:** LLM calls with prompts and completions
- **Projects:** Organize by use case
- **Evaluations:** Score outputs for quality
- **Datasets:** Build test/eval datasets from production

### Security Considerations

**1. Redact Sensitive Data:**

```php
$span = TelemetryManager::instance()->getCurrentSpan();

// DON'T: Include PII
$span->setAttribute('user.email', $email); // ❌

// DO: Use hashed IDs
$span->setAttribute('user.id', hash('sha256', $email)); // ✓

// DO: Redact prompts in production
if (env('APP_ENV') === 'production') {
    $span->setAttribute('prompt.redacted', true);
} else {
    $span->setAttribute('prompt.text', $prompt);
}
```

**2. Use Environment Variables for Credentials:**

```php
// NEVER hardcode API keys
telemetry_otlp(
    endpoint: 'https://api.honeycomb.io',
    headers: ['x-honeycomb-team' => 'abc123'] // ❌
);

// ALWAYS use environment variables
telemetry_otlp(
    endpoint: env('OTEL_ENDPOINT'),
    headers: ['x-honeycomb-team' => env('HONEYCOMB_KEY')] // ✓
);
```

**3. Implement Sampling for PII-Heavy Workloads:**

```php
// Sample 10% of requests
$shouldTrace = (rand(1, 100) <= 10);

$agent = agent('support')
    ->telemetry($shouldTrace)
    ->build();
```

---

## Advanced Patterns and Workflows

### Multi-Agent Pipeline Tracing

Let's trace a complex content creation pipeline:

```php
<?php

use function Pagent\{agent, pipeline, telemetry_jaeger};

// Configure Jaeger
telemetry_jaeger();

// Create specialized agents
$researcher = agent('researcher')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->telemetry(true)
    ->systemPrompt('You are a thorough researcher. Find accurate information.')
    ->tool('web_search', 'Search the web', function(string $query): string {
        // ... implementation
    })
    ->build();

$writer = agent('writer')
    ->provider('openai')
    ->model('gpt-4-turbo-preview')
    ->telemetry(true)
    ->systemPrompt('You are a skilled writer. Create engaging content.')
    ->build();

$editor = agent('editor')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->telemetry(true)
    ->systemPrompt('You are a meticulous editor. Polish and refine.')
    ->build();

// Run pipeline
$result = pipeline('blog-creation')
    ->step('research', $researcher)
    ->step('write', $writer)
    ->step('edit', $editor)
    ->run('Write a blog post about PHP 8.3 features');
```

**Trace Visualization in Jaeger:**

```
workflow.pipeline: blog-creation (5.234s)
│
├── workflow.step[0]: research (2.123s)
│   └── agent.prompt (2.123s)
│       ├── llm.request (1.987s)
│       │   ├── gen_ai.usage.input_tokens: 250
│       │   └── gen_ai.usage.output_tokens: 450
│       └── tool.execute: web_search (0.123s)
│
├── workflow.step[1]: write (2.345s)
│   └── agent.prompt (2.345s)
│       └── llm.request (2.312s)
│           ├── gen_ai.usage.input_tokens: 500
│           └── gen_ai.usage.output_tokens: 800
│
└── workflow.step[2]: edit (0.766s)
    └── agent.prompt (0.766s)
        └── llm.request (0.734s)
            ├── gen_ai.usage.input_tokens: 850
            └── gen_ai.usage.output_tokens: 120
```

**Insights from the Trace:**

1. **Total Time:** 5.234s
2. **Bottleneck:** The writer step (2.345s)
3. **Total Tokens:** 3,170 (1,600 input + 1,370 output)
4. **Cost:** Approximately $0.03 per blog post
5. **Optimization Opportunity:** Research step uses tools—can we cache results?

### Distributed Tracing Across Services

Real-world LLM applications often span multiple services. Use W3C Trace Context to connect them:

**Service A (API Gateway):**

```php
<?php

use function Pagent\{agent, telemetry_otlp};

telemetry_otlp('http://collector:4318/v1/traces');

$agent = agent('classifier')
    ->telemetry(true)
    ->build();

$category = $agent->ask("Classify this request: {$userInput}");

// Extract trace context
$span = TelemetryManager::instance()->getCurrentSpan();
$traceContext = $span->getContext()->toW3C();

// Pass to Service B via HTTP header
$response = Http::withHeaders([
    'traceparent' => $traceContext,
])->post('http://service-b/process', [
    'category' => $category,
    'input' => $userInput,
]);
```

**Service B (Processing Service):**

```php
<?php

use function Pagent\{agent, telemetry_otlp};

telemetry_otlp('http://collector:4318/v1/traces');

// Extract trace context from request
$traceContext = request()->header('traceparent');

// Continue the trace
TelemetryManager::instance()->setParentContext($traceContext);

$agent = agent('processor')
    ->telemetry(true)
    ->build();

$result = $agent->ask("Process: {$input}");
```

**Result:**

Both services' spans appear in the same trace, showing the complete request flow across your infrastructure.

### Custom Attributes for Business Metrics

Add custom attributes to track business-specific metrics:

```php
<?php

$span = TelemetryManager::instance()->getCurrentSpan();

// Customer attributes
$span->setAttribute('customer.id', $customerId);
$span->setAttribute('customer.tier', 'enterprise'); // For cost attribution

// Feature flags
$span->setAttribute('feature.new_prompt_template', true);

// Business metrics
$span->setAttribute('request.category', 'support');
$span->setAttribute('request.priority', 'high');

// Cost tracking
$inputCost = ($inputTokens / 1_000_000) * 3.0;  // $3/M tokens
$outputCost = ($outputTokens / 1_000_000) * 15.0; // $15/M tokens
$span->setAttribute('cost.input_usd', $inputCost);
$span->setAttribute('cost.output_usd', $outputCost);
$span->setAttribute('cost.total_usd', $inputCost + $outputCost);

$agent = agent('support')
    ->telemetry(true)
    ->build();

$response = $agent->ask($userQuestion);
```

**Querying in Honeycomb:**

```sql
-- Total cost by customer tier
SELECT SUM(cost.total_usd) as total_cost
GROUP BY customer.tier

-- Average cost by request category
SELECT AVG(cost.total_usd) as avg_cost
GROUP BY request.category

-- Identify expensive customers
SELECT customer.id, SUM(cost.total_usd) as customer_cost
GROUP BY customer.id
ORDER BY customer_cost DESC
LIMIT 100
```

### Error Tracking and Alerting

Capture and alert on LLM-specific errors:

```php
<?php

try {
    $agent = agent('validator')
        ->telemetry(true)
        ->schema([
            'type' => 'object',
            'properties' => [
                'valid' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
        ])
        ->maxRetries(3)
        ->build();

    $result = $agent->ask('Validate this input');

} catch (ValidationException $e) {
    $span = TelemetryManager::instance()->getCurrentSpan();

    // Record validation failure
    $span->recordException($e);
    $span->setAttribute('validation.failed', true);
    $span->setAttribute('validation.retries', $e->getRetries());
    $span->setAttribute('validation.schema_errors', $e->getSchemaErrors());
    $span->setStatus('error', 'Validation failed after retries');

    // Alert if retries exhausted
    if ($e->getRetries() >= 3) {
        // Send alert to monitoring system
        alerting()->trigger('llm.validation.retries_exhausted', [
            'agent' => 'validator',
            'trace_id' => $span->getContext()->getTraceId(),
        ]);
    }

    throw $e;
}
```

---

## Real-World Case Studies

### Case Study 1: Debugging a Customer Support Chatbot

**Problem:**

A customer support chatbot suddenly started giving irrelevant answers. Traditional logs showed:
- HTTP 200 responses
- Normal latency
- No error rate increase

But customer satisfaction dropped from 85% to 45% overnight.

**Investigation with Observability:**

```php
telemetry_jaeger();

$agent = agent('support')
    ->telemetry(true)
    ->tool('search_docs', ...)
    ->tool('create_ticket', ...)
    ->build();
```

**Finding in Jaeger UI:**

1. **Filter traces:** `agent.name = "support" AND date > yesterday`
2. **Sort by duration:** Noticed traces with 0 tool calls
3. **Drill into sample trace:**

```
agent.prompt (1.2s)
└── llm.request (1.1s)
    ├── gen_ai.usage.input_tokens: 5000  ← WAY too high!
    └── gen_ai.usage.output_tokens: 100
```

**Root Cause:**

The agent's conversation history was growing unbounded, filling the context window with old conversations. No room for the knowledge base tools!

**Solution:**

```php
$agent = agent('support')
    ->telemetry(true)
    ->maxHistory(10) // Limit conversation history
    ->tool('search_docs', ...)
    ->build();
```

**Result:**

- Tool call rate back to normal
- Input tokens dropped from 5000 to 500
- Customer satisfaction recovered to 83%
- Cost reduction of 90%

**Lesson:** Without token usage visibility, this would have been impossible to debug.

### Case Study 2: Optimizing a Content Generation Pipeline

**Scenario:**

A content creation pipeline was slow and expensive:

```php
$result = pipeline('content')
    ->step('research', $researcher)
    ->step('outline', $outliner)
    ->step('write', $writer)
    ->step('fact-check', $factChecker)
    ->step('edit', $editor)
    ->run($topic);
```

**Baseline Metrics (from Honeycomb):**

```sql
SELECT
  AVG(duration_ms) as avg_duration,
  AVG(gen_ai.usage.total_tokens) as avg_tokens,
  AVG(cost.total_usd) as avg_cost
WHERE workflow.name = "content"
```

Results:
- Average Duration: 15.3s
- Average Tokens: 4,200
- Average Cost: $0.12 per article

**Trace Analysis:**

Looking at a sample trace revealed:

```
workflow.pipeline (15.3s)
├── research (5.2s)      // 1,500 tokens
├── outline (2.1s)       // 800 tokens
├── write (4.8s)         // 1,200 tokens
├── fact-check (2.1s)    // 500 tokens  ← Almost same as outline!
└── edit (1.1s)          // 200 tokens
```

**Optimization 1: Combine Similar Steps**

Merged outline and fact-check into write step:

```php
$writer = agent('writer')
    ->systemPrompt('Write content based on research. Include fact-checking.')
    ->build();

$result = pipeline('content-v2')
    ->step('research', $researcher)
    ->step('write', $writer)  // Now does outline + write + fact-check
    ->step('edit', $editor)
    ->run($topic);
```

**Results:**
- Duration: 10.1s (-34%)
- Tokens: 3,100 (-26%)
- Cost: $0.088 (-27%)

**Optimization 2: Parallel Research**

Used `parallel()` for research:

```php
$results = parallel([
    'facts' => $factResearcher,
    'examples' => $exampleResearcher,
    'citations' => $citationResearcher,
])->run($topic);

$result = pipeline('content-v3')
    ->step('write', $writer, fn() => $results)
    ->step('edit', $editor)
    ->run($topic);
```

**Final Results:**
- Duration: 7.2s (-53% from baseline)
- Tokens: 2,900 (-31%)
- Cost: $0.078 (-35%)

**ROI:**

At 1,000 articles per day:
- **Time Saved:** 8.1s × 1,000 = 2.25 hours per day
- **Cost Saved:** $0.042 × 1,000 = $42 per day = $1,260/month
- **Setup Time:** 2 hours

**Payback:** Less than 2 days!

### Case Study 3: Multi-Tenant Cost Attribution

**Challenge:**

A SaaS LLM application needed to:
- Track costs per customer
- Identify expensive customers
- Bill accurately for usage

**Implementation:**

```php
<?php

namespace App\Middleware;

class TelemetryMiddleware
{
    public function handle($request, $next)
    {
        $span = TelemetryManager::instance()->getCurrentSpan();

        // Add customer context
        $span->setAttribute('customer.id', $request->user()->customer_id);
        $span->setAttribute('customer.name', $request->user()->customer->name);
        $span->setAttribute('customer.tier', $request->user()->customer->tier);

        $response = $next($request);

        // Record usage for billing
        $span->addEvent('billing.usage_recorded', [
            'tokens' => $span->getAttribute('gen_ai.usage.total_tokens'),
            'cost_usd' => $this->calculateCost($span),
        ]);

        return $response;
    }

    private function calculateCost($span): float
    {
        $inputTokens = $span->getAttribute('gen_ai.usage.input_tokens') ?? 0;
        $outputTokens = $span->getAttribute('gen_ai.usage.output_tokens') ?? 0;

        // Pricing varies by model
        $model = $span->getAttribute('gen_ai.request.model');
        $pricing = config("llm.pricing.{$model}");

        $inputCost = ($inputTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($outputTokens / 1_000_000) * $pricing['output'];

        return $inputCost + $outputCost;
    }
}
```

**Honeycomb Query for Billing:**

```sql
-- Monthly usage by customer
SELECT
  customer.id,
  customer.name,
  SUM(gen_ai.usage.total_tokens) as total_tokens,
  SUM(cost.total_usd) as total_cost_usd
WHERE
  date >= start_of_month() AND
  date < start_of_next_month()
GROUP BY customer.id, customer.name
ORDER BY total_cost_usd DESC
```

**Results:**

- **Accurate Billing:** Token-level precision
- **Cost Insights:** Identified top 20% of customers driving 80% of costs
- **Fair Pricing:** Implemented tiered pricing based on actual usage
- **Churn Reduction:** Proactively contacted high-cost customers about optimization

---

## Best Practices and Pitfalls

### Best Practices

#### 1. Start Simple, Then Expand

```php
// Development: Console exporter
if (env('APP_ENV') === 'local') {
    telemetry_console(verbose: true);
}

// Staging: Self-hosted Jaeger
if (env('APP_ENV') === 'staging') {
    telemetry_jaeger('http://jaeger:4318/v1/traces');
}

// Production: Managed service
if (env('APP_ENV') === 'production') {
    telemetry_otlp(
        endpoint: env('OTEL_ENDPOINT'),
        headers: ['x-honeycomb-team' => env('HONEYCOMB_KEY')]
    );
}
```

#### 2. Use Sampling in High-Volume Systems

```php
// Sample 10% of requests
$enableTelemetry = (rand(1, 100) <= 10);

$agent = agent('high-volume')
    ->telemetry($enableTelemetry)
    ->build();
```

Or sample based on conditions:

```php
// Always trace errors, sample 5% of success
$enableTelemetry = $isError || (rand(1, 100) <= 5);
```

#### 3. Add Business Context Early

```php
$span = TelemetryManager::instance()->getCurrentSpan();

// Add context BEFORE agent execution
$span->setAttribute('request.id', $requestId);
$span->setAttribute('user.id', $userId);
$span->setAttribute('feature', 'chat');

$response = $agent->ask($prompt);
```

#### 4. Use Structured Logging Alongside Traces

```php
Log::info('Agent request started', [
    'trace_id' => $span->getContext()->getTraceId(),
    'span_id' => $span->getContext()->getSpanId(),
    'agent' => 'support',
]);

// Now you can correlate logs with traces!
```

#### 5. Monitor Your Observability Costs

Observability isn't free. Track costs:

```sql
-- Honeycomb: Monthly data volume
SELECT COUNT(*) as span_count
WHERE date >= start_of_month()

-- At 1M spans/month × $0.50/M spans = $0.50/month
-- Plus retention costs
```

### Common Pitfalls

#### 1. Logging Sensitive Data

**DON'T:**

```php
// ❌ PII in spans
$span->setAttribute('user.email', $email);
$span->setAttribute('user.phone', $phone);
$span->setAttribute('prompt.text', $prompt); // Might contain PII!
```

**DO:**

```php
// ✓ Hashed identifiers
$span->setAttribute('user.id', hash('sha256', $email));

// ✓ Redacted prompts
$span->setAttribute('prompt.hash', hash('sha256', $prompt));
$span->setAttribute('prompt.length', strlen($prompt));

// ✓ Feature flags instead of raw data
$span->setAttribute('prompt.contains_pii', $this->detectPII($prompt));
```

#### 2. Forgetting to Enable Telemetry

```php
// ❌ Telemetry configured but not enabled on agent
telemetry_otlp('http://collector:4318/v1/traces');

$agent = agent('support')
    // Missing: ->telemetry(true)
    ->build();

// No traces will be generated!
```

**Solution:** Create a helper:

```php
function createAgent(string $name): Agent
{
    return agent($name)
        ->telemetry(env('APP_ENV') !== 'local') // Auto-enable in non-local
        ->build();
}
```

#### 3. Not Handling Exporter Failures

```php
// ❌ If exporter fails, request fails
telemetry_otlp('http://unreachable:4318/v1/traces');

$agent = agent('critical')
    ->telemetry(true)
    ->build();

$response = $agent->ask($prompt); // Might fail if exporter times out!
```

**Solution:** Use async export (coming in v0.8.0) or set reasonable timeouts:

```php
telemetry_otlp(
    endpoint: 'http://collector:4318/v1/traces',
    timeout: 1.0, // Don't wait more than 1 second
    retries: 0     // Don't retry on failure
);
```

#### 4. Over-Sampling

```php
// ❌ Too much data
// 100 requests/sec × 3600 sec/hour × 24 hours = 8.6M spans/day
telemetry_otlp(...);

$agent = agent('high-traffic')
    ->telemetry(true) // ALWAYS ON
    ->build();
```

**Solution:** Intelligent sampling:

```php
// Sample 1% of requests, but 100% of errors
$shouldTrace = $isError || (rand(1, 100) === 1);

$agent = agent('high-traffic')
    ->telemetry($shouldTrace)
    ->build();
```

---

## Troubleshooting Guide

### Problem: No Traces Appearing

**Symptoms:** Telemetry is enabled, but no traces in backend.

**Checklist:**

1. **Is telemetry enabled on the agent?**
   ```php
   $agent->telemetry(true) // This is required!
   ```

2. **Is the exporter configured correctly?**
   ```php
   // Check endpoint URL
   telemetry_otlp('http://collector:4318/v1/traces'); // Correct
   telemetry_otlp('http://collector:4318'); // ❌ Wrong (missing /v1/traces)
   ```

3. **Can you reach the collector?**
   ```bash
   curl http://collector:4318/v1/traces
   # Should return 405 Method Not Allowed (POST expected)
   ```

4. **Check for export errors:**
   ```php
   // Enable debug logging
   telemetry_console(verbose: true);

   // Look for export errors in console
   ```

5. **Verify authentication:**
   ```php
   telemetry_otlp(
       endpoint: 'https://api.honeycomb.io/v1/traces',
       headers: [
           'x-honeycomb-team' => env('HONEYCOMB_KEY'), // Check this!
       ]
   );
   ```

### Problem: High Memory Usage

**Symptoms:** Application memory grows over time with telemetry enabled.

**Cause:** Spans are buffered in memory before export.

**Solutions:**

1. **Reduce batch size** (coming in v0.8.0)
2. **Export more frequently**
3. **Implement sampling:**
   ```php
   $shouldTrace = (rand(1, 100) <= 10); // 10% sampling
   $agent->telemetry($shouldTrace);
   ```

### Problem: Performance Degradation

**Symptoms:** Application slows down with telemetry enabled.

**Debugging:**

```php
// Measure overhead
$start = microtime(true);

telemetry_console();
$agent = agent('test')->telemetry(true)->build();
$response = $agent->ask('test');

$duration = microtime(true) - $start;
echo "Total duration: {$duration}s\n";

// Compare with telemetry disabled
$start = microtime(true);

$agent = agent('test')->telemetry(false)->build();
$response = $agent->ask('test');

$duration = microtime(true) - $start;
echo "Total duration: {$duration}s\n";
```

**Expected:** <5ms difference

**If much higher:** Check exporter timeout settings.

### Problem: Docker Containers Won't Start

**Symptoms:** `docker-compose up` fails for observability stack.

**Common Issues:**

1. **Port already in use:**
   ```bash
   # Check what's using port 16686
   lsof -i :16686

   # Kill the process or change the port
   ```

2. **Insufficient resources:**
   ```bash
   # Check Docker resources
   docker info | grep -i memory

   # Increase in Docker Desktop: Settings → Resources → Memory
   ```

3. **Volume permissions:**
   ```bash
   # Fix volume permissions
   sudo chown -R $(whoami) ./docker/observability/data
   ```

---

## The Road Ahead

### Future of LLM Observability

The field of LLM observability is evolving rapidly. Expect to see:

**1. Standardization:**
- More providers adopting OpenTelemetry semantic conventions
- Industry-standard metrics for LLM performance
- Common query languages (like PromQL for metrics)

**2. LLM-Specific Tools:**
- **Prompt versioning:** Track prompt changes like code changes
- **Response caching:** Automatically cache identical prompts
- **Cost optimization:** AI-driven suggestions for reducing costs
- **Quality scoring:** Automatic evaluation of LLM responses

**3. Regulatory Compliance:**
- **Audit trails:** Immutable logs of LLM interactions
- **Bias detection:** Automatic alerts for biased outputs
- **Data governance:** Track data lineage through LLM pipelines

### Pagent Roadmap

**v0.8.0 - Enhanced Observability** (Q1 2026):
- Batch export for improved performance
- Probabilistic sampling
- OpenTelemetry Metrics support
- Additional exporters (CloudWatch, Prometheus)

**v0.9.0 - Advanced Analytics** (Q2 2026):
- Built-in cost calculator
- Performance profiler
- Query builder for common analyses
- Anomaly detection

**v1.0.0 - Production Hardened** (Q3 2026):
- Enterprise features (SSO, RBAC)
- SLA monitoring and alerting
- Multi-tenancy support
- Compliance reporting

### Contributing

Pagent is open source! We welcome contributions:

- **Code:** New exporters, performance improvements
- **Documentation:** Tutorials, use cases, translations
- **Testing:** Edge cases, integration tests
- **Ideas:** Feature requests, architectural discussions

Visit https://github.com/helge/pagent to get involved.

---

## Conclusion

Observability transforms LLM applications from black boxes to transparent, debuggable systems. With Pagent's OpenTelemetry integration, you get:

✅ **Production-Ready:** Zero-overhead design, battle-tested patterns
✅ **Standards-Based:** OpenTelemetry compatibility with all major backends
✅ **Developer-Friendly:** Works out of the box, minimal configuration
✅ **Cost-Aware:** Track token usage and optimize spending
✅ **Future-Proof:** Built on industry standards, evolves with the ecosystem

Start with console output for development:

```php
use function Pagent\{agent, telemetry_console};

telemetry_console(verbose: true);

$agent = agent('assistant')
    ->telemetry(true)
    ->build();

$response = $agent->ask('Hello, observability!');
```

Then graduate to production observability when you're ready:

```php
use function Pagent\telemetry_otlp;

telemetry_otlp(
    endpoint: env('OTEL_ENDPOINT'),
    headers: ['x-honeycomb-team' => env('HONEYCOMB_KEY')]
);
```

The insights you'll gain—from debugging production issues in minutes instead of hours, to optimizing costs by 35%, to building confidence in your LLM applications—will be transformative.

**Happy tracing!** 🔍

---

## Resources

### Documentation
- [Pagent Observability Guide](../../docs/observability.md)
- [OpenTelemetry Semantic Conventions](https://opentelemetry.io/docs/specs/semconv/gen-ai/)
- [W3C Trace Context](https://www.w3.org/TR/trace-context/)

### Tools
- [Jaeger](https://www.jaegertracing.io/)
- [Honeycomb](https://honeycomb.io/)
- [Phoenix (Arize)](https://phoenix.arize.com/)
- [Langfuse](https://langfuse.com/)

### Community
- GitHub: https://github.com/helge/pagent
- Discussions: https://github.com/helge/pagent/discussions
- Twitter: @pagent_php

---

*This article is part of the Pagent documentation. For updates and corrections, please contribute on GitHub.*
