# Quick Reference - OpenTelemetry Observability Stack

## Start/Stop Commands

```bash
# Start all services
docker-compose -f docker-compose.observability.yml up -d

# Start specific services only
docker-compose -f docker-compose.observability.yml up -d jaeger phoenix

# Stop all services (keep data)
docker-compose -f docker-compose.observability.yml down

# Stop all services and remove data
docker-compose -f docker-compose.observability.yml down -v

# View status
docker-compose -f docker-compose.observability.yml ps

# View logs
docker-compose -f docker-compose.observability.yml logs -f [service-name]

# Restart service
docker-compose -f docker-compose.observability.yml restart [service-name]
```

## Service URLs

| Service | UI URL | OTLP Endpoint |
|---------|--------|---------------|
| **Jaeger** | http://localhost:16686 | http://localhost:4318/v1/traces |
| **Phoenix** | http://localhost:6006 | http://localhost:6007/v1/traces |
| **Langfuse** | http://localhost:3000 | http://localhost:3000/api/public/ingestion |
| **Opik** | http://localhost:5173 | http://localhost:8080/v1/traces |

## Pagent Configuration

### Switch Between Backends

```php
<?php

use function Pagent\telemetry_otlp;
use function Pagent\agent;

// Jaeger - General distributed tracing
telemetry_otlp('http://localhost:4318/v1/traces');

// Phoenix - LLM observability
telemetry_otlp('http://localhost:6007/v1/traces');

// Opik - Experiment tracking
telemetry_otlp('http://localhost:8080/v1/traces');

// Create agent and test
$agent = agent('anthropic')->model('claude-3-5-sonnet-20241022')->build();
$response = $agent->ask('Hello, world!');
```

### Environment Variable Method

```php
<?php

// In your code
$endpoint = getenv('OTEL_ENDPOINT') ?: 'http://localhost:4318/v1/traces';
telemetry_otlp($endpoint);
```

```bash
# Switch backends via environment
OTEL_ENDPOINT=http://localhost:6007/v1/traces php your-script.php
```

## Common Issues

### Port Already in Use
```bash
# Find what's using the port
lsof -i :16686

# Change port in docker-compose.observability.yml or .env file
```

### Service Won't Start
```bash
# Check logs
docker-compose -f docker-compose.observability.yml logs [service-name]

# Restart service
docker-compose -f docker-compose.observability.yml restart [service-name]

# Reset service data
docker-compose -f docker-compose.observability.yml down -v
docker-compose -f docker-compose.observability.yml up -d
```

### Traces Not Appearing
```bash
# 1. Verify service is running
docker-compose -f docker-compose.observability.yml ps

# 2. Check endpoint is correct (note /v1/traces path)
telemetry_otlp('http://localhost:4318/v1/traces');

# 3. Test endpoint manually
curl -v http://localhost:4318/v1/traces

# 4. Check service logs
docker-compose -f docker-compose.observability.yml logs jaeger
```

## Recommended Workflow

### For Development (Low Resources)
```bash
# Start only Jaeger + Phoenix
docker-compose -f docker-compose.observability.yml up -d jaeger phoenix
```

### For Full Testing
```bash
# Start everything
docker-compose -f docker-compose.observability.yml up -d

# Run integration test
bash docker/observability/test-integration.sh
```

### For Specific Use Cases

**General Debugging**: Use Jaeger
```bash
docker-compose -f docker-compose.observability.yml up -d jaeger
```

**LLM Development**: Use Phoenix
```bash
docker-compose -f docker-compose.observability.yml up -d phoenix
```

**Experiment Tracking**: Use Opik
```bash
docker-compose -f docker-compose.observability.yml up -d opik-clickhouse opik-backend opik-frontend
```

**Production Testing**: Use Langfuse
```bash
docker-compose -f docker-compose.observability.yml up -d langfuse-db langfuse
# Visit http://localhost:3000 to create account
```

## Resource Usage

Typical memory usage per service:
- **Jaeger**: ~200MB
- **Phoenix**: ~300MB
- **Langfuse**: ~400MB (includes PostgreSQL)
- **Opik**: ~800MB (includes ClickHouse)

**Minimum Docker Memory**: 4GB for Jaeger + Phoenix, 8GB for all services

## Testing

### Quick Test Script
```bash
# Create test file
cat > test-otel.php << 'EOF'
<?php
require 'vendor/autoload.php';
use function Pagent\telemetry_otlp;
use function Pagent\agent;

telemetry_otlp('http://localhost:4318/v1/traces');
$agent = agent('mock')->model('test')->build();
echo $agent->ask('Test trace') . "\n";
EOF

# Run test
php test-otel.php

# Check traces in Jaeger
open http://localhost:16686
```

### Run Pest Tests with Tracing
```bash
# Start Jaeger
docker-compose -f docker-compose.observability.yml up -d jaeger

# Set environment and run tests
OTEL_ENDPOINT=http://localhost:4318/v1/traces ./vendor/bin/pest --group=api

# View traces
open http://localhost:16686
```

## Comparison Table

| Feature | Jaeger | Phoenix | Langfuse | Opik |
|---------|:------:|:-------:|:--------:|:----:|
| Setup Difficulty | Easy | Easy | Medium | Medium |
| LLM-Specific | ❌ | ✅ | ✅ | ✅ |
| No Auth Required | ✅ | ✅ | ❌ | ✅ |
| Prompt Management | ❌ | ⚠️ | ✅ | ⚠️ |
| Cost Tracking | ❌ | ⚠️ | ✅ | ⚠️ |
| Memory Usage | Low | Low | Medium | High |
| Best For | Debugging | Development | Production | Experiments |

## Integration Test

Run the full integration test:
```bash
bash docker/observability/test-integration.sh
```

This will:
1. Start all services
2. Wait for health checks
3. Run test traces to each backend
4. Display access URLs
5. Show useful commands

## Clean Up

```bash
# Stop all services
docker-compose -f docker-compose.observability.yml down

# Remove all data
docker-compose -f docker-compose.observability.yml down -v

# Remove images (optional)
docker-compose -f docker-compose.observability.yml down --rmi all
```

## Further Reading

- Full documentation: `docker/observability/README.md`
- OpenTelemetry spec: https://opentelemetry.io/docs/
- Pagent docs: `docs/observability.md` (when available)
