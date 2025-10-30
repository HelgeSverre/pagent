# Pagent Examples

This directory contains working examples demonstrating Pagent's features.

## Prerequisites

Make sure you have API keys configured in your `.env` file:

```bash
cp ../.env.example ../.env
# Edit .env and add your API keys
```

## Running Examples

### 01 - Basic Chat

Simple conversation examples with different providers:

```bash
php examples/01-basic-chat.php
```

### 02 - Tool Calling

Automatic tool execution with function calling:

```bash
php examples/02-tool-calling.php
```

### 03 - Context & Memory

Conversation history and context management:

```bash
php examples/03-context-memory.php
```

### 04 - Multi-Provider

Using different providers for different tasks:

```bash
php examples/04-multi-provider.php
```

## Telemetry Examples (15-19)

### 15 - Console Telemetry

Basic telemetry with console output. Great for debugging and understanding span structure.

```bash
php examples/15-telemetry-console.php
```

Features:

- Console exporter with verbose mode
- Single and multi-turn conversations
- Span hierarchy visualization

### 16 - Jaeger Integration

Send traces to Jaeger for distributed tracing visualization.

```bash
php examples/16-telemetry-jaeger.php
```

**Prerequisites:**

```bash
docker run -d --name jaeger \
  -p 16686:16686 \
  -p 4318:4318 \
  jaegertracing/all-in-one:latest
```

View traces at: http://localhost:16686

Features:

- Jaeger OTLP exporter
- Agent with tools tracking
- Multi-agent traces
- UI-based trace visualization

### 17 - Multi-Agent Workflow

Distributed tracing across multiple agents in Pipeline workflows.

```bash
php examples/17-telemetry-workflow.php
```

Features:

- Pipeline workflow spans
- Multiple agents coordination
- Parent-child span relationships
- Step-by-step execution tracking

### 18 - Tool Execution Tracking

Track tool executions with arguments, results, and timing.

```bash
php examples/18-telemetry-tools.php
```

Features:

- Tool execution spans
- Arguments and result type tracking
- Error handling in tools
- Performance profiling
- Sequential tool chains

### 19 - Custom OTLP Configuration

Advanced OTLP configuration with headers, endpoints, and authentication.

```bash
php examples/19-telemetry-custom.php
```

Features:

- Custom OTLP endpoints
- Authentication headers (Honeycomb, etc.)
- Zipkin exporter
- Environment-based configuration
- Sampling configuration
- Multiple exporter patterns

**Common OTLP Backends:**

- **Jaeger**: `telemetry_jaeger('http://localhost:4318/v1/traces')`
- **Zipkin**: `telemetry_zipkin('http://localhost:9411/api/v2/spans')`
- **Honeycomb**: `telemetry_otlp('https://api.honeycomb.io/v1/traces', ['x-honeycomb-team' => 'key'])`
- **New Relic**: `telemetry_otlp('https://otlp.nr-data.net/v1/traces', ['api-key' => 'key'])`
- **Custom**: `telemetry_otlp('http://your-collector:4318/v1/traces')`

## Notes

- Examples use `unset($variable)` to trigger AgentBuilder destruction and registration
- Most examples work with OpenAI (always available) and optionally with Anthropic
- Mock provider examples work without any API keys
