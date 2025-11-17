# Chapter 23: Debugging and Monitoring

Building LLM agents is one thing. Understanding how they behave in production is another. When your agent makes unexpected decisions, consumes more tokens than anticipated, or takes too long to respond, you need visibility into what's happening. This chapter explores Pagent's debugging and monitoring capabilities, from simple statistics tracking to comprehensive distributed tracing.

You'll learn how to debug conversations, monitor token usage and costs, track performance with OpenTelemetry, visualize agent behavior with observability tools, and implement middleware for custom logging and metrics.

## The Observability Challenge

LLM applications present unique observability challenges:

- **Non-deterministic behavior** - Same prompt may produce different outputs
- **Token costs** - Every API call has a direct cost impact
- **Latency variability** - Response times vary by model, prompt complexity, and provider load
- **Multi-step workflows** - Conversations, tool calls, and delegations create complex execution traces
- **Context accumulation** - Message history grows with each interaction

Traditional logging isn't enough. You need specialized observability that captures LLM-specific metrics like token counts, model parameters, and conversation flow.

## Agent Statistics with getStats()

The simplest debugging approach is inspecting agent statistics. Every agent tracks basic usage metrics:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;

$agent = agent('customer-support')
    ->provider(anthropic())
    ->system('You are a helpful customer support agent.')
    ->build();

// Have some conversations
$agent->prompt('How do I reset my password?');
$agent->prompt('Can you help me with billing?');
$agent->prompt('I need to update my email address.');

// Get statistics
$stats = $agent->getStats();

print_r($stats);
/*
Array
(
    [agent] => customer-support
    [total_messages] => 6
    [user_messages] => 3
    [assistant_messages] => 3
    [tools_registered] => 0
    [guards_active] => 0
    [middleware_active] => 0
)
*/
```

The implementation is straightforward:

```php
// From src/Agent.php:813-828
public function getStats(): array
{
    $totalMessages = count($this->messages);
    $userMessages = count(array_filter($this->messages, fn ($m) => $m['role'] === 'user'));
    $assistantMessages = count(array_filter($this->messages, fn ($m) => $m['role'] === 'assistant'));

    return [
        'agent' => $this->name,
        'total_messages' => $totalMessages,
        'user_messages' => $userMessages,
        'assistant_messages' => $assistantMessages,
        'tools_registered' => count($this->tools),
        'guards_active' => count($this->guards),
        'middleware_active' => count($this->middleware),
    ];
}
```

These statistics answer basic questions:

- **How much has this agent been used?** - Check `total_messages`
- **Is the conversation balanced?** - Compare `user_messages` to `assistant_messages`
- **What features are active?** - Inspect tools, guards, and middleware counts

For multi-agent systems, you can aggregate statistics across agents:

```php
<?php

use function Pagent\agent;

$agents = ['researcher', 'writer', 'reviewer'];

foreach ($agents as $name) {
    $stats = agent($name)->getStats();
    echo "{$stats['agent']}: {$stats['total_messages']} messages\n";
}

// Output:
// researcher: 12 messages
// writer: 8 messages
// reviewer: 4 messages
```

This reveals which agents are doing the most work and helps identify bottlenecks.

## Tracking Token Usage

Token consumption directly impacts costs. Every response object includes usage metadata:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;

$agent = agent('analyzer')
    ->provider(anthropic())
    ->model('claude-sonnet-4-20250514')
    ->build();

$response = $agent->prompt('Analyze the performance implications of using Redis vs Memcached for session storage.');

// Access token counts
echo "Total tokens: {$response->tokens}\n";

// Detailed breakdown
print_r($response->usage);
/*
Array
(
    [input_tokens] => 45
    [output_tokens] => 320
    [total_tokens] => 365
)
*/
```

Both Anthropic and OpenAI providers return structured usage data. The `tokens` property provides the total, while `usage` gives you the breakdown:

- **input_tokens** - Tokens in your prompt (including system message and conversation history)
- **output_tokens** - Tokens in the model's response
- **total_tokens** - Sum of input and output

### Calculating Costs

Combine token counts with provider pricing to calculate costs:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;

class CostTracker
{
    // Pricing per million tokens (as of Nov 2024)
    private const PRICING = [
        'claude-sonnet-4-20250514' => [
            'input' => 3.00,   // $3 per million input tokens
            'output' => 15.00, // $15 per million output tokens
        ],
        'gpt-4o' => [
            'input' => 5.00,
            'output' => 15.00,
        ],
    ];

    private float $totalCost = 0;

    public function trackPrompt(string $model, object $response): float
    {
        if (!isset(self::PRICING[$model])) {
            return 0.0;
        }

        $pricing = self::PRICING[$model];
        $usage = $response->usage;

        $inputCost = ($usage['input_tokens'] / 1_000_000) * $pricing['input'];
        $outputCost = ($usage['output_tokens'] / 1_000_000) * $pricing['output'];
        $cost = $inputCost + $outputCost;

        $this->totalCost += $cost;

        return $cost;
    }

    public function getTotalCost(): float
    {
        return $this->totalCost;
    }
}

// Usage
$tracker = new CostTracker();
$agent = agent('assistant')->provider(anthropic())->model('claude-sonnet-4-20250514')->build();

$response1 = $agent->prompt('Explain dependency injection.');
$cost1 = $tracker->trackPrompt('claude-sonnet-4-20250514', $response1);

$response2 = $agent->prompt('Give me a code example.');
$cost2 = $tracker->trackPrompt('claude-sonnet-4-20250514', $response2);

echo "Total cost: $" . number_format($tracker->getTotalCost(), 4) . "\n";
```

For production applications, track costs per user, per feature, or per time period to understand spending patterns.

## Exporting Conversations for Debugging

When an agent produces unexpected output, you need to inspect the entire conversation history:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;

$agent = agent('debugger')
    ->provider(anthropic())
    ->system('You are a helpful assistant.')
    ->build();

$agent->prompt('What is PHP?');
$agent->prompt('Show me a code example.');
$agent->prompt('Explain namespaces.');

// Export entire conversation as JSON
$json = $agent->exportConversation();
file_put_contents('/tmp/conversation.json', $json);

// The exported JSON includes:
// {
//     "agent": "debugger",
//     "messages": [
//         {"role": "user", "content": "What is PHP?"},
//         {"role": "assistant", "content": "PHP is a server-side..."},
//         ...
//     ],
//     "exported_at": "2025-11-17T12:00:00+00:00"
// }
```

This is invaluable for debugging:

- **Reproduce issues** - Replay the exact conversation that caused a problem
- **Analyze prompts** - See how conversation history affects responses
- **Share with team** - Send conversation logs to colleagues
- **Test improvements** - Compare behavior before/after changes

You can also import conversations to restore state:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;

// Create new agent
$agent = agent('restored')
    ->provider(anthropic())
    ->build();

// Load previous conversation
$json = file_get_contents('/tmp/conversation.json');
$agent->importConversation($json);

// Agent now has full conversation history
// Next prompt continues from where it left off
$response = $agent->prompt('Can you elaborate on the last point?');
```

This enables scenarios like:

- **Session persistence** - Save/restore conversations across requests
- **Agent migration** - Transfer conversation to a different model
- **Testing** - Create agents with pre-loaded conversation state

## Inspecting Message History

For programmatic analysis, access the messages array directly:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;

$agent = agent('analyzer')->provider(anthropic())->build();

$agent->prompt('Hello');
$agent->prompt('How are you?');

// Direct access to message array
foreach ($agent->messages as $message) {
    echo "[{$message['role']}]: {$message['content']}\n";
}

// Output:
// [user]: Hello
// [assistant]: Hello! I'm Claude, an AI assistant...
// [user]: How are you?
// [assistant]: I'm doing well, thank you...
```

The `messages` property is public (as of src/Agent.php:60), making it easy to inspect, filter, or analyze:

```php
<?php

// Count messages by role
$roleCounts = [];
foreach ($agent->messages as $msg) {
    $role = $msg['role'];
    $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
}

// Find longest message
$longest = array_reduce($agent->messages, function ($carry, $msg) {
    $length = strlen($msg['content']);
    return $length > ($carry['length'] ?? 0) ? ['length' => $length, 'msg' => $msg] : $carry;
}, []);

// Extract all user questions
$questions = array_filter($agent->messages, fn($m) => $m['role'] === 'user');
```

## OpenTelemetry Integration

For production-grade observability, Pagent integrates with OpenTelemetry, the industry-standard observability framework. Enable telemetry to automatically trace all agent operations:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;
use function Pagent\telemetry_console;

// Enable console output for development
telemetry_console(verbose: true);

$agent = agent('traced-agent')
    ->provider(anthropic())
    ->telemetry(true) // Enable telemetry for this agent
    ->build();

$response = $agent->prompt('Explain closures in PHP.');

// Console output shows:
// Span: agent.prompt (duration: 1.2s)
//   agent.name = traced-agent
//   agent.operation = prompt
// Span: llm.request (duration: 1.1s)
//   gen_ai.system = anthropic
//   gen_ai.request.model = claude-sonnet-4-20250514
//   gen_ai.usage.input_tokens = 25
//   gen_ai.usage.output_tokens = 180
```

### Telemetry Exporters

Pagent supports multiple telemetry backends:

```php
<?php

use function Pagent\telemetry_console;
use function Pagent\telemetry_jaeger;
use function Pagent\telemetry_otlp;
use function Pagent\telemetry_zipkin;

// Console (development)
telemetry_console(verbose: true);

// Jaeger (distributed tracing)
telemetry_jaeger(
    endpoint: 'http://localhost:4318/v1/traces',
    serviceName: 'my-llm-app'
);

// Generic OTLP (Phoenix, Langfuse, etc.)
telemetry_otlp(
    endpoint: 'http://localhost:6006/v1/traces',
    headers: ['x-api-key' => 'your-key'],
    serviceName: 'my-llm-app'
);

// Zipkin
telemetry_zipkin(
    endpoint: 'http://localhost:9411/api/v2/spans',
    serviceName: 'my-llm-app'
);
```

Each exporter sends traces to a different backend. Choose based on your infrastructure:

- **Console** - Quick debugging, prints to stdout
- **Jaeger** - Open-source distributed tracing, great for microservices
- **OTLP** - OpenTelemetry Protocol, works with many backends (Phoenix, Langfuse, Helicone)
- **Zipkin** - Lightweight tracing, simple to deploy

### What Gets Traced

When telemetry is enabled, Pagent automatically creates spans for:

**Agent operations:**
- `agent.prompt` - Each prompt/response cycle
- `agent.build` - Agent construction
- Attributes: agent name, operation type

**LLM requests:**
- `llm.request` - Every API call to the provider
- Attributes: provider, model, temperature, max tokens
- Usage metrics: input/output/total tokens

**Tool executions:**
- `tool.execute` - When agents use tools
- Attributes: tool name, arguments
- Results and errors

**Guard checks:**
- `guard.check` - When content guards run
- Attributes: guard name, passed/failed

The implementation uses OpenTelemetry semantic conventions:

```php
// From src/Observability/TelemetryManager.php:121-133
public function startLLMSpan(string $provider, string $model, array $attributes = []): Span|NullSpan
{
    $defaultAttributes = [
        'gen_ai.system' => $provider,
        'gen_ai.request.model' => $model,
        'gen_ai.operation.name' => 'chat',
    ];

    return $this->startSpan(
        'llm.request',
        array_merge($defaultAttributes, $attributes)
    );
}
```

This ensures compatibility with standard observability tools.

## Visualizing with Jaeger

Jaeger provides a web UI for exploring traces. Start Jaeger with Docker:

```bash
# Using Pagent's observability stack
just observability-up

# Or manually with Docker
docker run -d \
  --name jaeger \
  -p 16686:16686 \
  -p 4318:4318 \
  jaegertracing/all-in-one:latest
```

Then send traces from your application:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;
use function Pagent\telemetry_jaeger;

telemetry_jaeger('http://localhost:4318/v1/traces', 'demo-app');

$agent = agent('demo')
    ->provider(anthropic())
    ->telemetry(true)
    ->build();

$agent->prompt('What is the difference between abstract classes and interfaces?');
```

Open http://localhost:16686 in your browser to see:

- **Service list** - All services sending traces (e.g., "demo-app")
- **Trace timeline** - Visualize span duration and nesting
- **Span details** - Inspect attributes like model, tokens, errors
- **Search** - Find traces by service, operation, duration, tags

Jaeger is especially powerful for multi-agent systems, showing how agents delegate to each other and which operations take the longest.

## Observability Stack

Pagent includes a complete Docker-based observability stack with five platforms:

| Platform | Port  | Purpose                     |
|----------|-------|-----------------------------|
| Jaeger   | 16686 | Distributed tracing         |
| Phoenix  | 6006  | LLM observability (Arize)   |
| Langfuse | 3000  | LLM monitoring & prompts    |
| Helicone | 3001  | LLM cost tracking           |
| Opik     | 5173  | LLM experiment tracking     |

Start the entire stack:

```bash
# Start all services
just observability-up

# View URLs
just observability-urls

# Run integration tests
just observability-test

# Stop services
just observability-down
```

Each platform offers different capabilities. Phoenix focuses on LLM-specific observability with prompt analysis, Langfuse tracks prompt versions and A/B tests, Helicone specializes in cost tracking, and Opik handles experiment management.

Consult `OBSERVABILITY.md` in the repository for detailed setup instructions, authentication configuration, and integration examples.

## Middleware for Custom Logging

For application-specific observability, implement custom middleware:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;
use Pagent\Middleware\LoggingMiddleware;
use Psr\Log\LoggerInterface;

class MyLogger implements LoggerInterface
{
    public function info(string $message, array $context = []): void
    {
        error_log(sprintf("[INFO] %s %s", $message, json_encode($context)));
    }

    // Implement other PSR-3 methods...
}

$logger = new MyLogger();
$loggingMiddleware = new LoggingMiddleware($logger);

$agent = agent('logged-agent')
    ->provider(anthropic())
    ->middleware($loggingMiddleware)
    ->build();

$response = $agent->prompt('What is dependency injection?');

// Logs written:
// [INFO] Agent prompt initiated {"message":"What is dependency injection?","model":"claude-sonnet-4-20250514","temperature":0.7}
// [INFO] Agent response received {"provider":"anthropic","model":"claude-sonnet-4-20250514","tokens":245,"content_length":1024}
```

The `LoggingMiddleware` implementation is simple but effective:

```php
// From src/Middleware/LoggingMiddleware.php:22-43
public function before(string $message, array $options): array
{
    $this->logger->info('Agent prompt initiated', [
        'message' => $message,
        'model' => $options['model'] ?? null,
        'temperature' => $options['temperature'] ?? null,
    ]);

    return $options;
}

public function after(object $response): object
{
    $this->logger->info('Agent response received', [
        'provider' => $response->provider ?? null,
        'model' => $response->model ?? null,
        'tokens' => $response->tokens ?? 0,
        'content_length' => mb_strlen($response->content ?? ''),
    ]);

    return $response;
}
```

Create custom middleware to:

- **Log to databases** - Store prompts/responses for audit trails
- **Track metrics** - Send token counts to Prometheus, Datadog, etc.
- **Enforce policies** - Block prompts containing sensitive data
- **Cache responses** - Avoid redundant API calls
- **Rate limit** - Prevent excessive usage

### Custom Metrics Middleware

Here's an example that sends metrics to Prometheus:

```php
<?php

namespace App\Middleware;

use Pagent\Contracts\Middleware;
use Prometheus\CollectorRegistry;

final class MetricsMiddleware implements Middleware
{
    private CollectorRegistry $registry;

    public function __construct(CollectorRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function before(string $message, array $options): array
    {
        // Increment prompt counter
        $counter = $this->registry->getOrRegisterCounter(
            'app',
            'llm_prompts_total',
            'Total number of LLM prompts',
            ['model', 'agent']
        );

        $counter->inc([
            $options['model'] ?? 'unknown',
            $options['agent'] ?? 'unknown',
        ]);

        return $options;
    }

    public function after(object $response): object
    {
        // Record token usage
        $histogram = $this->registry->getOrRegisterHistogram(
            'app',
            'llm_tokens_used',
            'Token usage per request',
            ['model', 'type']
        );

        $histogram->observe(
            $response->usage['input_tokens'] ?? 0,
            [$response->model ?? 'unknown', 'input']
        );

        $histogram->observe(
            $response->usage['output_tokens'] ?? 0,
            [$response->model ?? 'unknown', 'output']
        );

        // Record latency
        if (isset($response->duration)) {
            $latency = $this->registry->getOrRegisterHistogram(
                'app',
                'llm_request_duration_seconds',
                'LLM request duration in seconds',
                ['model']
            );

            $latency->observe($response->duration, [$response->model ?? 'unknown']);
        }

        return $response;
    }
}
```

Middleware runs for every prompt, making it ideal for cross-cutting concerns like logging, metrics, and validation.

## Guard Statistics

If you use content guards, track their execution:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;
use Pagent\Guards\ContentGuard;

$agent = agent('guarded')
    ->provider(anthropic())
    ->guard(new ContentGuard(
        name: 'no-profanity',
        check: fn($input, $output) => !preg_match('/bad|words/', $output)
    ))
    ->build();

// After some conversations...
$guardStats = $agent->getGuardStats();

print_r($guardStats);
/*
Array
(
    [0] => Array
        (
            [name] => no-profanity
            [active] => 1
        )
)
*/
```

Currently, guard stats show which guards are registered. For detailed metrics (pass/fail counts), combine guards with custom middleware that tracks violations.

## Performance Profiling

Identify slow operations by adding timing to your code:

```php
<?php

use function Pagent\agent;
use function Pagent\anthropic;

class PerformanceProfiler
{
    private array $timings = [];

    public function start(string $operation): void
    {
        $this->timings[$operation] = microtime(true);
    }

    public function end(string $operation): float
    {
        if (!isset($this->timings[$operation])) {
            return 0.0;
        }

        $duration = microtime(true) - $this->timings[$operation];
        unset($this->timings[$operation]);

        return $duration;
    }
}

$profiler = new PerformanceProfiler();

$agent = agent('profiled')->provider(anthropic())->build();

$profiler->start('prompt');
$response = $agent->prompt('Explain monads.');
$duration = $profiler->end('prompt');

echo "Prompt took: " . number_format($duration, 3) . " seconds\n";
echo "Tokens used: {$response->tokens}\n";
echo "Tokens per second: " . number_format($response->tokens / $duration, 0) . "\n";
```

This helps you:

- **Compare providers** - Which is faster for your workload?
- **Optimize prompts** - Do shorter prompts reduce latency?
- **Detect regressions** - Did the latest change slow things down?

OpenTelemetry spans automatically track duration, but manual profiling gives you flexibility for custom measurements.

## Best Practices for Debugging and Monitoring

### 1. Enable Telemetry in Production

Don't wait for problems to appear. Enable telemetry from day one:

```php
<?php

use function Pagent\telemetry_otlp;

// Configure based on environment
$endpoint = $_ENV['OTEL_ENDPOINT'] ?? 'http://localhost:4318/v1/traces';
$serviceName = $_ENV['SERVICE_NAME'] ?? 'llm-app';

telemetry_otlp($endpoint, [], $serviceName);
```

Telemetry overhead is minimal (microseconds per span), but the visibility is invaluable.

### 2. Track Costs Per Feature

Aggregate token usage by feature to understand where money goes:

```php
<?php

class FeatureCostTracker
{
    private array $costs = [];

    public function track(string $feature, object $response): void
    {
        $cost = $this->calculateCost($response);
        $this->costs[$feature] = ($this->costs[$feature] ?? 0) + $cost;
    }

    private function calculateCost(object $response): float
    {
        // Use actual pricing for your provider
        return ($response->usage['input_tokens'] / 1_000_000) * 3.00
             + ($response->usage['output_tokens'] / 1_000_000) * 15.00;
    }

    public function getCosts(): array
    {
        return $this->costs;
    }
}

$tracker = new FeatureCostTracker();

// Feature: document summarization
$response1 = $agent->prompt('Summarize this document...');
$tracker->track('summarization', $response1);

// Feature: code generation
$response2 = $agent->prompt('Generate a REST API...');
$tracker->track('code-generation', $response2);

// At end of day/week/month
print_r($tracker->getCosts());
```

This reveals which features drive costs and informs pricing decisions.

### 3. Export Conversations for Failed Requests

When things go wrong, save the conversation:

```php
<?php

try {
    $response = $agent->prompt($userInput);
} catch (Exception $e) {
    // Export conversation for debugging
    $json = $agent->exportConversation();
    file_put_contents("/var/log/failed-conversations/{$agent->getName()}-" . time() . ".json", $json);

    throw $e;
}
```

This makes it easy to reproduce and debug failures.

### 4. Use Different Telemetry in Dev vs Production

Console output is perfect for development, but production needs persistent storage:

```php
<?php

use function Pagent\telemetry_console;
use function Pagent\telemetry_jaeger;

if ($_ENV['APP_ENV'] === 'production') {
    telemetry_jaeger($_ENV['JAEGER_ENDPOINT'], $_ENV['SERVICE_NAME']);
} else {
    telemetry_console(verbose: true);
}
```

### 5. Set Up Alerts on Key Metrics

Monitor critical thresholds:

- **High token usage** - Alert when daily tokens exceed budget
- **Slow responses** - Alert when average latency crosses threshold
- **Error rates** - Alert when guards fail frequently or API errors spike

Use your metrics middleware to send data to alerting platforms like PagerDuty, Opsgenie, or Slack.

## What's Next?

You now have comprehensive tools for debugging and monitoring Pagent applications:

- Agent statistics with `getStats()` and `getGuardStats()`
- Token tracking and cost calculation
- Conversation export/import for debugging
- OpenTelemetry integration for distributed tracing
- Multiple observability platforms (Jaeger, Phoenix, Langfuse, etc.)
- Custom middleware for logging and metrics
- Performance profiling and cost tracking

In **Chapter 24: Testing LLM Agents**, we'll explore:

- Writing unit tests for deterministic agent behavior
- Testing with mock providers
- Integration testing with real APIs
- Evaluating agent outputs programmatically
- Test-driven development patterns for LLM applications

**Key Takeaways:**

✅ Use `getStats()` for quick insight into agent usage and configuration
✅ Track token usage with `response->tokens` and `response->usage` to monitor costs
✅ Export conversations with `exportConversation()` for debugging and audit trails
✅ Enable OpenTelemetry with `telemetry_console()` or `telemetry_jaeger()` for production observability
✅ Use the observability stack (Jaeger, Phoenix, Langfuse) for comprehensive LLM monitoring
✅ Implement custom middleware for application-specific logging and metrics
✅ Profile performance to identify bottlenecks and optimize latency
✅ Track costs per feature to understand spending and inform pricing

Continue to [Chapter 24: Testing LLM Agents](./article.part24.md) →
