# Pagent OpenTelemetry Observability Stack

This Docker Compose setup provides a complete local observability stack for testing Pagent's OpenTelemetry integration with multiple open-source LLM observability tools.

## Quick Start

1. **Copy the environment file** (optional, uses defaults if not present):

   ```bash
   cp docker/observability/.env.example docker/observability/.env
   ```

2. **Start all services**:

   ```bash
   docker-compose -f docker-compose.observability.yml up -d
   ```

3. **Wait for services to be healthy** (30-60 seconds):

   ```bash
   docker-compose -f docker-compose.observability.yml ps
   ```

4. **Run the test integration script**:

   ```bash
   bash docker/observability/test-integration.sh
   ```

5. **Stop all services**:

   ```bash
   docker-compose -f docker-compose.observability.yml down
   ```

6. **Stop and remove all data** (reset everything):
   ```bash
   docker-compose -f docker-compose.observability.yml down -v
   ```

## Included Tools

### 1. Jaeger (General Distributed Tracing)

**Purpose**: Industry-standard distributed tracing visualization

**Access**:

- UI: http://localhost:16686
- OTLP HTTP: http://localhost:4318
- OTLP gRPC: http://localhost:4317

**Features**:

- Trace visualization
- Service dependency graphs
- Performance analysis
- Search and filtering

**Pagent Configuration**:

```php
<?php

use function Pagent\telemetry_otlp;

// Configure Pagent to send traces to Jaeger
telemetry_otlp('http://localhost:4318/v1/traces');

// Create an agent and make API calls - traces will appear in Jaeger
$agent = agent('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->build();

$response = $agent->ask('Explain quantum computing');
```

### 2. Phoenix by Arize (LLM-Specific Observability)

**Purpose**: Specialized LLM observability with prompt tracking and evaluation

**Access**:

- UI: http://localhost:6006
- OTLP Collector: http://localhost:6007

**Features**:

- LLM-specific span visualization
- Prompt and completion tracking
- Token usage monitoring
- Model performance metrics
- Embedding visualization

**Pagent Configuration**:

```php
<?php

use function Pagent\telemetry_otlp;

// Configure Pagent to send traces to Phoenix
telemetry_otlp('http://localhost:6007/v1/traces');

$agent = agent('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->build();

// All LLM interactions will be tracked in Phoenix
$response = $agent->ask('Analyze this sentiment');
```

**Notes**:

- Phoenix automatically detects LLM-specific attributes in traces
- Provides specialized views for prompt engineering
- Supports evaluation datasets

### 3. Langfuse (LLM Monitoring & Prompt Management)

**Purpose**: Production LLM monitoring with prompt versioning and cost tracking

**Access**:

- UI: http://localhost:3000
- API: http://localhost:3000/api/public

**Features**:

- Prompt version management
- Cost tracking and analytics
- User session tracking
- A/B testing support
- Production monitoring dashboards

**Initial Setup**:

1. Visit http://localhost:3000
2. Create an account (first user becomes admin)
3. Create a project
4. Generate API keys (Settings → API Keys)

**Pagent Configuration**:

Using OpenTelemetry (native support):

```php
<?php

use function Pagent\telemetry_otlp;

// Langfuse has native OTLP support
telemetry_otlp('http://localhost:3000/api/public/ingestion');

$agent = agent('anthropic')->model('claude-3-5-sonnet-20241022')->build();
$response = $agent->ask('Summarize this text');
```

Using Langfuse SDK (alternative):

```php
<?php

// If using Langfuse PHP SDK directly
// composer require langfuse/langfuse-php

use Langfuse\Langfuse;

$langfuse = new Langfuse([
    'publicKey' => 'your-public-key',
    'secretKey' => 'your-secret-key',
    'host' => 'http://localhost:3000',
]);

// Wrap Pagent calls with Langfuse traces
$trace = $langfuse->trace(['name' => 'agent-interaction']);
```

**Notes**:

- Langfuse requires account creation on first use
- API keys needed for programmatic access
- Excellent for tracking production usage and costs

### 4. Opik by Comet (LLM Experiment Tracking)

**Purpose**: LLM experiment tracking and evaluation with dataset management

**Access**:

- Frontend UI: http://localhost:5173
- Backend API: http://localhost:8080

**Features**:

- Experiment tracking
- Dataset management
- Evaluation metrics
- Model comparison
- Trace visualization

**Pagent Configuration**:

```php
<?php

use function Pagent\telemetry_otlp;

// Opik backend accepts OTLP traces
telemetry_otlp('http://localhost:8080/v1/traces');

$agent = agent('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->build();

// Track experiments with Opik
$response = $agent->ask('Classify this text');
```

**Notes**:

- Great for comparing different prompts/models
- Supports evaluation datasets
- Can track multiple experiments in parallel

## Comparing Tools

| Feature                | Jaeger          | Phoenix         | Langfuse              | Opik            |
| ---------------------- | --------------- | --------------- | --------------------- | --------------- |
| **Best For**           | General tracing | LLM development | Production monitoring | Experimentation |
| **LLM-Specific**       | ❌              | ✅              | ✅                    | ✅              |
| **Prompt Management**  | ❌              | ⚠️              | ✅                    | ⚠️              |
| **Cost Tracking**      | ❌              | ⚠️              | ✅                    | ⚠️              |
| **Dataset Management** | ❌              | ✅              | ⚠️                    | ✅              |
| **A/B Testing**        | ❌              | ❌              | ✅                    | ✅              |
| **Setup Complexity**   | Low             | Low             | Medium                | Medium          |
| **Auth Required**      | ❌              | ❌              | ✅                    | ❌              |

**Legend**: ✅ Full support | ⚠️ Partial support | ❌ Not supported

## Running Tests with Observability

### Example: Test with Jaeger

```bash
# Start Jaeger
docker-compose -f docker-compose.observability.yml up -d jaeger

# Wait for health check
sleep 10

# Create test script
cat > test-trace.php << 'EOF'
<?php
require 'vendor/autoload.php';

use function Pagent\telemetry_otlp;
use function Pagent\agent;

telemetry_otlp('http://localhost:4318/v1/traces');

$agent = agent('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->build();

$response = $agent->ask('What is 2+2?');
echo $response . "\n";
EOF

# Run test
php test-trace.php

# View traces
echo "Open http://localhost:16686 to view traces"
```

### Example: Compare Multiple Tools

```bash
# Start all services
docker-compose -f docker-compose.observability.yml up -d

# Test with Jaeger
OTEL_ENDPOINT=http://localhost:4318/v1/traces php test-trace.php

# Test with Phoenix
OTEL_ENDPOINT=http://localhost:6007/v1/traces php test-trace.php

# Test with Opik
OTEL_ENDPOINT=http://localhost:8080/v1/traces php test-trace.php

# Compare the same trace across all tools
echo "Jaeger:   http://localhost:16686"
echo "Phoenix:  http://localhost:6006"
echo "Opik:     http://localhost:5173"
```

## Troubleshooting

### Services Not Starting

**Check logs**:

```bash
docker-compose -f docker-compose.observability.yml logs [service-name]
```

**Common issues**:

- Port conflicts: Another service using the same port
- Resource limits: Docker needs more memory (increase in Docker Desktop)
- Database initialization: Wait 30-60 seconds for PostgreSQL/ClickHouse

### Port Conflicts

If ports are already in use, edit `docker-compose.observability.yml` or create a `.env` file:

```bash
# docker/observability/.env
JAEGER_UI_PORT=16687
PHOENIX_UI_PORT=6008
LANGFUSE_UI_PORT=3001
OPIK_FRONTEND_PORT=5174
```

### Traces Not Appearing

1. **Check service health**:

   ```bash
   docker-compose -f docker-compose.observability.yml ps
   ```

2. **Verify endpoint configuration**:

   ```php
   // Make sure the endpoint is correct
   telemetry_otlp('http://localhost:4318/v1/traces'); // Note /v1/traces path
   ```

3. **Check logs**:

   ```bash
   docker-compose -f docker-compose.observability.yml logs jaeger
   ```

4. **Test endpoint manually**:
   ```bash
   curl -v http://localhost:4318/v1/traces
   ```

### Langfuse Database Issues

If Langfuse won't start:

```bash
# Reset Langfuse database
docker-compose -f docker-compose.observability.yml down langfuse langfuse-db
docker volume rm pagent-postgres-data
docker-compose -f docker-compose.observability.yml up -d langfuse-db langfuse
```

### Opik ClickHouse Issues

If Opik backend fails to connect:

```bash
# Check ClickHouse health
docker-compose -f docker-compose.observability.yml exec opik-clickhouse clickhouse-client --query "SELECT 1"

# Reset if needed
docker-compose -f docker-compose.observability.yml down opik-clickhouse opik-backend
docker volume rm pagent-clickhouse-data
docker-compose -f docker-compose.observability.yml up -d opik-clickhouse opik-backend
```

### Memory Issues

If services crash or are slow:

1. **Increase Docker memory** (Docker Desktop → Settings → Resources)
   - Recommended: 8GB minimum for all services
   - 4GB minimum for Jaeger + Phoenix only

2. **Run fewer services**:
   ```bash
   # Start only Jaeger and Phoenix
   docker-compose -f docker-compose.observability.yml up -d jaeger phoenix
   ```

## Development Workflow

### Recommended Setup for Development

**Start with Jaeger + Phoenix**:

```bash
docker-compose -f docker-compose.observability.yml up -d jaeger phoenix
```

These two provide:

- General distributed tracing (Jaeger)
- LLM-specific observability (Phoenix)
- Low resource usage
- No authentication required

**Add Langfuse for Production Testing**:

```bash
docker-compose -f docker-compose.observability.yml up -d langfuse-db langfuse
```

**Add Opik for Experiment Tracking**:

```bash
docker-compose -f docker-compose.observability.yml up -d opik-clickhouse opik-backend opik-frontend
```

### Switching Between Tools

```php
<?php

// Use environment variable for easy switching
$endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT')
    ?: 'http://localhost:4318/v1/traces';

telemetry_otlp($endpoint);

// Now you can easily switch:
// OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:6007/v1/traces php script.php
```

## Data Persistence

All tools use Docker volumes for data persistence:

- `pagent-jaeger-data`: Jaeger traces (temporary storage)
- `pagent-phoenix-data`: Phoenix traces and datasets
- `pagent-postgres-data`: Langfuse database
- `pagent-clickhouse-data`: Opik database

**Keep data between restarts**:

```bash
docker-compose -f docker-compose.observability.yml down
docker-compose -f docker-compose.observability.yml up -d
```

**Reset all data**:

```bash
docker-compose -f docker-compose.observability.yml down -v
```

## Advanced Configuration

### Custom OTLP Attributes

Add custom attributes to traces:

```php
<?php

use function Pagent\telemetry_otlp;

// Configure with custom service name
telemetry_otlp('http://localhost:4318/v1/traces', [
    'service.name' => 'pagent-dev',
    'service.version' => '0.7.0',
    'deployment.environment' => 'local',
]);
```

### Sampling Configuration

For production, configure sampling to reduce data volume:

```php
<?php

// Sample 10% of traces
telemetry_otlp('http://localhost:4318/v1/traces', [
    'sampling.rate' => 0.1,
]);
```

### Batch Configuration

Configure batching for better performance:

```php
<?php

telemetry_otlp('http://localhost:4318/v1/traces', [
    'batch.size' => 100,
    'batch.timeout' => 5000, // milliseconds
]);
```

## Integration with Pagent Tests

### Automated Docker Integration Tests

Pagent includes comprehensive integration tests that automatically verify trace exports to observability backends. These tests handle Docker container lifecycle and verify actual trace data:

```bash
# Run all Docker observability integration tests
./vendor/bin/pest --group=docker

# Run specific backend tests
./vendor/bin/pest --group=jaeger   # Test Jaeger integration
./vendor/bin/pest --group=zipkin   # Test Zipkin integration
./vendor/bin/pest --group=phoenix  # Test Phoenix integration
```

**What the tests do**:

- Automatically start and stop Docker containers
- Wait for services to be healthy
- Send test traces via Pagent
- Query backend APIs to verify traces were received
- Verify trace structure and attributes
- Clean up containers after tests

**Test coverage**:

- ✅ Jaeger OTLP HTTP export and trace verification
- ✅ Jaeger health checks and container lifecycle
- ✅ Multiple agent operations with trace correlation
- ✅ LLM-specific attributes in traces
- ⚠️ Zipkin export (skipped if incompatible format)
- ⚠️ Phoenix export (requires investigation of query API)

**Requirements**:

- Docker installed and running
- Docker Compose available
- Sufficient Docker resources (4GB+ RAM recommended)

**Test file location**: `tests/Integration/Observability/DockerBackendIntegrationTest.php`

### Running Pest Tests with Observability

```bash
# Start observability stack
docker-compose -f docker-compose.observability.yml up -d jaeger

# Run tests with tracing enabled
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318/v1/traces ./vendor/bin/pest --group=api

# View test traces in Jaeger
open http://localhost:16686
```

### Test Configuration

Add to `phpunit.xml`:

```xml
<php>
    <env name="OTEL_EXPORTER_OTLP_ENDPOINT" value="http://localhost:4318/v1/traces"/>
    <env name="OTEL_SERVICE_NAME" value="pagent-tests"/>
</php>
```

## Resources

### Documentation

- **Jaeger**: https://www.jaegertracing.io/docs/
- **Phoenix**: https://phoenix.arize.com/
- **Langfuse**: https://langfuse.com/docs/
- **Opik**: https://www.comet.com/docs/opik/

### OpenTelemetry

- **Specification**: https://opentelemetry.io/docs/specs/otel/
- **PHP SDK**: https://github.com/open-telemetry/opentelemetry-php
- **Semantic Conventions**: https://opentelemetry.io/docs/specs/semconv/

### Community

- **OpenLLMetry**: https://github.com/traceloop/openllmetry
- **OTEL PHP Examples**: https://github.com/open-telemetry/opentelemetry-php-contrib

## License

This observability stack uses open-source tools with various licenses:

- **Jaeger**: Apache 2.0
- **Phoenix**: Elastic License 2.0
- **Langfuse**: MIT
- **Opik**: Apache 2.0

Refer to each project's repository for specific license terms.
