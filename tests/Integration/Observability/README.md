# Observability Integration Tests

This directory contains integration tests for Pagent's observability features, including OpenTelemetry/OTLP tracing with various backends.

## Quick Start

### Running All Tests

```bash
# Run all observability tests
./vendor/bin/pest tests/Integration/Observability/

# Run with Docker services
just obs-up  # Start observability stack
./vendor/bin/pest tests/Integration/Observability/
just obs-down  # Stop services when done
```

### Running Specific Backend Tests

```bash
# Test specific backends
./vendor/bin/pest --group=jaeger
./vendor/bin/pest --group=phoenix
./vendor/bin/pest --group=zipkin
./vendor/bin/pest --group=langfuse
./vendor/bin/pest --group=opik
./vendor/bin/pest --group=helicone
```

## Test Structure

### Infrastructure Tests

Each backend has 3-4 basic infrastructure tests that verify:

- Container health and startup
- UI/API accessibility
- Database dependencies (if applicable)

**These tests do NOT require API keys** and should always pass when Docker services are running.

### OTLP Integration Tests

Backends supporting OTLP have additional tests that verify:

- Traces are exported via OTLP
- LLM-specific attributes are captured
- Multiple operations are tracked correctly

**OTLP tests have different requirements per backend:**

## Backend-Specific Setup

### Jaeger (Fully Supported ✅)

**Infrastructure**: Always available
**OTLP**: Fully working

```bash
# No setup needed - just run the tests
./vendor/bin/pest --group=jaeger
```

### Phoenix (Fully Supported ✅)

**Infrastructure**: Always available
**OTLP**: Fully working

```bash
# No setup needed
./vendor/bin/pest --group=phoenix
```

### Zipkin (Fully Supported ✅)

**Infrastructure**: Always available
**OTLP**: Fully working

```bash
# No setup needed
./vendor/bin/pest --group=zipkin
```

### Langfuse (Infrastructure Working, OTLP Needs Config ⏸️)

**Infrastructure**: 3 tests passing
**OTLP**: 3 tests skip (need API keys)

```bash
# Infrastructure tests (no auth needed)
./vendor/bin/pest tests/Integration/Observability/LangfuseBackendTest.php

# Enable OTLP tests - set these environment variables:
export TEST_LANGFUSE_PUBLIC_KEY="your-public-key"
export TEST_LANGFUSE_SECRET_KEY="your-secret-key"

# Then run OTLP tests
./vendor/bin/pest --group=langfuse --group=otlp
```

**Requirements for OTLP:**

- Langfuse v3.22.0+ (local deployment)
- API keys from Langfuse instance
- Endpoint: `http://localhost:3000/api/public/otel/v1/traces`
- Auth: HTTP Basic Auth with `base64(publicKey:secretKey)`

### Opik (Infrastructure Working, OTLP Has Known Issues ⚠️)

**Infrastructure**: 3 tests passing
**OTLP**: 3 tests passing but exports fail (known backend issue)

```bash
# Infrastructure tests always work
./vendor/bin/pest tests/Integration/Observability/OpikBackendTest.php

# OTLP tests run but see export errors (expected)
./vendor/bin/pest --group=opik --group=otlp
```

**Known Issue:**
Opik's self-hosted OTLP endpoint returns 404 (see [GitHub #2566](https://github.com/comet-ml/opik/issues/2566)). The tests verify infrastructure but OTLP exports fail. This is a known Opik limitation.

**Optional API Key:**

```bash
export TEST_OPIK_API_KEY="your-api-key"  # Optional for production instances
```

### Helicone (Infrastructure Only ✅)

**Infrastructure**: 4 tests passing
**OTLP**: Not applicable (proxy architecture)

```bash
# Helicone is a proxy/gateway, not an OTLP receiver
./vendor/bin/pest --group=helicone
```

Helicone tests verify the gateway and database connectivity but do not test OTLP since Helicone is designed as an LLM API proxy, not a telemetry backend.

## Docker Services

### Managing the Observability Stack

```bash
# Start all services
just obs-up

# Start specific backend
docker compose -f docker-compose.observability.yml up jaeger -d
docker compose -f docker-compose.observability.yml up phoenix -d

# Check service health
docker compose -f docker-compose.observability.yml ps

# View logs
docker compose -f docker-compose.observability.yml logs jaeger
docker compose -f docker-compose.observability.yml logs langfuse

# Stop all services
just obs-down
```

### Service Endpoints

| Service  | UI/API Endpoint                  | OTLP Endpoint                                   |
| -------- | -------------------------------- | ----------------------------------------------- |
| Jaeger   | http://localhost:16686           | http://localhost:4318/v1/traces                 |
| Phoenix  | http://localhost:6006            | http://localhost:4317 (gRPC)                    |
| Zipkin   | http://localhost:9411            | http://localhost:9411/api/v2/spans              |
| Langfuse | http://localhost:3000            | http://localhost:3000/api/public/otel/v1/traces |
| Opik     | http://localhost:5173 (frontend) | http://localhost:8080/v1/traces (404)           |
|          | http://localhost:8080 (backend)  |                                                 |
| Helicone | http://localhost:8788 (UI)       | N/A (proxy architecture)                        |
|          | http://localhost:8585 (gateway)  |                                                 |

## Test Coverage Summary

| Backend   | Infrastructure | OTLP            | Total  | Status                  |
| --------- | -------------- | --------------- | ------ | ----------------------- |
| Jaeger    | 1 passing      | 4 passing       | 5      | ✅ Excellent            |
| Phoenix   | 1 passing      | 4 passing       | 5      | ✅ Excellent            |
| Zipkin    | 1 passing      | 2 passing       | 3      | ✅ Excellent            |
| Langfuse  | 3 passing      | 3 skip          | 6      | ⏸️ Needs API keys       |
| Opik      | 3 passing      | 3 passing\*     | 6      | ⚠️ OTLP endpoint broken |
| Helicone  | 4 passing      | N/A             | 4      | ✅ Infrastructure only  |
| **Total** | **13**         | **13 + 3 skip** | **29** | **26 passing, 3 skip**  |

\*Opik OTLP tests pass infrastructure checks but OTLP exports fail (expected due to backend bug).

## Troubleshooting

### Tests Timing Out

If tests timeout waiting for services:

1. Check Docker services are running: `docker ps`
2. Check service health: `docker compose -f docker-compose.observability.yml ps`
3. Increase timeout in test if service is slow to start
4. Check Docker logs for errors

### OTLP Tests Failing

**Langfuse OTLP tests skipping:**

- Set `TEST_LANGFUSE_PUBLIC_KEY` and `TEST_LANGFUSE_SECRET_KEY`
- Ensure Langfuse v3.22.0+ is running

**Opik OTLP exports failing (404 errors):**

- This is expected due to [known issue](https://github.com/comet-ml/opik/issues/2566)
- Tests verify infrastructure but acknowledge OTLP endpoint doesn't work

### Port Conflicts

If services fail to start due to port conflicts:

```bash
# Check what's using the port
lsof -i :16686  # Jaeger UI
lsof -i :6006   # Phoenix
lsof -i :9411   # Zipkin

# Either stop the conflicting service or change ports in docker-compose.observability.yml
```

### Cleaning Up Old Data

Observability backends accumulate traces across test runs. This is normal and shouldn't affect tests, but you can reset by:

```bash
# Stop services
just obs-down

# Remove volumes (deletes all trace data)
docker volume rm $(docker volume ls -q | grep observability)

# Restart services
just obs-up
```

## CI/CD Integration

### GitHub Actions

To run these tests in CI with full OTLP coverage:

```yaml
# .github/workflows/test.yml
- name: Start Observability Services
  run: docker compose -f docker-compose.observability.yml up -d

- name: Run Observability Tests
  env:
    TEST_LANGFUSE_PUBLIC_KEY: ${{ secrets.LANGFUSE_PUBLIC_KEY }}
    TEST_LANGFUSE_SECRET_KEY: ${{ secrets.LANGFUSE_SECRET_KEY }}
    TEST_OPIK_API_KEY: ${{ secrets.OPIK_API_KEY }}
  run: ./vendor/bin/pest tests/Integration/Observability/

- name: Cleanup
  if: always()
  run: docker compose -f docker-compose.observability.yml down
```

### Required Secrets

Add these to your CI environment (GitHub Secrets, etc.):

- `LANGFUSE_PUBLIC_KEY` - For Langfuse OTLP tests
- `LANGFUSE_SECRET_KEY` - For Langfuse OTLP tests
- `OPIK_API_KEY` - Optional, for production Opik instances

## Test File Overview

### Helper Files

- **ObservabilityDockerHelpers.php** - Docker service management, trace querying
- **ObservabilityTestHelper.php** - Service availability checks, HTTP requests

### Test Files

- **AgentTelemetryTest.php** - Core agent telemetry functionality
- **WorkflowTelemetryTest.php** - Pipeline and workflow tracing
- **ObservabilityInfrastructureTest.php** - Docker compose configuration
- **SetupTest.php** - Service availability checks

### Backend Test Files

- **JaegerBackendTest.php** + **JaegerTest.php**
- **PhoenixBackendTest.php**
- **ZipkinBackendTest.php**
- **LangfuseBackendTest.php** + **LangfuseTest.php**
- **OpikBackendTest.php** + **OpikTest.php**
- **HeliconeBackendTest.php** + **HeliconeTest.php**

Each `*BackendTest.php` uses Docker and tests OTLP integration.
Each `*Test.php` (without Backend) contains lighter infrastructure checks.

## Contributing

When adding new observability backend tests:

1. Follow the pattern established by Jaeger/Phoenix/Zipkin tests
2. Add both infrastructure tests (no auth) and OTLP tests (with auth handling)
3. Use `ObservabilityDockerHelpers::startService()` for Docker management
4. Implement graceful skipping when credentials aren't available
5. Document any backend-specific requirements in this README
6. Add service configuration to `docker-compose.observability.yml`

## Further Reading

- [Pagent Observability Guide](../../../docs/observability.md)
- [OpenTelemetry PHP Documentation](https://opentelemetry.io/docs/languages/php/)
- [Report: Observability Test Enhancement](../../../ai-docs/reports/2025-11-18-observability-test-enhancement.md)
