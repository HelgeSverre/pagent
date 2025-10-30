# Observability Integration Tests

This directory contains integration tests for the observability stack used with Pagent.

## Overview

These tests verify that all observability services are running correctly and can accept data from the Pagent framework. They test:

- **Jaeger**: Distributed tracing with OTLP
- **Phoenix (Arize)**: LLM observability
- **Langfuse**: LLM monitoring and prompt management
- **Helicone**: LLM cost tracking and proxy
- **Opik (Comet)**: LLM experiment tracking

## Quick Start

### 1. Start the Observability Stack

```bash
# Using just (recommended)
just observability-up

# Or using docker compose directly
docker compose -f docker-compose.observability.yml up -d
```

### 2. Run the Tests

```bash
# Run all observability tests
composer test:observability

# Or using just (starts services + runs tests)
just observability-test

# Run specific service tests
composer test:observability -- --filter=Jaeger
composer test:observability -- --filter=Phoenix
composer test:observability -- --filter=Langfuse
composer test:observability -- --filter=Helicone
composer test:observability -- --filter=Opik
```

### 3. Stop the Services

```bash
just observability-down
```

## Test Structure

```
tests/Integration/Observability/
├── README.md                    # This file
├── ObservabilityTestHelper.php  # Helper class for tests
├── SetupTest.php                # Verifies all services are running
├── JaegerTest.php               # Jaeger-specific tests
├── PhoenixTest.php              # Phoenix-specific tests
├── LangfuseTest.php             # Langfuse-specific tests
├── HeliconeTest.php             # Helicone-specific tests
└── OpikTest.php                 # Opik-specific tests
```

## Configuration

### Environment Variables

Create a `.env` file in the project root or set these environment variables:

```bash
# Jaeger
TEST_JAEGER_UI_URL=http://localhost:16686
TEST_JAEGER_OTLP_HTTP=http://localhost:4318
TEST_JAEGER_OTLP_GRPC=http://localhost:4317

# Phoenix
TEST_PHOENIX_BASE_URL=http://localhost:6006
TEST_PHOENIX_API_KEY=phoenix_xxx  # Optional

# Langfuse
TEST_LANGFUSE_BASE_URL=http://localhost:3000
TEST_LANGFUSE_PUBLIC_KEY=pk_xxx   # Required for auth tests
TEST_LANGFUSE_SECRET_KEY=sk_xxx   # Required for auth tests

# Helicone
TEST_HELICONE_BASE_URL=http://localhost:3001
TEST_HELICONE_GATEWAY_URL=http://localhost:8585
TEST_HELICONE_API_KEY=sk_helicone_xxx  # Optional

# Opik
TEST_OPIK_URL=http://localhost:8080
TEST_OPIK_API_KEY=opik_xxx        # Optional
TEST_OPIK_WORKSPACE=default
```

### Setup API Keys

Most services require API keys for authenticated tests. Here's how to set them up:

#### Langfuse

1. Visit http://localhost:3000
2. Create an account
3. Create a project
4. Copy the Public Key and Secret Key from project settings
5. Set `TEST_LANGFUSE_PUBLIC_KEY` and `TEST_LANGFUSE_SECRET_KEY`

#### Phoenix

1. API keys are optional for local testing
2. To generate:
   ```bash
   curl -X POST http://localhost:6006/v1/api_keys \
     -H "admin-secret: your_secret" \
     -H "Content-Type: application/json" \
     -d '{"name": "test-key"}'
   ```
3. Set `TEST_PHOENIX_API_KEY`

#### Opik

1. Visit http://localhost:5173
2. Create an account and project
3. Generate API key from Settings
4. Set `TEST_OPIK_API_KEY`

#### Helicone

1. Visit http://localhost:3001
2. Create an account
3. Generate API key from Settings
4. Set `TEST_HELICONE_API_KEY`

## Test Categories

### Setup Tests (`SetupTest.php`)

Basic connectivity tests that verify each service is accessible:

```bash
composer test:observability -- --filter=Setup
```

These run automatically before other tests and will fail fast if services aren't running.

### Service-Specific Tests

Each service has its own test file with specific functionality tests:

**Jaeger** - Tests OTLP trace ingestion:

```bash
composer test:observability -- --group=jaeger
```

**Phoenix** - Tests LLM trace ingestion:

```bash
composer test:observability -- --group=phoenix
```

**Langfuse** - Tests authentication and trace creation:

```bash
composer test:observability -- --group=langfuse
```

**Helicone** - Tests gateway and authentication:

```bash
composer test:observability -- --group=helicone
```

**Opik** - Tests API and authentication:

```bash
composer test:observability -- --group=opik
```

## Writing New Tests

### Using the Test Helper

```php
<?php

use Tests\Integration\Observability\ObservabilityTestHelper;

test('my observability test', function () {
    // Check if service is available
    $isAvailable = ObservabilityTestHelper::isServiceAvailable('http://localhost:3000');
    expect($isAvailable)->toBeTrue();

    // Get service configuration
    $config = ObservabilityTestHelper::getTestConfig('langfuse');

    // Send HTTP request
    $response = ObservabilityTestHelper::sendRequest(
        'http://localhost:3000/api/endpoint',
        'POST',
        ['data' => 'value'],
        ['Authorization' => 'Bearer token']
    );

    expect($response['status'])->toBe(200);
})->group('observability', 'my-service');
```

### Test Groups

Always tag observability tests with appropriate groups:

```php
test('example test', function () {
    // test code
})->group('observability', 'servicename');
```

Available groups:

- `observability` - All observability tests
- `jaeger` - Jaeger-specific tests
- `phoenix` - Phoenix-specific tests
- `langfuse` - Langfuse-specific tests
- `helicone` - Helicone-specific tests
- `opik` - Opik-specific tests

### Skipping Tests

Tests that require API keys will automatically skip if keys aren't configured:

```php
test('authenticated endpoint', function () {
    $config = ObservabilityTestHelper::getTestConfig('langfuse');

    if (empty($config['public_key'])) {
        $this->markTestSkipped('Langfuse API keys not configured');
    }

    // test code
})->group('observability', 'langfuse');
```

## Troubleshooting

### Services Not Starting

```bash
# Check service status
docker ps --filter "name=pagent-"

# View service logs
docker compose -f docker-compose.observability.yml logs langfuse

# Restart specific service
docker compose -f docker-compose.observability.yml restart langfuse
```

### Port Conflicts

If ports are already in use:

1. Stop conflicting services
2. Or modify ports in `docker-compose.observability.yml`
3. Update test environment variables to match

### Test Timeouts

Some services (especially Opik) can be slow to start. The tests include retry logic, but you may need to:

```bash
# Wait longer before running tests
just observability-up
sleep 30
composer test:observability
```

### Database Issues

Reset all data if services behave unexpectedly:

```bash
# WARNING: This deletes all observability data
docker compose -f docker-compose.observability.yml down -v
just observability-up
```

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Observability Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3
          extensions: curl

      - name: Install dependencies
        run: composer install

      - name: Start observability stack
        run: docker compose -f docker-compose.observability.yml up -d

      - name: Wait for services
        run: sleep 30

      - name: Run observability tests
        run: composer test:observability

      - name: Stop services
        if: always()
        run: docker compose -f docker-compose.observability.yml down
```

## Performance Considerations

- **Parallel Execution**: These tests should NOT be run in parallel as they share Docker services
- **Resource Usage**: All 5 services + databases require ~4GB RAM minimum
- **Startup Time**: Cold start can take 20-30 seconds
- **Test Duration**: Full suite typically runs in 30-60 seconds

## Best Practices

1. **Always start services first**: Tests will fail if services aren't running
2. **Use test groups**: Run specific service tests when debugging
3. **Check service health**: Use setup tests to verify connectivity
4. **Clean data regularly**: Use `down -v` to reset state between major test runs
5. **Mock for unit tests**: Only use these for integration testing
6. **Document API keys**: Keep track of test credentials separately

## Next Steps

- Add more comprehensive trace validation tests
- Test multi-service scenarios (sending same trace to multiple services)
- Add performance benchmarks
- Test error handling and retry logic
- Add tests for actual Pagent agent integration with each service

## References

- [Jaeger Documentation](https://www.jaegertracing.io/docs/)
- [Phoenix Documentation](https://docs.arize.com/phoenix)
- [Langfuse API Documentation](https://langfuse.com/docs/api)
- [Helicone Documentation](https://docs.helicone.ai/)
- [Opik Documentation](https://www.comet.com/docs/opik)
