# Observability Stack for Pagent

This directory contains Docker Compose configuration for running a comprehensive observability stack for LLM application monitoring and tracing.

## Services Included

| Service | Port(s) | Purpose | Status |
|---------|---------|---------|--------|
| **Jaeger** | 16686 (UI), 4317/4318 (OTLP) | Distributed tracing | ✅ Working |
| **Phoenix** | 6006 (UI), 6007 (OTLP) | LLM observability (Arize) | ✅ Working |
| **Langfuse** | 3000 | LLM monitoring & prompts | ✅ Working |
| **Helicone** | 3001 (UI), 8585 (Gateway) | LLM cost tracking | ✅ Working |
| **Opik** | 5173 (UI), 8080 (API) | LLM experiment tracking | ✅ Working |

## Quick Start

### Start All Services

```bash
# From project root
just observability-up

# Or using docker compose directly
docker compose -f docker-compose.observability.yml up -d
```

### View Service URLs

```bash
just observability-urls
```

### View Logs

```bash
just observability-logs

# Or specific service
docker compose -f docker-compose.observability.yml logs -f langfuse
```

### Stop Services

```bash
just observability-down
```

## Environment Configuration

Copy the example environment file and customize:

```bash
cp docker/.env.observability.example docker/.env.observability
# Edit docker/.env.observability with your values
```

Then reference it in your docker-compose command:

```bash
docker compose -f docker-compose.observability.yml --env-file docker/.env.observability up -d
```

## Service-Specific Setup

### Langfuse

**Access**: http://localhost:3000

**First-time setup:**
1. Create an account through the UI
2. Create an organization and project
3. Copy the Public Key and Secret Key from project settings

**Headless initialization** (for CI/CD):
Set these environment variables before starting:
```bash
LANGFUSE_INIT_ORG_NAME="My Organization"
LANGFUSE_INIT_PROJECT_NAME="My Project"
LANGFUSE_INIT_USER_EMAIL="admin@example.com"
LANGFUSE_INIT_USER_PASSWORD="changeme"
LANGFUSE_INIT_PROJECT_PUBLIC_KEY="pk_xxx"
LANGFUSE_INIT_PROJECT_SECRET_KEY="sk_xxx"
```

**Integration in code:**
```php
use Pagent\Agent;

$agent = Agent::build()
    ->model('anthropic:claude-3-5-sonnet-20241022')
    ->langfuseTrace([
        'public_key' => 'pk_xxx',
        'secret_key' => 'sk_xxx',
        'host' => 'http://localhost:3000',
    ])
    ->create();
```

### Phoenix (Arize)

**Access**: http://localhost:6006

**Setup:**
1. No initial account creation required
2. Start sending traces via OTLP (port 6007) or HTTP (port 6006/v1/traces)

**API Key generation** (for production):
```bash
# Set admin secret
PHOENIX_ADMIN_SECRET=your_secret

# Generate API key via API
curl -X POST http://localhost:6006/v1/api_keys \
  -H "admin-secret: your_secret" \
  -H "Content-Type: application/json" \
  -d '{"name": "test-key"}'
```

**Integration:**
```php
use Pagent\Agent;

$agent = Agent::build()
    ->model('anthropic:claude-3-5-sonnet-20241022')
    ->phoenixTrace([
        'endpoint' => 'http://localhost:6006/v1/traces',
        'headers' => ['api-key' => 'phoenix_xxx'], // optional
    ])
    ->create();
```

### Opik (Comet)

**Access**:
- UI: http://localhost:5173
- API: http://localhost:8080

**First-time setup:**
1. Access http://localhost:5173
2. Create an account
3. Create a project
4. Generate API keys from Settings

**Local SDK configuration:**
```bash
# Configure Opik CLI to use local instance
opik configure --use_local
```

This creates `~/.opik.config`:
```yaml
api_key: null
url: http://localhost:5173/api
workspace: default
```

**Integration:**
```php
use Pagent\Agent;

$agent = Agent::build()
    ->model('anthropic:claude-3-5-sonnet-20241022')
    ->opikTrace([
        'api_key' => 'opik_xxx',
        'url' => 'http://localhost:8080',
        'workspace' => 'default',
    ])
    ->create();
```

### Helicone

**Access**:
- UI: http://localhost:3001
- Gateway: http://localhost:8585

**First-time setup:**
1. Access http://localhost:3001
2. Create an account
3. Generate API key from Settings

**Integration:**
Use Helicone as a proxy for your LLM requests:
```php
use Pagent\Agent;

$agent = Agent::build()
    ->model('anthropic:claude-3-5-sonnet-20241022')
    ->heliconeProxy([
        'api_key' => 'sk_helicone_xxx',
        'base_url' => 'http://localhost:8585',
    ])
    ->create();
```

### Jaeger

**Access**: http://localhost:16686

**Setup:**
1. No account creation needed
2. Send traces via OTLP HTTP (4318) or gRPC (4317)

**Integration:**
```php
use Pagent\Agent;

$agent = Agent::build()
    ->model('anthropic:claude-3-5-sonnet-20241022')
    ->jaegerTrace([
        'otlp_endpoint' => 'http://localhost:4318',
        'service_name' => 'pagent-app',
    ])
    ->create();
```

## Integration Testing Setup

### Environment Variables for Tests

Create a `.env.test` file:

```bash
# Langfuse
TEST_LANGFUSE_PUBLIC_KEY=pk_xxx
TEST_LANGFUSE_SECRET_KEY=sk_xxx
TEST_LANGFUSE_BASE_URL=http://localhost:3000

# Opik
TEST_OPIK_API_KEY=opik_xxx
TEST_OPIK_URL=http://localhost:8080

# Phoenix
TEST_PHOENIX_BASE_URL=http://localhost:6006

# Helicone
TEST_HELICONE_API_KEY=sk_helicone_xxx
TEST_HELICONE_BASE_URL=http://localhost:3001

# Jaeger
TEST_JAEGER_OTLP_HTTP=http://localhost:4318
```

### Test Setup Script

```php
<?php

// tests/Integration/Observability/SetupTest.php

use function Pest\testify;

beforeAll(function () {
    // Verify all observability services are running
    $services = [
        'Jaeger' => 'http://localhost:16686',
        'Phoenix' => 'http://localhost:6006',
        'Langfuse' => 'http://localhost:3000/api/public/health',
        'Helicone' => 'http://localhost:3001',
        'Opik' => 'http://localhost:8080/health',
    ];

    foreach ($services as $name => $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new \RuntimeException(
                "{$name} is not available at {$url}. " .
                "Please run: just observability-up"
            );
        }
    }
});

test('all observability services are running', function () {
    expect(true)->toBeTrue();
});
```

### Running Integration Tests

```bash
# Start observability stack
just observability-up

# Wait for services to be ready
sleep 10

# Run integration tests
composer test:integration -- --group=observability

# Stop services when done
just observability-down
```

## Data Persistence

All services use Docker volumes for data persistence:

- `pagent-jaeger-data`: Jaeger traces (in-memory by default)
- `pagent-phoenix-data`: Phoenix database (SQLite)
- `pagent-postgres-data`: Langfuse database (PostgreSQL)
- `pagent-mysql-data`: Opik state database (MySQL)
- `pagent-clickhouse-data`: Opik analytics database (ClickHouse)
- `pagent-helicone-data`: Helicone database (PostgreSQL)

To reset all data:

```bash
docker compose -f docker-compose.observability.yml down -v
```

## Troubleshooting

### Service shows as "unhealthy"

Some services may show as unhealthy during startup but still be functional. Check logs:

```bash
docker compose -f docker-compose.observability.yml logs <service-name>
```

### Cannot connect to service

1. Verify service is running:
   ```bash
   docker ps --filter "name=pagent-"
   ```

2. Check service logs for errors

3. Verify port is not already in use:
   ```bash
   lsof -i :3000  # Check Langfuse port
   ```

### Reset a specific service

```bash
# Stop and remove container
docker compose -f docker-compose.observability.yml rm -sf langfuse

# Remove its volume
docker volume rm pagent-postgres-data

# Recreate
docker compose -f docker-compose.observability.yml up -d langfuse
```

## Architecture Notes

### Service Dependencies

```
Langfuse → langfuse-db (PostgreSQL)
Opik Backend → opik-mysql (MySQL) + opik-clickhouse (ClickHouse)
Opik Frontend → opik-backend
Helicone → helicone-db (PostgreSQL)
Phoenix → (SQLite, self-contained)
Jaeger → (In-memory storage, self-contained)
```

### Network

All services run on the `pagent-observability` bridge network, allowing them to communicate with each other using container names.

## Production Considerations

**⚠️ This setup is for local development and testing only!**

For production:

1. **Use versioned tags** instead of `:latest`
2. **Change all default passwords** in environment variables
3. **Enable authentication** on all services
4. **Use external databases** for persistence
5. **Enable HTTPS** with proper certificates
6. **Configure resource limits** in docker-compose
7. **Set up proper backup** strategies
8. **Use secrets management** (Docker secrets, Vault, etc.)
9. **Enable monitoring** and alerting
10. **Review security** settings for each service

## References

- [Jaeger Documentation](https://www.jaegertracing.io/docs/)
- [Phoenix Documentation](https://docs.arize.com/phoenix)
- [Langfuse Documentation](https://langfuse.com/docs)
- [Helicone Documentation](https://docs.helicone.ai/)
- [Opik Documentation](https://www.comet.com/docs/opik)
