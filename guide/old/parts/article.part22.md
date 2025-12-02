# Chapter 22: OpenTelemetry Integration

## What You'll Learn

In this chapter, you'll master observability in Pagent applications using OpenTelemetry. By the end, you'll be able to:

- Configure OpenTelemetry exporters for various backends
- Instrument agent operations with distributed tracing
- Create custom spans for detailed performance analysis
- Track metrics and correlate logs with traces
- Visualize agent workflows in Jaeger and other observability platforms

## Prerequisites

- Completed Chapters 1-9 of the Pagent tutorial
- Basic understanding of distributed systems concepts
- Docker installed (for running observability backends)
- PHP 8.3+ environment with Composer

## Time Estimate

45-60 minutes for full implementation and testing

## Final Result

A fully instrumented Pagent application with distributed tracing, allowing you to visualize and analyze every LLM interaction, tool execution, and custom operation in real-time.

## Part 1: Understanding OpenTelemetry in Pagent

OpenTelemetry provides vendor-neutral APIs and tools for collecting telemetry data. In the context of AI applications, this means tracking:

- **LLM Requests**: Model, prompts, tokens, latency
- **Tool Executions**: Which tools ran, inputs, outputs, duration
- **Agent Operations**: Chains of thought, decision points, fallbacks
- **Custom Spans**: Business logic, data processing, external API calls

Let's start with a minimal example to see telemetry in action:

```php
<?php

require 'vendor/autoload.php';

use Pagent\Observability\TelemetryManager;

// Initialize telemetry with console output
TelemetryManager::instance()->initialize([
    'enabled' => true,
    'service_name' => 'my-ai-app',
    'service_version' => '1.0.0',
    'exporter' => 'console',  // Output to console for development
]);

// Create an instrumented agent
$agent = agent('assistant')
    ->provider(openai())
    ->model('gpt-4o-mini')
    ->telemetry(true)  // Enable telemetry for this agent
    ->prompt('What is OpenTelemetry?');

echo $agent->content;

// Shutdown to flush remaining spans
TelemetryManager::instance()->shutdown();
```

Run this code and observe the console output showing span creation, attributes, and timing information. This visibility is crucial for understanding agent behavior.

## Part 2: Configuring OTLP Exporter

The OpenTelemetry Protocol (OTLP) is the standard for sending telemetry data. Let's configure Pagent to export to an OTLP collector:

```php
<?php

use Pagent\Observability\TelemetryManager;

// Configure OTLP exporter for production
TelemetryManager::instance()->initialize([
    'enabled' => true,
    'service_name' => 'pagent-production',
    'service_version' => '2.0.0',
    'exporter' => 'otlp',
    'otlp' => [
        'endpoint' => 'http://localhost:4318/v1/traces',
        'headers' => [
            'x-api-key' => $_ENV['OTLP_API_KEY'] ?? '',
        ],
        'compression' => 'gzip',
        'timeout' => 10.0,
        'content_type' => 'application/x-protobuf',
    ],
]);
```

### Setting Up Local Observability Stack

Create a `docker-compose.yml` for local development:

```yaml
version: "3.8"

services:
  # OpenTelemetry Collector
  otel-collector:
    image: otel/opentelemetry-collector-contrib:latest
    ports:
      - "4318:4318" # OTLP HTTP
      - "4317:4317" # OTLP gRPC
    volumes:
      - ./otel-config.yaml:/etc/otelcol-contrib/config.yaml
    command: ["--config=/etc/otelcol-contrib/config.yaml"]

  # Jaeger for trace visualization
  jaeger:
    image: jaegertracing/all-in-one:latest
    ports:
      - "16686:16686" # Jaeger UI
      - "14250:14250" # gRPC for collector
    environment:
      - COLLECTOR_OTLP_ENABLED=true

  # Prometheus for metrics
  prometheus:
    image: prom/prometheus:latest
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
```

Create the collector configuration `otel-config.yaml`:

```yaml
receivers:
  otlp:
    protocols:
      grpc:
        endpoint: 0.0.0.0:4317
      http:
        endpoint: 0.0.0.0:4318

exporters:
  jaeger:
    endpoint: jaeger:14250
    tls:
      insecure: true

  prometheus:
    endpoint: "0.0.0.0:8889"

processors:
  batch:

service:
  pipelines:
    traces:
      receivers: [otlp]
      processors: [batch]
      exporters: [jaeger]

    metrics:
      receivers: [otlp]
      processors: [batch]
      exporters: [prometheus]
```

Start the stack:

```bash
docker-compose up -d
```

Now your Pagent application will send traces to Jaeger. Access the UI at http://localhost:16686.

## Part 3: Custom Instrumentation

While Pagent automatically instruments agent operations, you'll often need custom spans for business logic:

```php
<?php

use Pagent\Observability\TelemetryManager;

class DocumentProcessor
{
    private TelemetryManager $telemetry;

    public function __construct()
    {
        $this->telemetry = TelemetryManager::instance();
    }

    public function processDocument(string $documentId): array
    {
        // Start a custom span for the entire operation
        $span = $this->telemetry->startSpan('document.process', [
            'document.id' => $documentId,
            'document.type' => 'pdf',
        ]);

        try {
            // Extract text with a child span
            $extractSpan = $this->telemetry->startSpan('document.extract', [
                'extraction.method' => 'ocr',
            ]);

            $text = $this->extractText($documentId);

            $extractSpan->setAttribute('extraction.char_count', strlen($text))
                       ->setStatus('ok')
                       ->end();

            // Analyze with AI agent (automatically instrumented)
            $agent = agent('analyzer')
                ->provider(anthropic())
                ->model('claude-3-5-haiku-20241022')
                ->telemetry(true);

            $analysis = $agent->prompt("Analyze this document: {$text}");

            // Store results with a span
            $storeSpan = $this->telemetry->startSpan('document.store');

            $results = $this->storeResults($documentId, $analysis->content);

            $storeSpan->addEvent('results_stored', [
                'storage.location' => 's3',
                'storage.size_bytes' => strlen(json_encode($results)),
            ])->end();

            // Success - mark parent span as OK
            $span->setStatus('ok', 'Document processed successfully');

            return $results;

        } catch (\Exception $e) {
            // Record exception in span
            $span->recordException($e)
                 ->setStatus('error', $e->getMessage());

            throw $e;
        } finally {
            // Always end the span
            $span->end();
        }
    }

    private function extractText(string $documentId): string
    {
        // Simulate text extraction
        sleep(1);
        return "Sample document text for {$documentId}";
    }

    private function storeResults(string $documentId, string $analysis): array
    {
        // Simulate storing results
        return [
            'document_id' => $documentId,
            'analysis' => $analysis,
            'timestamp' => time(),
        ];
    }
}
```

This example demonstrates:

- Creating parent and child spans for operation hierarchy
- Setting attributes for searchability
- Adding events for important milestones
- Proper error handling with exception recording
- Status codes for success/failure indication

## Part 4: Instrumenting Tool Executions

Pagent automatically creates spans for tool executions, but you can add custom telemetry within tools:

```php
<?php

use Pagent\Tools\Tool;
use Pagent\Observability\TelemetryManager;

class DatabaseQueryTool extends Tool
{
    protected string $name = 'database_query';
    protected string $description = 'Execute database queries';

    public function execute(array $arguments): mixed
    {
        $telemetry = TelemetryManager::instance();

        // Tool span is automatically created by Pagent
        // Add a custom child span for detailed tracking
        $querySpan = $telemetry->startSpan('database.query', [
            'db.system' => 'postgresql',
            'db.name' => 'production',
            'db.statement' => $arguments['query'],
        ]);

        try {
            $startTime = microtime(true);

            // Execute query
            $results = $this->runQuery($arguments['query']);

            $duration = microtime(true) - $startTime;

            // Add performance metrics
            $querySpan->setAttribute('db.rows_affected', count($results))
                      ->setAttribute('db.duration_ms', $duration * 1000)
                      ->addEvent('query_executed', [
                          'cache_hit' => $this->wasCacheHit(),
                          'execution_plan' => $this->getExecutionPlan(),
                      ]);

            // Check for slow queries
            if ($duration > 1.0) {
                $querySpan->addEvent('slow_query_detected', [
                    'threshold_ms' => 1000,
                    'actual_ms' => $duration * 1000,
                ]);
            }

            $querySpan->setStatus('ok');

            return $results;

        } catch (\PDOException $e) {
            $querySpan->recordException($e)
                      ->setStatus('error', 'Database error: ' . $e->getMessage());
            throw $e;
        } finally {
            $querySpan->end();
        }
    }

    private function runQuery(string $query): array
    {
        // Simulate database query
        usleep(rand(10000, 500000)); // 10-500ms
        return [
            ['id' => 1, 'name' => 'Result 1'],
            ['id' => 2, 'name' => 'Result 2'],
        ];
    }

    private function wasCacheHit(): bool
    {
        return rand(0, 10) > 7; // 30% cache hit rate
    }

    private function getExecutionPlan(): string
    {
        return 'Seq Scan on users (cost=0.00..10.50 rows=500)';
    }
}

// Use the instrumented tool
$agent = agent('data-assistant')
    ->provider(openai())
    ->telemetry(true)
    ->tool(new DatabaseQueryTool())
    ->prompt('Find all users created in the last week');
```

## Part 5: Correlating Traces Across Services

For distributed systems, maintain trace context across service boundaries:

```php
<?php

use Pagent\Observability\TelemetryManager;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;

class TraceContextPropagator
{
    /**
     * Extract trace context from HTTP headers
     */
    public static function extractFromHeaders(array $headers): void
    {
        if (isset($headers['traceparent'])) {
            // Parse W3C Trace Context format
            $parts = explode('-', $headers['traceparent']);
            if (count($parts) === 4) {
                // Reconstruct context and set as current
                // This would use OpenTelemetry's propagation API
                // Simplified for illustration
            }
        }
    }

    /**
     * Inject trace context into outgoing HTTP request
     */
    public static function injectIntoRequest($client, $request): void
    {
        $telemetry = TelemetryManager::instance();

        if ($telemetry->isEnabled()) {
            $context = Context::getCurrent();
            $span = \OpenTelemetry\API\Trace\Span::fromContext($context);

            if ($span->getContext()->isValid()) {
                $traceId = $span->getContext()->getTraceId();
                $spanId = $span->getContext()->getSpanId();
                $flags = $span->getContext()->getTraceFlags();

                // W3C Trace Context format
                $traceparent = sprintf('00-%s-%s-%02x', $traceId, $spanId, $flags);

                $request->withHeader('traceparent', $traceparent);
            }
        }

        return $request;
    }
}

// Example: Microservice handling
class ApiGateway
{
    public function handleRequest($request): void
    {
        // Extract trace context from incoming request
        TraceContextPropagator::extractFromHeaders($request->headers);

        // Start span for this service's work
        $span = TelemetryManager::instance()->startSpan('api.handle_request', [
            'http.method' => $request->method,
            'http.url' => $request->url,
        ]);

        try {
            // Process with agent
            $agent = agent('api-processor')
                ->telemetry(true)
                ->prompt($request->body);

            // Make downstream service call
            $httpClient = new \GuzzleHttp\Client();
            $downstreamRequest = new \GuzzleHttp\Psr7\Request(
                'POST',
                'http://downstream-service/process'
            );

            // Propagate trace context
            $downstreamRequest = TraceContextPropagator::injectIntoRequest(
                $httpClient,
                $downstreamRequest
            );

            $response = $httpClient->send($downstreamRequest);

            $span->setStatus('ok');

        } finally {
            $span->end();
        }
    }
}
```

## Part 6: Performance Monitoring Pattern

Create a comprehensive monitoring setup for production:

```php
<?php

use Pagent\Observability\TelemetryManager;

class ObservableAgent
{
    private array $metrics = [];

    public function createAgent(string $name, array $config): Agent
    {
        $telemetry = TelemetryManager::instance();

        // Start operation span
        $span = $telemetry->startSpan('agent.create', [
            'agent.name' => $name,
            'agent.provider' => $config['provider'] ?? 'unknown',
        ]);

        try {
            $startTime = microtime(true);

            $agent = agent($name)
                ->provider($this->getProvider($config))
                ->model($config['model'] ?? 'gpt-4o-mini')
                ->temperature($config['temperature'] ?? 0.7)
                ->maxTokens($config['max_tokens'] ?? 1000)
                ->telemetry(true);

            // Add tools with instrumentation
            if (isset($config['tools'])) {
                foreach ($config['tools'] as $tool) {
                    $agent->tool($this->instrumentTool($tool));
                }
            }

            // Track creation time
            $creationTime = (microtime(true) - $startTime) * 1000;
            $span->setAttribute('agent.creation_time_ms', $creationTime);

            // Record metrics
            $this->recordMetric('agent.created', 1, [
                'provider' => $config['provider'],
                'model' => $config['model'],
            ]);

            // Add success event
            $span->addEvent('agent_created', [
                'config' => json_encode($config),
                'duration_ms' => $creationTime,
            ])->setStatus('ok');

            return $agent;

        } catch (\Exception $e) {
            $span->recordException($e)
                 ->setStatus('error', 'Failed to create agent');

            $this->recordMetric('agent.creation_failed', 1, [
                'error' => get_class($e),
            ]);

            throw $e;
        } finally {
            $span->end();
        }
    }

    private function instrumentTool($tool): $tool
    {
        // Wrap tool execution with telemetry
        return new class($tool) extends Tool {
            private $innerTool;
            private $telemetry;

            public function __construct($tool)
            {
                $this->innerTool = $tool;
                $this->telemetry = TelemetryManager::instance();
            }

            public function execute(array $arguments): mixed
            {
                $span = $this->telemetry->startSpan('tool.wrapper', [
                    'tool.name' => $this->innerTool->getName(),
                    'tool.arguments_size' => strlen(json_encode($arguments)),
                ]);

                $startMemory = memory_get_usage(true);
                $startTime = microtime(true);

                try {
                    $result = $this->innerTool->execute($arguments);

                    // Record performance metrics
                    $span->setAttribute('tool.duration_ms', (microtime(true) - $startTime) * 1000)
                         ->setAttribute('tool.memory_used_bytes', memory_get_usage(true) - $startMemory)
                         ->setStatus('ok');

                    return $result;

                } catch (\Exception $e) {
                    $span->recordException($e)->setStatus('error');
                    throw $e;
                } finally {
                    $span->end();
                }
            }
        };
    }

    private function recordMetric(string $name, float $value, array $labels = []): void
    {
        $this->metrics[] = [
            'name' => $name,
            'value' => $value,
            'labels' => $labels,
            'timestamp' => time(),
        ];
    }

    private function getProvider(array $config): Provider
    {
        return match($config['provider'] ?? 'openai') {
            'anthropic' => anthropic(),
            'openai' => openai(),
            'ollama' => ollama(),
            default => mock(),
        };
    }
}
```

## Troubleshooting Common Issues

### Spans Not Appearing in Jaeger

1. **Verify exporter configuration**: Ensure endpoint is correct
2. **Check network connectivity**: `curl http://localhost:4318/v1/traces`
3. **Enable debug logging**: Set `verbose: true` in telemetry config
4. **Flush spans**: Call `TelemetryManager::instance()->shutdown()`

### High Memory Usage

```php
// Use batch processing for large operations
$telemetry = TelemetryManager::instance();

// Process in chunks with separate spans
foreach (array_chunk($largeDataset, 100) as $index => $chunk) {
    $span = $telemetry->startSpan('batch.process', [
        'batch.index' => $index,
        'batch.size' => count($chunk),
    ]);

    // Process chunk
    $this->processChunk($chunk);

    $span->end();

    // Allow garbage collection between batches
    gc_collect_cycles();
}
```

### Trace Context Lost

Always propagate context when spawning async operations:

```php
// Capture current context before async operation
$context = Context::getCurrent();

// In async callback, restore context
$scope = $context->activate();
try {
    // Your async operation with proper trace context
    $span = $telemetry->startSpan('async.operation');
    // ...
    $span->end();
} finally {
    $scope->detach();
}
```

## Summary

You've now mastered OpenTelemetry integration with Pagent, learning to:

- Configure multiple exporter types (Console, OTLP, Jaeger)
- Create custom spans for business operations
- Instrument tool executions with detailed metrics
- Propagate trace context across service boundaries
- Monitor performance and identify bottlenecks

Your AI applications now have production-grade observability, enabling you to understand exactly how agents make decisions, where time is spent, and how to optimize performance.

## Next Steps

- **Chapter 23**: Advanced error handling and retry strategies
- **Chapter 24**: Building production-ready AI pipelines
- **Learn More**: Explore the [OpenTelemetry PHP documentation](https://opentelemetry.io/docs/languages/php/)
- **Practice**: Add telemetry to your existing Pagent applications

## Additional Resources

- [OpenTelemetry Semantic Conventions](https://opentelemetry.io/docs/specs/semconv/)
- [Jaeger Query Language](https://www.jaegertracing.io/docs/latest/query-language/)
- [Pagent Observability Examples](https://github.com/hgraca/pagent/tree/main/tests/Integration/Observability)
