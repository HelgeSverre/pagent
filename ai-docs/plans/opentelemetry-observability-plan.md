# OpenTelemetry Observability Implementation Plan

**Feature:** Comprehensive OpenTelemetry instrumentation for distributed tracing
**Target Version:** v0.7.0
**Estimated Effort:** 10-15 hours
**Priority:** HIGH - Production observability
**Status:** 📋 Ready for Implementation
**Created:** 2025-10-29

---

## Executive Summary

Implement comprehensive OpenTelemetry instrumentation for Pagent to enable distributed tracing, performance monitoring, and deep observability into LLM agent interactions. Allows integration with Jaeger, Zipkin, Datadog, New Relic, Langfuse, Langsmith, and Phoenix.

**Key Benefits:**
- **Debugging**: Visualize multi-agent workflows
- **Performance**: Track latency and identify bottlenecks
- **Cost Visibility**: Monitor token usage in real-time
- **Compliance**: Audit trail of LLM interactions
- **Integration**: Works with existing observability stacks

---

## Architecture Overview

```
Agent Layer
├── Agent::telemetry(enabled)
├── Agent::prompt() [instrumented]
├── Agent::stream() [instrumented]
└── Agent::executeTool() [instrumented]

TelemetryManager (Singleton)
├── initialize(config)
├── startSpan(name, attributes)
├── startAgentSpan(operation, agentName)
├── startLLMSpan(provider, model)
├── startToolSpan(toolName, arguments)
└── shutdown()

Span Wrapper
├── setAttribute(key, value)
├── setAttributes(array)
├── addEvent(name, attributes)
├── recordException(throwable)
├── setStatus(code, description)
├── end()
└── getContext() → SpanContext

Exporters
├── ConsoleExporter (development)
├── OTLPExporter (generic)
├── JaegerExporter
├── ZipkinExporter
└── LangfuseExporter (future)
```

### Data Flow

1. User calls `agent()->prompt()`
2. TelemetryManager starts `agent.prompt` span
3. Middleware runs (telemetry context added)
4. Provider LLM call → child span `llm.request`
5. Tool calls detected → child spans `tool.execute`
6. Guard checks → child spans `guard.check`
7. Response received → span attributes updated
8. Span ends → exported to configured backends

---

## Implementation Phases

### Phase 1: Core Infrastructure (3-4 hours)

**Goal:** Basic telemetry with console exporter

#### Files to Create:

**1. TelemetryManager Singleton**
- File: `src/Observability/TelemetryManager.php`
- Responsibilities:
  - Initialize OpenTelemetry SDK
  - Create and manage spans
  - Register exporters
  - Handle context propagation
- Key Methods:
  - `initialize(array $config): self` - Setup OTel with exporter
  - `startSpan(string $name, array $attrs): Span` - Create span
  - `startAgentSpan(string $op, string $name): Span` - Agent-specific
  - `startLLMSpan(string $provider, string $model): Span` - LLM-specific
  - `startToolSpan(string $toolName, array $args): Span` - Tool-specific
  - `clearContext(): void` - Reset context (testing)
  - `shutdown(): void` - Flush spans

**2. Span Wrapper**
- File: `src/Observability/Span.php`
- Wraps OpenTelemetry SpanInterface for fluent API
- Methods:
  - `setAttribute(string $key, mixed $value): self`
  - `setAttributes(array $attributes): self`
  - `addEvent(string $name, array $attrs = []): self`
  - `recordException(\Throwable $e): self`
  - `setStatus(string $code, string $desc = ''): self`
  - `end(): void`
  - `getContext(): SpanContext`

**3. SpanContext**
- File: `src/Observability/SpanContext.php`
- Wraps OpenTelemetry SpanContextInterface
- Methods:
  - `getOtelContext(): SpanContextInterface`
  - `getTraceId(): string`
  - `getSpanId(): string`
  - `isValid(): bool`

**4. NullSpan**
- File: `src/Observability/NullSpan.php`
- No-op implementation when telemetry disabled
- All methods return `$this` or do nothing
- Zero overhead when disabled

**5. ConsoleExporter**
- File: `src/Observability/Exporters/ConsoleExporter.php`
- Implements `ExporterInterface`
- Outputs spans to console for debugging
- Methods:
  - `export(iterable $spans): FutureInterface`
  - `shutdown(): bool`
  - `forceFlush(): bool`

**6. Global Helper Functions**
- File: Add to `src/functions.php`
```php
function telemetry(array $config = []): void
function telemetry_console(bool $verbose = false): void
function telemetry_jaeger(string $endpoint): void
function telemetry_otlp(string $endpoint): void
```

**Tests Required (10 tests):**
- `tests/Unit/Observability/TelemetryManagerTest.php`
  - it returns singleton instance
  - it initializes with default config
  - it can be disabled
  - it returns NullSpan when disabled
  - it returns real Span when enabled
  - it creates agent/LLM/tool spans
  - it propagates context to child spans
  - it clears context
  - it shuts down gracefully

### Phase 2: Agent Integration (3-4 hours)

**Goal:** Automatic instrumentation of Agent operations

#### Agent.php Modifications:

**Add Properties:**
```php
private bool $telemetryEnabled = false;
private ?Span $currentSpan = null;
```

**Add Methods:**
```php
public function telemetry(bool $enabled = true): self
private function startOperationSpan(string $operation, array $attrs): Span
private function callProviderWithTelemetry(string $msg, array $opts, Span $parent): object
private function handleToolCallsWithTelemetry(object $response, Span $parent): object
private function runGuardsWithTelemetry(string $input, string $output, Span $parent): void
```

**Modify `prompt()` Method:**
- Start agent operation span at beginning
- Wrap provider call with LLM span
- Wrap tool execution with tool spans
- Wrap guard checks with guard spans
- Add memory events (loaded/saved)
- Add context pruning events
- Set final attributes (tokens, cost)
- Record exceptions
- End span

**Integration Points:**
- **Line ~140**: Start agent span
- **Line ~183**: Wrap provider call
- **Line ~200**: Wrap tool handling
- **Line ~215**: Wrap guard checks
- **Line ~235**: Set final attributes

**Tests Required (8 tests):**
- `tests/Integration/Observability/AgentTelemetryTest.php`
  - it creates span for prompt operation
  - it creates LLM span for provider call
  - it creates tool execution spans
  - it propagates context across operations
  - it records exceptions in spans
  - it tracks memory operations
  - it works with streaming
  - it works when disabled

### Phase 3: Workflow Integration (2 hours)

**Goal:** Multi-agent workflow tracing

#### Pipeline.php Modifications:

**Modify `run()` Method:**
- Start workflow.pipeline span
- For each step:
  - Start workflow.step child span
  - Execute step (agent or transform)
  - Set step attributes (duration, tokens)
  - End step span
- Set workflow attributes (total duration, tokens)
- End workflow span

**Integration Points:**
- `src/Workflow/Pipeline.php` - Add span creation in `run()`
- `src/Workflow/Chain.php` - Similar span creation

**Tests Required (5 tests):**
- `tests/Integration/Observability/WorkflowTelemetryTest.php`
  - it traces multi-step pipeline
  - it links parent-child spans
  - it records step failures
  - it includes step metadata
  - it works with transform steps

### Phase 4: Exporters (2-3 hours)

**Goal:** Production-ready exporters

#### Files to Create:

**1. Base Interface**
- File: `src/Observability/Exporters/ExporterInterface.php`
- Extends OpenTelemetry's `SpanExporterInterface`

**2. OTLPExporter**
- File: `src/Observability/Exporters/OTLPExporter.php`
- Uses `open-telemetry/exporter-otlp` package
- Supports HTTP and gRPC

**3. JaegerExporter**
- File: `src/Observability/Exporters/JaegerExporter.php`
- Uses `open-telemetry/contrib-jaeger` package
- Default endpoint: http://localhost:14268/api/traces

**4. ZipkinExporter**
- File: `src/Observability/Exporters/ZipkinExporter.php`
- Uses `open-telemetry/contrib-zipkin` package
- Default endpoint: http://localhost:9411/api/v2/spans

**Tests Required (4 tests):**
- `tests/Unit/Observability/Exporters/ConsoleExporterTest.php`
  - it exports spans to console
  - it shows attributes in verbose mode
  - it can be shut down

---

## OpenTelemetry Semantic Conventions

Following OpenTelemetry Semantic Conventions for GenAI:

```php
// Span attributes
[
    // Standard OTel
    'service.name' => 'pagent',
    'service.version' => '0.7.0',

    // Agent context
    'agent.name' => 'assistant',
    'agent.session_id' => 'session-123',
    'agent.operation' => 'prompt',

    // GenAI specific
    'gen_ai.system' => 'anthropic',
    'gen_ai.request.model' => 'claude-sonnet-4-20250514',
    'gen_ai.request.temperature' => 0.7,
    'gen_ai.request.max_tokens' => 1024,
    'gen_ai.response.model' => 'claude-sonnet-4-20250514',
    'gen_ai.usage.input_tokens' => 150,
    'gen_ai.usage.output_tokens' => 75,
    'gen_ai.usage.total_tokens' => 225,
    'gen_ai.operation.name' => 'chat',

    // Tool execution
    'tool.name' => 'calculator',
    'tool.arguments' => '{"a": 5, "b": 3}',
    'tool.result_type' => 'int',

    // Guard checks
    'guard.name' => 'PIIGuard',
    'guard.passed' => true,
]
```

---

## API Examples

### 1. Basic Console Debugging

```php
use function Pagent\{agent, telemetry_console};

// Enable console telemetry
telemetry_console(verbose: true);

$response = agent('assistant')
    ->provider('anthropic')
    ->telemetry(true)
    ->prompt('Hello!');

// Console shows:
// ┌─ Span: agent.prompt
// │  Duration: 1.23 s
// │  Attributes:
// │    - agent.name: assistant
// │    - gen_ai.usage.total_tokens: 125
// └─
```

### 2. Jaeger Integration

```php
use function Pagent\{agent, telemetry_jaeger};

telemetry_jaeger('http://localhost:14268/api/traces');

agent('researcher')
    ->provider('anthropic')
    ->telemetry(true)
    ->tool('web_fetch', 'Fetch URL', fn($url) => file_get_contents($url))
    ->prompt('Research PHP trends');

// View at http://localhost:16686 (Jaeger UI)
```

### 3. Multi-Agent Workflow

```php
use function Pagent\{agent, pipeline, telemetry_otlp};

telemetry_otlp('http://localhost:4318/v1/traces');

agent('researcher')->provider('anthropic')->telemetry(true);
agent('writer')->provider('anthropic')->telemetry(true);
agent('editor')->provider('anthropic')->telemetry(true);

$result = pipeline('content-creation')
    ->step('research', agent('researcher'))
    ->step('write', agent('writer'))
    ->step('edit', agent('editor'))
    ->run('Write article about PHP agents');

// All operations visible in single trace:
// workflow.pipeline
//   ├─ workflow.step (research)
//   │  └─ agent.prompt → llm.request → tool.execute
//   ├─ workflow.step (write)
//   │  └─ agent.prompt → llm.request
//   └─ workflow.step (edit)
//      └─ agent.prompt → llm.request
```

### 4. Error Tracking

```php
telemetry_console();

try {
    agent('buggy')
        ->provider('anthropic')
        ->telemetry(true)
        ->guard('pii')
        ->prompt('My SSN is 123-45-6789');
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
}

// Span shows error:
// ┌─ Span: agent.prompt
// │  ├─ guard.check
// │  │  Status: ERROR
// │  │  Exception: PIIGuard failed
// │  Status: ERROR
// └─
```

---

## Comprehensive Test Cases

### Unit Tests (20-25 tests)

**TelemetryManagerTest.php:**
1. Returns singleton instance
2. Initializes with default config
3. Can be disabled
4. Returns NullSpan when disabled
5. Returns real Span when enabled
6. Creates agent spans with attributes
7. Creates LLM spans with semantic attributes
8. Creates tool spans
9. Propagates context to child spans
10. Clears context
11. Shuts down gracefully

**SpanTest.php:**
1. Sets single attribute
2. Sets multiple attributes
3. Adds events
4. Records exceptions
5. Sets status (ok/error)
6. Provides context for propagation
7. Ends properly

**NullSpanTest.php:**
1. Does nothing on setAttribute
2. Does nothing on addEvent
3. Does nothing on recordException
4. Does nothing on end
5. Returns null context
6. Can be chained

**ConsoleExporterTest.php:**
1. Exports spans to console
2. Shows attributes in verbose mode
3. Can be shut down

### Integration Tests (10-15 tests)

**AgentTelemetryTest.php:**
1. Creates span for prompt operation
2. Creates LLM span for provider call
3. Creates tool execution spans
4. Propagates context across operations
5. Records exceptions in spans
6. Tracks memory operations
7. Works with streaming
8. Works when disabled

**WorkflowTelemetryTest.php:**
1. Traces multi-step pipeline
2. Links parent-child spans
3. Records step failures
4. Includes step metadata
5. Works with transform steps

### Total Test Count: 30-40 tests

---

## Configuration

### Basic Setup

```php
use Pagent\Observability\TelemetryManager;

TelemetryManager::instance()->initialize([
    'service_name' => 'my-app',
    'service_version' => '1.0.0',
    'enabled' => true,
    'exporter' => 'console',  // or 'otlp', 'jaeger', 'zipkin'
    'sampling_rate' => 1.0,   // 100% sampling
]);
```

### OTLP Configuration

```php
TelemetryManager::instance()->initialize([
    'enabled' => true,
    'exporter' => 'otlp',
    'otlp' => [
        'endpoint' => 'http://localhost:4318/v1/traces',
        'headers' => ['x-api-key' => 'secret'],
    ],
]);
```

### Jaeger Configuration

```php
TelemetryManager::instance()->initialize([
    'enabled' => true,
    'exporter' => 'jaeger',
    'jaeger' => [
        'endpoint' => 'http://localhost:14268/api/traces',
    ],
]);
```

---

## Dependencies

### Required Composer Packages

```json
{
  "require": {
    "open-telemetry/sdk": "^1.0",
    "open-telemetry/api": "^1.0",
    "open-telemetry/sem-conv": "^1.0",
    "open-telemetry/exporter-otlp": "^1.0"
  },
  "suggest": {
    "open-telemetry/contrib-jaeger": "For Jaeger export",
    "open-telemetry/contrib-zipkin": "For Zipkin export"
  }
}
```

---

## Implementation Timeline

### Day 1-2: Core Infrastructure (3-4 hours)
- Create TelemetryManager, Span, SpanContext, NullSpan
- Create ConsoleExporter
- Add global helper functions
- Write 10 unit tests

### Day 3-4: Agent Integration (3-4 hours)
- Modify Agent::prompt() with telemetry
- Add LLM request spans
- Add tool execution spans
- Add guard check spans
- Write 8 integration tests

### Day 5: Workflow Integration (2 hours)
- Modify Pipeline::run() with telemetry
- Add step spans
- Write 5 workflow tests

### Day 6-7: Exporters (2-3 hours)
- Implement OTLPExporter
- Implement JaegerExporter
- Implement ZipkinExporter
- Write 4 exporter tests

### Day 8: Documentation & Examples (1-2 hours)
- Write user guide (`docs/observability.md`)
- Create 5 example scripts
- Update README

**Total: 10-15 hours**

---

## Success Criteria

### Functionality
- ✅ Automatic instrumentation of all Agent operations
- ✅ Distributed tracing across multi-agent workflows
- ✅ Tool execution tracking
- ✅ Guard check tracing
- ✅ Streaming support
- ✅ Context propagation works
- ✅ Console/OTLP/Jaeger exporters work
- ✅ Semantic attributes follow OTel conventions
- ✅ Zero-config mode available
- ✅ Can be disabled with zero overhead

### Code Quality
- ✅ 30-40 tests passing
- ✅ PHPStan level 9 compliance
- ✅ All public APIs documented
- ✅ No memory leaks

### Documentation
- ✅ User guide written
- ✅ 5+ working examples
- ✅ API reference complete

### Performance
- ✅ <5ms overhead per span when enabled
- ✅ Zero overhead when disabled

---

## Risks & Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Performance overhead | Medium | Medium | Lazy initialization, NullSpan, efficient builders |
| OpenTelemetry complexity | Medium | Low | Simple wrapper API, zero-config defaults |
| Exporter failures | Medium | Medium | Graceful degradation, retry logic, fallback |
| Memory usage | Low | Low | Immediate export, batch processors |

---

## Future Enhancements (Post v0.7.0)

### v0.8.0
- Metrics (counters, gauges, histograms)
- Automatic cost tracking in spans
- Span links for complex workflows
- Custom span processors

### v0.9.0
- Langfuse native integration
- Langsmith native integration
- Phoenix native integration
- Custom platform adapters

### v1.0.0
- Distributed context propagation (HTTP headers)
- Multi-service tracing
- Alerting integration
- Custom dashboards

---

## File Checklist

### Files to Create (15-20 files)

```
src/Observability/
├── TelemetryManager.php
├── Span.php
├── SpanContext.php
├── NullSpan.php
├── NullSpanContext.php
└── Exporters/
    ├── ExporterInterface.php
    ├── ConsoleExporter.php
    ├── OTLPExporter.php
    ├── JaegerExporter.php
    └── ZipkinExporter.php

tests/Unit/Observability/
├── TelemetryManagerTest.php
├── SpanTest.php
├── NullSpanTest.php
└── Exporters/
    └── ConsoleExporterTest.php

tests/Integration/Observability/
├── AgentTelemetryTest.php
└── WorkflowTelemetryTest.php

examples/
├── 18-telemetry-console.php
├── 19-telemetry-jaeger.php
├── 20-telemetry-workflow.php
├── 21-telemetry-tools.php
└── 22-telemetry-streaming.php

docs/
└── observability.md
```

### Files to Modify (3 files)

```
src/Agent.php
├── Add property: $telemetryEnabled
├── Add method: telemetry(bool)
├── Add method: startOperationSpan() [private]
├── Add method: callProviderWithTelemetry() [private]
├── Modify method: prompt() [add telemetry]
└── Modify method: handleToolCalls() [add telemetry]

src/Workflow/Pipeline.php
└── Modify method: run() [add telemetry]

src/functions.php
├── Add function: telemetry()
├── Add function: telemetry_console()
├── Add function: telemetry_jaeger()
└── Add function: telemetry_otlp()
```

---

## References

- [OpenTelemetry Specification](https://opentelemetry.io/docs/specs/otel/)
- [OpenTelemetry Semantic Conventions for GenAI](https://opentelemetry.io/docs/specs/semconv/gen-ai/)
- [Langfuse OpenTelemetry Integration](https://langfuse.com/docs/integrations/opentelemetry)
- [Jaeger Documentation](https://www.jaegertracing.io/docs/)
- [OTLP Protocol](https://opentelemetry.io/docs/specs/otlp/)

---

**End of Plan**

This plan is implementation-ready. An AI agent can follow this specification to implement comprehensive OpenTelemetry observability for Pagent with distributed tracing, automatic instrumentation, and integration with industry-standard observability platforms.