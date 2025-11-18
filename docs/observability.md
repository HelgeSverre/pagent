# Observability & Distributed Tracing

Pagent includes comprehensive OpenTelemetry instrumentation for distributed tracing, performance monitoring, and deep observability into your LLM agent interactions.

## Table of Contents

- [Introduction](#introduction)
- [Quick Start](#quick-start)
- [Exporters](#exporters)
- [Agent Instrumentation](#agent-instrumentation)
- [Workflow Telemetry](#workflow-telemetry)
- [Semantic Conventions](#semantic-conventions)
- [Configuration Reference](#configuration-reference)
- [Production Deployment](#production-deployment)
- [Troubleshooting](#troubleshooting)

---

## Introduction

### What is Observability?

Observability gives you deep insights into your LLM agent's behavior through distributed tracing. Each operation (prompts, tool executions, guard checks, memory operations) creates **spans** that are exported to observability platforms for analysis.

### Benefits

- **Debug Multi-Agent Workflows** - Visualize complex agent interactions
- **Performance Monitoring** - Track latency and identify bottlenecks
- **Token Usage Visibility** - Monitor input/output tokens in real-time
- **Cost Tracking** - Understand API consumption patterns
- **Compliance & Auditing** - Complete audit trail of LLM interactions
- **Platform Integration** - Works with Jaeger, Zipkin, Datadog, New Relic, and more

### Supported Platforms

- **Jaeger** - Open-source distributed tracing
- **Zipkin** - Distributed tracing system
- **OTLP** - Generic OpenTelemetry Protocol (works with most platforms)
- **Console** - Local debugging output
- **In-Memory** - Testing and development

---

## Quick Start

### Console Output (Development)

The easiest way to get started is with console output for local debugging:

```php
<?php

use function Pagent\{agent, telemetry_console};

// Enable console telemetry (verbose mode)
telemetry_console(verbose: true);

// Create agent with telemetry enabled
agent('assistant')
    ->provider('anthropic')
    ->telemetry(true)
    ->prompt('What is 2+2?');

// Output shows:
// ┌─ Span: agent.prompt
// │  Duration: 1.23s
// │  Attributes:
// │    - agent.name: assistant
// │    - gen_ai.system: anthropic
// │    - gen_ai.usage.total_tokens: 125
// └─
```

See [`examples/15-telemetry-console.php`](../examples/15-telemetry-console.php) for complete example.

### Jaeger Integration (Production)

For production monitoring with Jaeger:

```php
<?php

use function Pagent\{agent, telemetry_jaeger};

// Configure Jaeger exporter
telemetry_jaeger('http://localhost:14268/api/traces');

// All agents with telemetry enabled will export to Jaeger
agent('researcher')
    ->provider('anthropic')
    ->telemetry(true)
    ->tool('web_fetch', 'Fetch URL', fn($url) => file_get_contents($url))
    ->prompt('Research PHP trends');

// View traces at http://localhost:16686 (Jaeger UI)
```

See [`examples/16-telemetry-jaeger.php`](../examples/16-telemetry-jaeger.php) for complete example.

### Generic OTLP (Works with Most Platforms)

For platforms supporting OpenTelemetry Protocol:

```php
<?php

use function Pagent\{agent, telemetry_otlp};

// Configure OTLP exporter (HTTP)
telemetry_otlp('http://localhost:4318/v1/traces');

// Now works with Datadog, New Relic, Honeycomb, etc.
agent('assistant')->telemetry(true)->prompt('Hello');
```

---

## Exporters

### Console Exporter

**Purpose:** Local debugging and development

**Configuration:**

```php
use function Pagent\telemetry_console;

// Verbose mode (shows all attributes)
telemetry_console(verbose: true);

// Minimal mode (span names and durations only)
telemetry_console(verbose: false);
```

**Use Cases:**
- Understanding trace structure
- Debugging agent interactions
- Verifying instrumentation
- Local development

### Jaeger Exporter

**Purpose:** Production distributed tracing

**Configuration:**

```php
use function Pagent\telemetry_jaeger;

// Default endpoint
telemetry_jaeger('http://localhost:14268/api/traces');

// Custom endpoint
telemetry_jaeger('https://jaeger.example.com/api/traces');
```

**Docker Setup:**

```bash
docker run -d \
  -p 16686:16686 \
  -p 14268:14268 \
  --name jaeger \
  jaegertracing/all-in-one:latest

# Access UI: http://localhost:16686
```

**Use Cases:**
- Production monitoring
- Multi-service tracing
- Performance analysis
- Latency tracking

### Zipkin Exporter

**Purpose:** Alternative distributed tracing platform

**Configuration:**

```php
use function Pagent\telemetry_zipkin;

// Default endpoint
telemetry_zipkin('http://localhost:9411/api/v2/spans');

// Custom endpoint
telemetry_zipkin('https://zipkin.example.com/api/v2/spans');
```

**Docker Setup:**

```bash
docker run -d \
  -p 9411:9411 \
  --name zipkin \
  openzipkin/zipkin:latest

# Access UI: http://localhost:9411
```

### OTLP Exporter

**Purpose:** Generic OpenTelemetry Protocol support

**Configuration:**

```php
use function Pagent\telemetry_otlp;

// HTTP endpoint
telemetry_otlp('http://localhost:4318/v1/traces');

// gRPC endpoint (if supported by backend)
telemetry_otlp('http://localhost:4317/v1/traces');
```

**Compatible Platforms:**
- Datadog
- New Relic
- Honeycomb
- Lightstep
- Elastic APM
- Grafana Tempo
- Any OpenTelemetry-compatible backend

**Custom Headers:**

```php
use Pagent\Observability\TelemetryManager;

TelemetryManager::instance()->initialize([
    'enabled' => true,
    'exporter' => 'otlp',
    'otlp' => [
        'endpoint' => 'https://api.example.com/v1/traces',
        'headers' => [
            'x-api-key' => 'your-api-key',
            'x-tenant-id' => 'tenant-123',
        ],
    ],
]);
```

### Advanced Configuration

**Manual Configuration:**

```php
use Pagent\Observability\TelemetryManager;

TelemetryManager::instance()->initialize([
    'service_name' => 'my-agent-app',
    'service_version' => '1.0.0',
    'enabled' => true,
    'exporter' => 'jaeger',  // or 'otlp', 'zipkin', 'console'
    'sampling_rate' => 1.0,   // 100% sampling (0.0 to 1.0)
    'jaeger' => [
        'endpoint' => 'http://localhost:14268/api/traces',
    ],
]);
```

---

## Agent Instrumentation

### Basic Agent Operations

All agent operations are automatically instrumented when telemetry is enabled:

```php
agent('assistant')
    ->provider('anthropic')
    ->telemetry(true)  // Enable telemetry for this agent
    ->prompt('Hello');

// Creates spans:
// agent.prompt
//   ├─ llm.request
//   └─ guard.check (if guards configured)
```

### What Gets Traced

#### 1. Prompts

Every `prompt()` call creates an `agent.prompt` span:

```php
$response = agent('bot')->telemetry(true)->prompt('Calculate 5 + 3');

// Span: agent.prompt
// Attributes:
//   - agent.name: bot
//   - agent.operation: prompt
//   - gen_ai.usage.input_tokens: 15
//   - gen_ai.usage.output_tokens: 8
//   - gen_ai.usage.total_tokens: 23
```

#### 2. LLM Provider Calls

Each LLM request creates an `llm.request` span (child of `agent.prompt`):

```php
// Span: llm.request
// Attributes:
//   - gen_ai.system: anthropic
//   - gen_ai.request.model: claude-sonnet-4-20250514
//   - gen_ai.request.temperature: 0.7
//   - gen_ai.request.max_tokens: 1024
//   - gen_ai.response.model: claude-sonnet-4-20250514
//   - gen_ai.usage.completion_tokens: 75
//   - gen_ai.usage.prompt_tokens: 150
```

#### 3. Tool Execution

Tool calls create `tool.execute` spans:

```php
agent('calculator')
    ->telemetry(true)
    ->tool('add', 'Add numbers', fn(int $a, int $b) => $a + $b)
    ->prompt('What is 5 + 3?');

// Span: tool.execute
// Attributes:
//   - tool.name: add
//   - tool.arguments: {"a": 5, "b": 3}
//   - tool.result: 8
//   - tool.duration: 0.001s
```

See [`examples/18-telemetry-tools.php`](../examples/18-telemetry-tools.php) for detailed tool tracing examples.

#### 4. Guard Checks

Safety guard validation creates `guard.check` spans:

```php
use Pagent\Guards\PIIGuard;

agent('secure-bot')
    ->telemetry(true)
    ->guard(new PIIGuard())
    ->prompt('My email is test@example.com');

// Span: guard.check
// Attributes:
//   - guard.name: PIIGuard
//   - guard.passed: false
//   - guard.violation: Email address detected
```

#### 5. Memory Operations

Memory load/save operations create `memory.load` and `memory.save` spans:

```php
use Pagent\Memory\Adapters\SqliteAdapter;

agent('chatbot')
    ->telemetry(true)
    ->memory(new SqliteAdapter('chat.db'))
    ->sessionId('user-123')
    ->prompt('Hello');

// Span: memory.load
// Attributes:
//   - session.id: user-123
//   - message.count: 5
//   - duration: 0.005s

// Span: memory.save
// Attributes:
//   - session.id: user-123
//   - message.count: 7
//   - duration: 0.003s
```

#### 6. Streaming

Real-time streaming creates detailed trace spans:

```php
agent('streamer')
    ->telemetry(true)
    ->streamTo('Tell me a story', function($chunk) {
        echo $chunk->content;
    });

// Span: agent.stream
// Attributes:
//   - llm.stream.total_chunks: 45
//   - llm.stream.total_bytes: 1234
//   - llm.stream.duration: 2.5s
```

---

## Workflow Telemetry

Multi-agent workflows automatically create hierarchical traces showing the entire orchestration:

### Pipeline Workflows

```php
use function Pagent\{agent, pipeline, telemetry_jaeger};

telemetry_jaeger('http://localhost:14268/api/traces');

// Enable telemetry on all agents
agent('researcher')->provider('anthropic')->telemetry(true);
agent('writer')->provider('anthropic')->telemetry(true);
agent('editor')->provider('anthropic')->telemetry(true);

// Run workflow
pipeline('content-creation')
    ->step('research', agent('researcher'))
    ->step('write', agent('writer'))
    ->step('edit', agent('editor'))
    ->run('Write article about PHP agents');

// Creates hierarchical trace:
// workflow.pipeline (content-creation)
//   ├─ workflow.step (research)
//   │  └─ agent.prompt
//   │     └─ llm.request
//   ├─ workflow.step (write)
//   │  └─ agent.prompt
//   │     └─ llm.request
//   └─ workflow.step (edit)
//      └─ agent.prompt
//         └─ llm.request
```

See [`examples/17-telemetry-workflow.php`](../examples/17-telemetry-workflow.php) for complete workflow tracing.

### Automatic Workflow Detection

Workflows automatically enable telemetry if **any** agent in the workflow has it enabled:

```php
// Only researcher has telemetry
agent('researcher')->telemetry(true);
agent('writer')->telemetry(false);

// Pipeline still creates workflow spans because one agent has telemetry
pipeline('demo')
    ->step('research', agent('researcher'))
    ->step('write', agent('writer'))
    ->run('Demo');

// Workflow spans created, but only researcher.prompt span has LLM details
```

### Chain Workflows

```php
use function Pagent\chain;

chain()
    ->agent(agent('step1')->telemetry(true))
    ->agent(agent('step2')->telemetry(true))
    ->run('Input message');

// Creates:
// workflow.chain
//   ├─ agent.prompt (step1)
//   └─ agent.prompt (step2)
```

---

## Semantic Conventions

Pagent follows **OpenTelemetry Semantic Conventions for GenAI** to ensure compatibility with observability platforms.

### Standard Attributes

#### Agent Attributes

```
agent.name           - Agent instance name (e.g., "assistant")
agent.session_id     - Session identifier for multi-turn conversations
agent.operation      - Operation type ("prompt", "stream", etc.)
```

#### GenAI Attributes (OTel Standard)

```
gen_ai.system                    - Provider name ("anthropic", "openai", "ollama")
gen_ai.request.model             - Requested model
gen_ai.response.model            - Actual model used in response
gen_ai.request.temperature       - Temperature setting
gen_ai.request.max_tokens        - Max tokens limit
gen_ai.usage.prompt_tokens       - Input tokens consumed
gen_ai.usage.completion_tokens   - Output tokens generated
gen_ai.usage.total_tokens        - Total tokens (input + output)
gen_ai.operation.name            - Operation type ("chat", "completion")
```

#### Tool Attributes

```
tool.name         - Tool name
tool.arguments    - JSON-encoded arguments
tool.result       - Execution result (truncated if large)
tool.error        - Error message (if failed)
```

#### Guard Attributes

```
guard.name       - Guard class name
guard.passed     - Boolean (true/false)
guard.violation  - Violation reason (if failed)
```

#### Memory Attributes

```
session.id       - Session identifier
message.count    - Number of messages loaded/saved
```

#### HTTP Attributes

```
http.method           - HTTP method (POST)
http.url              - Request URL
http.status_code      - Response status code
http.timing.connect   - Connection time (seconds)
http.timing.total     - Total request time (seconds)
```

### Span Naming Conventions

```
agent.prompt         - Agent prompt operation
agent.stream         - Streaming operation
llm.request          - LLM provider call
tool.execute         - Tool execution
guard.check          - Guard validation
memory.load          - Memory retrieval
memory.save          - Memory persistence
workflow.pipeline    - Pipeline execution
workflow.step        - Individual workflow step
workflow.chain       - Chain execution
http.request         - HTTP client request
```

---

## Configuration Reference

### Per-Agent Configuration

Enable/disable telemetry per agent:

```php
// Enable for specific agent
agent('bot1')->telemetry(true)->prompt('Hello');

// Disable for specific agent
agent('bot2')->telemetry(false)->prompt('Hello');
```

### Global Configuration

Configure telemetry globally with `TelemetryManager`:

```php
use Pagent\Observability\TelemetryManager;

TelemetryManager::instance()->initialize([
    // Service identification
    'service_name' => 'my-app',
    'service_version' => '1.0.0',

    // Enable/disable
    'enabled' => true,

    // Exporter selection
    'exporter' => 'jaeger',  // 'jaeger', 'zipkin', 'otlp', 'console', 'inmemory'

    // Sampling (0.0 = none, 1.0 = all)
    'sampling_rate' => 1.0,

    // Exporter-specific config
    'jaeger' => [
        'endpoint' => 'http://localhost:14268/api/traces',
    ],
    'otlp' => [
        'endpoint' => 'http://localhost:4318/v1/traces',
        'headers' => ['x-api-key' => 'secret'],
    ],
    'zipkin' => [
        'endpoint' => 'http://localhost:9411/api/v2/spans',
    ],
]);
```

### Environment-Based Configuration

```php
// .env file
TELEMETRY_ENABLED=true
TELEMETRY_EXPORTER=jaeger
JAEGER_ENDPOINT=http://localhost:14268/api/traces
SERVICE_NAME=my-agent-app
SERVICE_VERSION=1.0.0

// Configuration
TelemetryManager::instance()->initialize([
    'enabled' => $_ENV['TELEMETRY_ENABLED'] === 'true',
    'exporter' => $_ENV['TELEMETRY_EXPORTER'] ?? 'console',
    'service_name' => $_ENV['SERVICE_NAME'] ?? 'pagent',
    'service_version' => $_ENV['SERVICE_VERSION'] ?? '0.0.0',
    'jaeger' => [
        'endpoint' => $_ENV['JAEGER_ENDPOINT'] ?? 'http://localhost:14268/api/traces',
    ],
]);
```

---

## Production Deployment

### Best Practices

#### 1. Use Sampling in High-Traffic Apps

```php
TelemetryManager::instance()->initialize([
    'enabled' => true,
    'sampling_rate' => 0.1,  // Sample 10% of requests
    'exporter' => 'otlp',
]);
```

#### 2. Set Service Metadata

```php
TelemetryManager::instance()->initialize([
    'service_name' => 'agent-api',
    'service_version' => '2.1.0',
    'service_namespace' => 'production',
    'deployment_environment' => 'us-east-1',
]);
```

#### 3. Use OTLP for Vendor-Neutral Export

```php
// Works with Datadog, New Relic, Honeycomb, etc.
telemetry_otlp($_ENV['OTLP_ENDPOINT']);
```

#### 4. Graceful Shutdown

```php
use Pagent\Observability\TelemetryManager;

// At application shutdown
register_shutdown_function(function() {
    TelemetryManager::instance()->shutdown();
});
```

#### 5. Performance Monitoring

- Overhead: <5ms per span when enabled
- Zero overhead when disabled (NullSpan pattern)
- Batch export to minimize latency impact

### Docker Compose Example

```yaml
version: '3.8'

services:
  app:
    build: .
    environment:
      TELEMETRY_ENABLED: "true"
      TELEMETRY_EXPORTER: "jaeger"
      JAEGER_ENDPOINT: "http://jaeger:14268/api/traces"
    depends_on:
      - jaeger

  jaeger:
    image: jaegertracing/all-in-one:latest
    ports:
      - "16686:16686"  # UI
      - "14268:14268"  # Collector
```

### Kubernetes Example

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: telemetry-config
data:
  TELEMETRY_ENABLED: "true"
  TELEMETRY_EXPORTER: "otlp"
  OTLP_ENDPOINT: "http://otel-collector:4318/v1/traces"

---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: agent-app
spec:
  template:
    spec:
      containers:
      - name: app
        envFrom:
        - configMapRef:
            name: telemetry-config
```

---

## Troubleshooting

### No Spans Appearing

**Check 1: Telemetry Enabled**

```php
// Ensure telemetry is enabled on agent
agent('bot')->telemetry(true)->prompt('Test');

// Verify global telemetry is initialized
TelemetryManager::instance()->initialize(['enabled' => true]);
```

**Check 2: Exporter Configured**

```php
// Verify exporter is set
telemetry_console(verbose: true);  // Should show output

// Or check Jaeger endpoint
telemetry_jaeger('http://localhost:14268/api/traces');
```

**Check 3: Network Connectivity**

```bash
# Test Jaeger endpoint
curl -X POST http://localhost:14268/api/traces

# Test OTLP endpoint
curl -X POST http://localhost:4318/v1/traces
```

### Console Exporter Not Showing Output

```php
// Make sure verbose is enabled
telemetry_console(verbose: true);

// Check if telemetry is enabled on agent
$agent = agent('test')->telemetry(true);
echo $agent->telemetryEnabled ? "Enabled\n" : "Disabled\n";
```

### Spans Missing Attributes

**Issue:** Spans created but missing `gen_ai.*` attributes

**Solution:** Ensure provider is set correctly

```php
// Bad: No provider set
agent('bot')->telemetry(true)->prompt('Test');

// Good: Provider configured
agent('bot')->provider('anthropic')->telemetry(true)->prompt('Test');
```

### Performance Impact

**Issue:** High latency when telemetry enabled

**Solutions:**

1. **Reduce Sampling Rate**

```php
TelemetryManager::instance()->initialize([
    'sampling_rate' => 0.1,  // Sample 10% instead of 100%
]);
```

2. **Use Batch Export**

```php
// OTLP uses batch export by default
telemetry_otlp('http://localhost:4318/v1/traces');
```

3. **Disable in Development**

```php
$enabled = $_ENV['APP_ENV'] === 'production';
TelemetryManager::instance()->initialize(['enabled' => $enabled]);
```

### Jaeger Connection Refused

```bash
# Ensure Jaeger is running
docker ps | grep jaeger

# Start Jaeger if not running
docker run -d \
  -p 16686:16686 \
  -p 14268:14268 \
  --name jaeger \
  jaegertracing/all-in-one:latest
```

### Testing Telemetry

Use InMemoryExporter for testing:

```php
use Pagent\Observability\TelemetryManager;
use Pagent\Observability\Exporters\InMemoryExporter;

// Create in-memory exporter
$exporter = new InMemoryExporter();

TelemetryManager::instance()->initialize([
    'enabled' => true,
    'exporter' => 'inmemory',
    'inmemory' => ['instance' => $exporter],
]);

// Run your agent
agent('test')->telemetry(true)->prompt('Hello');

// Inspect spans
$spans = $exporter->getSpans();
foreach ($spans as $span) {
    echo "Span: {$span->getName()}\n";
    echo "Duration: {$span->getDuration()}s\n";
    print_r($span->getAttributes());
}
```

---

## Advanced Topics

### Custom Span Attributes

Add custom attributes to spans:

```php
use Pagent\Observability\TelemetryManager;

// Start custom span
$span = TelemetryManager::instance()->startSpan('custom.operation', [
    'user.id' => 'user-123',
    'request.id' => 'req-456',
]);

// Do work...
sleep(1);

// End span
$span->end();
```

### Recording Exceptions

```php
use Pagent\Observability\TelemetryManager;

$span = TelemetryManager::instance()->startAgentSpan('risky-operation', 'bot');

try {
    // Risky operation
    throw new RuntimeException('Something went wrong');
} catch (Throwable $e) {
    $span->recordException($e);
    $span->setStatus('error', $e->getMessage());
    throw $e;
} finally {
    $span->end();
}
```

### Span Events

Add events to spans for detailed tracking:

```php
$span = TelemetryManager::instance()->startAgentSpan('prompt', 'bot');

$span->addEvent('prompt.preprocessing', ['input_length' => 150]);
$span->addEvent('guard.checking', ['guard_count' => 3]);
$span->addEvent('llm.calling', ['provider' => 'anthropic']);
$span->addEvent('response.received', ['output_length' => 500]);

$span->end();
```

---

## References

- [OpenTelemetry Specification](https://opentelemetry.io/docs/specs/otel/)
- [OpenTelemetry Semantic Conventions for GenAI](https://opentelemetry.io/docs/specs/semconv/gen-ai/)
- [Jaeger Documentation](https://www.jaegertracing.io/docs/)
- [Zipkin Documentation](https://zipkin.io/pages/documentation.html)
- [OTLP Protocol Specification](https://opentelemetry.io/docs/specs/otlp/)

---

## See Also

- [Examples](../examples/) - Working code examples
- [Testing](../tests/Integration/Observability/) - Integration test examples
- [API Documentation](../src/Observability/) - Source code reference
