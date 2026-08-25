# Chapter 22: OpenTelemetry Integration

In previous chapters, we've explored how to build sophisticated agent systems with memory, tools, and orchestration. But once your agent is running in production, how do you understand what's actually happening? How do you debug performance issues, track down errors, or optimize expensive LLM calls?

This is where observability becomes crucial. Pagent provides deep integration with OpenTelemetry, the industry-standard observability framework that lets you instrument, collect, and analyze distributed traces. In this chapter, we'll explore how to enable telemetry, understand automatic instrumentation, and visualize your agent's behavior in tools like Jaeger, Zipkin, and Phoenix.

## Understanding Observability for AI Agents

Traditional application observability focuses on HTTP requests, database queries, and service calls. AI agent observability adds a new dimension: understanding LLM interactions, tool executions, and the complex decision trees that emerge from agent behavior.

OpenTelemetry traces help you answer critical questions:

**Performance Analysis**: Which LLM calls are slowest? How long do tool executions take? Where are the bottlenecks in your agent pipelines?

**Cost Tracking**: How many tokens is each operation consuming? Which prompts are most expensive? Are you hitting rate limits?

**Error Investigation**: When tool calls fail, what was the context? What led to guard violations? Why did the model refuse a request?

**Behavioral Understanding**: What sequence of operations did the agent perform? How many iterations did it take to complete the task? Which tools were actually used?

Pagent automatically instruments all agent operations, creating detailed traces that capture this information without requiring manual instrumentation code.

## Enabling Telemetry

The simplest way to get started is with console telemetry, which outputs traces directly to your terminal:

```php
use function Pagent\telemetry_console;

// Enable console telemetry for debugging
telemetry_console(verbose: false);

$agent = agent('assistant')
    ->provider(anthropic())
    ->telemetry(true)  // Enable telemetry for this agent
    ->build();

$response = $agent->prompt('What is the capital of France?');
```

When this code runs, you'll see trace output in your console:

```
┌─ Span: agent.prompt
│  Duration: 1.234s
└─
┌─ Span: llm.request
│  Duration: 1.201s
└─
```

The `verbose: true` option shows detailed attributes for each span:

```php
telemetry_console(verbose: true);
```

This outputs attributes like model names, token counts, and agent configuration:

```
┌─ Span: llm.request
│  Duration: 1.201s
│  Attributes:
│    - gen_ai.system: anthropic
│    - gen_ai.request.model: claude-sonnet-4-6
│    - gen_ai.request.temperature: 1.0
│    - gen_ai.usage.input_tokens: 45
│    - gen_ai.usage.output_tokens: 12
└─
```

Console telemetry is perfect for local development, but for production systems you'll want to export traces to a dedicated backend.

## Configuring Production Exporters

Pagent supports multiple OpenTelemetry exporters through convenient helper functions. Let's start with OTLP (OpenTelemetry Protocol), which works with most observability backends:

```php
use function Pagent\telemetry_otlp;

// Configure OTLP exporter
telemetry_otlp(
    endpoint: 'http://localhost:4318/v1/traces',
    headers: [],  // Optional: add authentication headers
    serviceName: 'my-production-agent'
);

$agent = agent('customer-support')
    ->provider(anthropic())
    ->telemetry(true)
    ->build();
```

The OTLP exporter works with any OpenTelemetry-compatible backend, including Jaeger, Grafana Tempo, Honeycomb, and cloud providers like AWS X-Ray.

For Jaeger specifically, there's a dedicated helper:

```php
use function Pagent\telemetry_jaeger;

telemetry_jaeger(
    endpoint: 'http://localhost:4318/v1/traces',
    serviceName: 'my-agent-system'
);
```

Similarly, for Zipkin:

```php
use function Pagent\telemetry_zipkin;

telemetry_zipkin(
    endpoint: 'http://localhost:9411/api/v2/spans',
    serviceName: 'my-agent-system'
);
```

These convenience functions handle all the configuration details, letting you focus on your agent logic rather than observability plumbing.

## Advanced Configuration

For more control, use the lower-level `telemetry()` function:

```php
use function Pagent\telemetry;

telemetry([
    'enabled' => true,
    'service_name' => 'my-app',
    'service_version' => '1.0.0',
    'exporter' => 'otlp',
    'sampling_rate' => 1.0,  // Sample 100% of traces (default)
    'otlp' => [
        'endpoint' => 'http://localhost:4318/v1/traces',
        'headers' => [
            'Authorization' => 'Bearer ' . getenv('OTLP_TOKEN'),
        ],
        'timeout' => 10.0,  // Connection timeout in seconds
        'compression' => null,  // Optional: 'gzip' for compression
    ],
]);
```

This gives you fine-grained control over service identification, sampling rates, and exporter configuration.

## Automatic Instrumentation

Once telemetry is enabled, Pagent automatically creates spans for all major operations. You don't need to manually instrument your code - just enable telemetry on your agent and spans will be created automatically.

Here's what gets instrumented:

**Agent Operations**: Every `prompt()`, `stream()`, and `continue()` call creates an `agent.prompt` or `agent.stream` span. This tracks the overall operation from start to finish.

**LLM Requests**: API calls to your LLM provider create `llm.request` spans with detailed attributes following the OpenTelemetry GenAI semantic conventions:

- `gen_ai.system`: Provider name ("anthropic", "openai", "ollama")
- `gen_ai.request.model`: Model identifier
- `gen_ai.request.temperature`: Temperature setting
- `gen_ai.request.max_tokens`: Maximum tokens requested
- `gen_ai.usage.input_tokens`: Actual input tokens consumed
- `gen_ai.usage.output_tokens`: Actual output tokens generated
- `gen_ai.usage.total_tokens`: Total tokens used

**Tool Executions**: When agents call tools, `tool.execute` spans capture:

- `tool.name`: The tool that was invoked
- `tool.arguments`: JSON-encoded arguments passed to the tool
- `tool.result`: The tool's response (truncated if large)

**Guard Checks**: Guard validations create `guard.check` spans with:

- `guard.name`: Which guard was evaluated
- `guard.passed`: Boolean indicating if validation succeeded
- `guard.reason`: Explanation if the guard failed

**Memory Operations**: Loading and saving conversation history creates `memory.load` and `memory.save` spans, helping you understand memory performance.

This comprehensive instrumentation means you can trace the entire lifecycle of an agent operation, from the initial prompt through LLM calls, tool executions, and guard checks, all the way to the final response.

## Understanding Span Hierarchies

OpenTelemetry organizes spans into traces with parent-child relationships. When you call `agent.prompt()`, Pagent creates a trace that looks like this:

```
agent.prompt (root span)
├── memory.load
├── llm.request
│   └── (network latency captured here)
├── tool.execute (if tool called)
│   └── (tool execution time)
├── llm.request (if tool result sent back)
└── memory.save
```

This hierarchy lets you see exactly what happened and in what order. If your agent makes multiple tool calls, you'll see multiple `tool.execute` and `llm.request` spans nested appropriately.

For streaming operations, the hierarchy is similar but the `agent.stream` span remains open while chunks are being processed:

```
agent.stream (stays open during streaming)
├── memory.load
├── llm.request (completes when streaming starts)
└── memory.save (happens after streaming finishes)
```

## Working with Custom Spans

While automatic instrumentation covers most use cases, you can also create custom spans for application-specific operations. Access the `TelemetryManager` directly:

```php
use Pagent\Observability\TelemetryManager;

$telemetry = TelemetryManager::instance();

// Start a custom span
$span = $telemetry->startSpan('document.process', [
    'document.id' => 'doc-123',
    'document.type' => 'pdf',
]);

try {
    // Perform your operation
    $result = processDocument($documentId);

    // Add events to the span
    $span->addEvent('processing.complete', [
        'pages_processed' => $result['page_count'],
    ]);

    // Set success status
    $span->setStatus('ok');
} catch (Throwable $e) {
    // Record exceptions
    $span->recordException($e);
    $span->setStatus('error', 'Document processing failed');
} finally {
    // Always end the span
    $span->end();
}
```

Custom spans integrate seamlessly with automatic instrumentation, appearing in the same trace as your agent operations.

## Visualizing Traces in Jaeger

Jaeger is one of the most popular open-source tracing backends. Setting up Jaeger with Docker is straightforward:

```bash
docker run -d --name jaeger \
  -p 16686:16686 \
  -p 4318:4318 \
  jaegertracing/all-in-one:latest
```

Then configure Pagent to export to Jaeger:

```php
use function Pagent\telemetry_jaeger;

telemetry_jaeger('http://localhost:4318/v1/traces', 'my-agent');

$agent = agent('support-bot')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->telemetry(true)
    ->build();

// Add tools for more interesting traces
$agent->tool('search_docs', 'Search documentation', [
    'query' => ['type' => 'string', 'description' => 'Search query'],
], function ($query) {
    // Simulate document search
    sleep(1);
    return "Found 3 relevant documents for: {$query}";
});

$response = $agent->prompt('How do I configure memory?');
```

After running this code, open Jaeger's UI at `http://localhost:16686`. You'll see:

**Service Overview**: A list of services that have reported traces. Your agent will appear as "my-agent".

**Trace Timeline**: A visual representation of spans over time. You can see the total duration, number of spans, and which operations took the longest.

**Span Details**: Click into any span to see detailed attributes like model names, token counts, and tool arguments.

**Error Tracking**: Failed operations appear with error status, making it easy to identify and investigate issues.

The Jaeger UI provides powerful filtering and search capabilities, letting you find traces by service name, operation name, duration, or custom attributes.

## Phoenix for LLM-Specific Observability

While Jaeger works great for general tracing, Phoenix is purpose-built for LLM observability. It understands GenAI semantic conventions and provides specialized visualizations for AI applications.

Start Phoenix with Docker:

```bash
docker run -d --name phoenix \
  -p 6006:6006 \
  -p 4317:4317 \
  arizephoenix/phoenix:latest
```

Configure Pagent to export to Phoenix:

```php
use function Pagent\telemetry_otlp;

// Phoenix uses OTLP on port 6006
telemetry_otlp(
    endpoint: 'http://localhost:6006/v1/traces',
    serviceName: 'my-agent'
);

$agent = agent('code-reviewer')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->temperature(0.3)
    ->telemetry(true)
    ->build();

$response = $agent->prompt('Review this code: function add($a, $b) { return $a + $b; }');
```

Open Phoenix at `http://localhost:6006` to see:

**LLM Call Visualization**: Phoenix automatically recognizes `gen_ai.*` attributes and displays LLM calls with model names, token counts, and latency.

**Token Cost Tracking**: See token usage across operations, helping you identify expensive prompts and optimize costs.

**Prompt Analysis**: View the actual prompts sent to the LLM, making it easier to debug prompt engineering issues.

**Response Quality**: Track response quality metrics over time, helping you understand model performance trends.

Phoenix is particularly valuable when working with complex agent systems, as it helps you understand the LLM behavior patterns that drive your agent's decisions.

## Performance Monitoring Example

Let's build a complete example that demonstrates how telemetry helps optimize agent performance:

```php
use function Pagent\telemetry_console;

telemetry_console(verbose: true);

// Create an agent with multiple tools
$agent = agent('research-assistant')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->telemetry(true)
    ->build();

// Add a slow tool to demonstrate performance tracking
$agent->tool('fetch_data', 'Fetch data from external API', [
    'endpoint' => ['type' => 'string', 'description' => 'API endpoint'],
], function ($endpoint) {
    // Simulate slow API call
    sleep(2);
    return "Data from {$endpoint}: [...]";
});

// Add a fast tool for comparison
$agent->tool('calculate', 'Perform calculation', [
    'expression' => ['type' => 'string', 'description' => 'Math expression'],
], function ($expression) {
    return eval("return {$expression};");
});

// Run a task that uses both tools
$response = $agent->prompt('Fetch data from /api/users and calculate 5 + 7');

// The console output will show timing for each operation:
// ┌─ Span: agent.prompt
// │  Duration: 4.567s
// └─
// ┌─ Span: llm.request
// │  Duration: 1.234s
// └─
// ┌─ Span: tool.execute
// │  Duration: 2.001s  <- Slow tool identified!
// │  Attributes:
// │    - tool.name: fetch_data
// └─
// ┌─ Span: tool.execute
// │  Duration: 0.003s  <- Fast tool
// │  Attributes:
// │    - tool.name: calculate
// └─
```

The telemetry immediately reveals that `fetch_data` is the performance bottleneck. With this information, you could optimize by caching API responses, parallelizing calls, or prompting the agent to be more selective about when it fetches data.

## Error Tracking System

Telemetry really shines when debugging errors. Here's an example that shows how exceptions are captured:

```php
use function Pagent\telemetry_jaeger;

telemetry_jaeger('http://localhost:4318/v1/traces', 'error-tracking-demo');

$agent = agent('validator')
    ->provider(anthropic())
    ->telemetry(true)
    ->build();

// Add a tool that might fail
$agent->tool('validate_data', 'Validate input data', [
    'data' => ['type' => 'string', 'description' => 'Data to validate'],
], function ($data) {
    if (empty($data)) {
        throw new InvalidArgumentException('Data cannot be empty');
    }
    return "Valid: {$data}";
});

// Add a guard that might fail
$agent->guard('NoEmptyResponses', function ($response) {
    if (empty(trim($response->content))) {
        return [false, 'Response cannot be empty'];
    }
    return [true, ''];
});

try {
    $response = $agent->prompt('Validate this empty string: ""');
} catch (Throwable $e) {
    echo "Error caught: " . $e->getMessage() . "\n";
}
```

When you view this trace in Jaeger, failed spans will be marked with error status. You can see:

**Exception Details**: The exception type, message, and stack trace
**Context**: What operation was being performed when the error occurred
**Timing**: How long the operation ran before failing
**Attributes**: Tool arguments, model settings, and other context

This contextual information is invaluable for debugging production issues where you can't easily reproduce the exact conditions.

## Managing Telemetry Lifecycle

For long-running applications, it's important to properly manage telemetry lifecycle:

```php
use Pagent\Observability\TelemetryManager;

// Initialize telemetry at application startup
telemetry_otlp('http://localhost:4318/v1/traces');

// Use agents throughout your application
$agent = agent('worker')->telemetry(true)->build();
// ... do work ...

// Clean up at shutdown
TelemetryManager::instance()->shutdown();
```

The `shutdown()` call ensures all pending traces are flushed to the backend before your application exits. This is particularly important for batch jobs or CLI applications that might terminate quickly.

## Sampling for High-Volume Systems

If your agent system handles thousands of requests per minute, tracing every single operation can be expensive. Use sampling to reduce overhead while maintaining observability:

```php
use function Pagent\telemetry;

telemetry([
    'enabled' => true,
    'service_name' => 'high-volume-agent',
    'exporter' => 'otlp',
    'sampling_rate' => 0.1,  // Trace 10% of operations
    'otlp' => [
        'endpoint' => 'http://localhost:4318/v1/traces',
    ],
]);
```

With a 10% sampling rate, you'll get representative traces while dramatically reducing storage and network costs. For troubleshooting specific issues, you can temporarily increase the sampling rate to capture more detail.

## Best Practices

Based on production experience with Pagent telemetry, here are some best practices:

**Enable Telemetry in Development**: Use `telemetry_console(verbose: true)` during development to understand your agent's behavior. This helps catch performance issues early.

**Use Descriptive Service Names**: Name your services clearly (`customer-support-agent`, `code-review-bot`) rather than generic names (`agent`, `bot`). This makes traces easier to identify in multi-service systems.

**Monitor Token Usage**: The `gen_ai.usage.*` attributes are critical for cost management. Set up alerts when token usage exceeds thresholds.

**Track Custom Metrics**: Add custom spans for business-specific operations like "document.classify" or "ticket.route". This helps connect technical metrics to business outcomes.

**Handle Sensitive Data**: Tool arguments are logged in traces by default. If your tools process sensitive data, be mindful of this and consider filtering or masking sensitive fields.

**Test Trace Export**: In production, ensure traces are actually reaching your backend. A misconfigured endpoint can silently fail, leaving you without observability.

## What We've Learned

In this chapter, we've explored Pagent's OpenTelemetry integration:

- Enabling telemetry with console, OTLP, Jaeger, and Zipkin exporters
- Understanding automatic instrumentation of agent operations, LLM calls, tool executions, and guards
- Creating custom spans for application-specific operations
- Visualizing traces in Jaeger and Phoenix
- Using telemetry for performance optimization and error tracking
- Managing telemetry lifecycle and sampling for high-volume systems

Observability transforms your agent from a black box into a well-understood system. You can see exactly what your agent is doing, identify performance bottlenecks, track costs, and debug errors with rich contextual information.

In the next chapter, we'll explore debugging and monitoring techniques that build on this telemetry foundation, including token usage tracking, cost calculation, and performance profiling.
