# Chapter 27: Production Deployment

**Learning Objectives:**

- Configure production environments with secure credential management
- Implement comprehensive monitoring and observability
- Design scalable agent architectures for production workloads
- Apply production-grade security with guards and error handling
- Respond to incidents with proper logging and alerting

---

## Why Production Deployment Differs

Deploying AI agents to production requires fundamentally different considerations than development. In development, you iterate quickly, tolerate errors, and focus on functionality. In production, you must ensure reliability, security, observability, and scalability.

The stakes are higher with LLM-powered agents. Every API call costs money. Every guard failure could expose sensitive data. Every unhandled exception disrupts user experience. Production deployment transforms your agent from prototype to dependable service.

This chapter covers the complete production deployment lifecycle: environment configuration, secret management, telemetry setup, scaling strategies, and incident response.

## Environment Configuration

Production agents should never contain hardcoded credentials or configuration. All environment-specific settings must be externalized and loaded at runtime.

### Environment Variables

Pagent providers automatically check environment variables for API credentials:

```php
<?php

declare(strict_types=1);

use function Pagent\agent;

// Provider checks these environment variables automatically:
// - ANTHROPIC_API_KEY
// - OPENAI_API_KEY
// - OLLAMA_HOST

$agent = agent('production-assistant')
    ->provider('anthropic')  // Uses $_ENV['ANTHROPIC_API_KEY']
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful production assistant.');

$response = $agent->prompt('Hello');
```

The provider resolution order is:

1. Explicit `api_key` in configuration array
2. `$_ENV` superglobal
3. `getenv()` function call
4. Throws `RuntimeException` if not found

This pattern allows different deployment environments to provide credentials without code changes.

### .env Files for Local Development

Use `phpdotenv` for local development environments:

```php
// bootstrap.php
use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}
```

Your `.env` file (never commit this to version control):

```bash
# .env
ANTHROPIC_API_KEY=sk-ant-api03-xxx
OPENAI_API_KEY=sk-proj-xxx
OLLAMA_HOST=http://localhost:11434

# Application settings
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=info

# Telemetry
TELEMETRY_ENABLED=true
TELEMETRY_ENDPOINT=https://telemetry.example.com/v1/traces
TELEMETRY_TOKEN=secret-token-xxx
```

### Configuration Files

For complex deployments, use configuration files with environment interpolation:

```php
// config/agents.php
return [
    'default_provider' => env('AGENT_PROVIDER', 'anthropic'),
    'default_model' => env('AGENT_MODEL', 'claude-sonnet-4-6'),

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 30),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        ],
    ],

    'telemetry' => [
        'enabled' => (bool) env('TELEMETRY_ENABLED', true),
        'exporter' => env('TELEMETRY_EXPORTER', 'otlp'),
        'service_name' => env('TELEMETRY_SERVICE_NAME', 'agent-service'),
        'endpoint' => env('TELEMETRY_ENDPOINT'),
    ],

    'limits' => [
        'max_tokens' => (int) env('AGENT_MAX_TOKENS', 1024),
        'temperature' => (float) env('AGENT_TEMPERATURE', 0.7),
        'tool_call_depth' => (int) env('TOOL_CALL_DEPTH', 10),
    ],
];
```

Load configuration at application startup:

```php
// app.php
$config = require __DIR__ . '/config/agents.php';

$agent = agent('production')
    ->provider($config['default_provider'])
    ->model($config['default_model'])
    ->temperature($config['limits']['temperature'])
    ->maxTokens($config['limits']['max_tokens']);
```

## Secret Management

Production systems require robust secret management beyond environment variables.

### Using External Secret Stores

Integrate with secret management services:

```php
use Google\Cloud\SecretManager\V1\SecretManagerServiceClient;

function getSecret(string $secretName): string
{
    static $client = null;

    if ($client === null) {
        $client = new SecretManagerServiceClient();
    }

    $projectId = getenv('GCP_PROJECT_ID');
    $name = "projects/{$projectId}/secrets/{$secretName}/versions/latest";

    $response = $client->accessSecretVersion($name);

    return $response->getPayload()->getData();
}

// Use in agent configuration
$agent = agent('secure-agent')
    ->provider('anthropic', [
        'api_key' => getSecret('anthropic-api-key'),
    ]);
```

Similar patterns work for AWS Secrets Manager, Azure Key Vault, HashiCorp Vault, or Kubernetes Secrets.

### Secret Rotation

Handle API key rotation without downtime:

```php
final class RotatingSecretProvider
{
    private string $currentKey;
    private int $lastRefresh;
    private const REFRESH_INTERVAL = 3600; // 1 hour

    public function __construct(
        private readonly callable $secretFetcher
    ) {
        $this->refresh();
    }

    public function getApiKey(): string
    {
        if (time() - $this->lastRefresh > self::REFRESH_INTERVAL) {
            $this->refresh();
        }

        return $this->currentKey;
    }

    private function refresh(): void
    {
        $this->currentKey = ($this->secretFetcher)();
        $this->lastRefresh = time();
    }
}

$secretProvider = new RotatingSecretProvider(
    fn() => getSecret('anthropic-api-key')
);

// Refresh happens automatically every hour
$agent = agent('rotating-key')
    ->provider('anthropic', [
        'api_key' => $secretProvider->getApiKey(),
    ]);
```

### Never Log Secrets

Implement secret redaction in logging:

```php
function redactSecrets(string $message): string
{
    // Redact API keys
    $message = preg_replace(
        '/sk-[a-zA-Z0-9]{32,}/i',
        '[REDACTED-API-KEY]',
        $message
    );

    // Redact bearer tokens
    $message = preg_replace(
        '/Bearer [a-zA-Z0-9._-]+/i',
        'Bearer [REDACTED]',
        $message
    );

    return $message;
}

// Use in error handling
try {
    $response = $agent->prompt($input);
} catch (Exception $e) {
    $safeMessage = redactSecrets($e->getMessage());
    logger()->error('Agent error: ' . $safeMessage);
}
```

## Production Telemetry Setup

Observability is non-negotiable in production. Pagent's OpenTelemetry integration provides comprehensive visibility into agent behavior.

### OTLP Exporter Configuration

Configure production-grade telemetry with the OTLP exporter:

```php
use function Pagent\telemetry;

telemetry([
    'enabled' => true,
    'exporter' => 'otlp',
    'service_name' => 'customer-support-agents',
    'service_version' => '1.2.0',
    'otlp' => [
        'endpoint' => 'https://api.honeycomb.io/v1/traces',
        'headers' => [
            'x-honeycomb-team' => $_ENV['HONEYCOMB_API_KEY'],
        ],
        'timeout' => 5000,
    ],
    'sampling_rate' => 1.0, // Sample 100% in production initially
]);
```

This configuration sends all traces to Honeycomb (or any OTLP-compatible backend like Grafana Cloud, New Relic, or Datadog).

### Agent-Level Telemetry

Enable telemetry for specific agents:

```php
$agent = agent('support-bot')
    ->provider('anthropic')
    ->telemetry(true) // Enable for this agent
    ->system('You are a customer support assistant.')
    ->guard('pii')
    ->guard('contentFilter');

// Every prompt generates spans:
// - agent.prompt (parent span)
//   - llm.request (provider call)
//   - guard.check (each guard)
//   - tool.execute (each tool call)
$response = $agent->prompt('Help with order #12345');
```

### Custom Attributes for Context

Add business context to spans:

```php
$agent = agent('sales-agent')
    ->provider('anthropic')
    ->telemetry(true);

$response = $agent->prompt($query, [
    'telemetry_attributes' => [
        'user.id' => $userId,
        'user.plan' => $userPlan,
        'session.id' => $sessionId,
        'request.priority' => 'high',
    ],
]);
```

These attributes enable powerful queries: "Show me all high-priority requests that failed" or "What's the P95 latency for premium users?"

### Monitoring Dashboards

Configure alerting based on key metrics:

```yaml
# Example Grafana alert
- alert: HighAgentErrorRate
  expr: sum(rate(agent_errors_total[5m])) > 0.05
  for: 5m
  labels:
    severity: warning
  annotations:
    summary: "Agent error rate above 5%"

- alert: SlowAgentResponses
  expr: histogram_quantile(0.95, agent_response_time_seconds) > 5
  for: 10m
  labels:
    severity: warning
  annotations:
    summary: "P95 response time above 5 seconds"

- alert: GuardViolationSpike
  expr: rate(guard_violations_total[5m]) > 0.1
  for: 5m
  labels:
    severity: critical
  annotations:
    summary: "Guard violations spiking"
```

## Production Guards and Security

Guards are critical for production safety. Layer multiple guards to prevent data leaks, harmful content, and prompt injection.

### Comprehensive Guard Configuration

```php
use Pagent\Guards\PIIGuard;
use Pagent\Guards\ContentFilterGuard;
use Pagent\Guards\PromptInjectionGuard;

$agent = agent('public-facing-bot')
    ->provider('anthropic')
    ->system('You are a helpful customer service agent.')

    // Built-in guards
    ->guard('pii')
    ->guard('contentFilter')
    ->guard('promptInjection')

    // Custom compliance guard
    ->guard('compliance', function (string $input, string $output): bool {
        $requiredDisclaimers = ['terms apply', 'see full terms'];
        $lowerOutput = mb_strtolower($output);

        foreach ($requiredDisclaimers as $disclaimer) {
            if (str_contains($lowerOutput, $disclaimer)) {
                return true;
            }
        }

        // If no disclaimer found, fail
        return false;
    })

    // Production fallback with logging
    ->fallback(function (Exception $error) use ($agent) {
        logger()->warning('Guard triggered', [
            'agent' => $agent->getName(),
            'guard' => get_class($error),
            'message' => $error->getMessage(),
        ]);

        return 'I apologize, but I cannot process that request. Please contact support.';
    });
```

### Rate Limiting via Middleware

Implement rate limiting to control costs:

```php
final class RateLimitMiddleware implements \Pagent\Contracts\Middleware
{
    public function __construct(
        private readonly int $maxRequestsPerMinute,
        private readonly string $cacheKey = 'agent_rate_limit'
    ) {}

    public function before(string $message, array $options): array
    {
        $cache = app('cache'); // Your cache instance
        $key = $this->cacheKey . ':' . ($options['user_id'] ?? 'anonymous');

        $count = $cache->get($key, 0);

        if ($count >= $this->maxRequestsPerMinute) {
            throw new RuntimeException('Rate limit exceeded. Please try again later.');
        }

        $cache->put($key, $count + 1, now()->addMinute());

        return [$message, $options];
    }

    public function after(object $response): object
    {
        return $response;
    }
}

$agent = agent('rate-limited')
    ->provider('anthropic')
    ->middleware(new RateLimitMiddleware(maxRequestsPerMinute: 10));
```

## Error Handling and Logging

Production systems must handle errors gracefully with comprehensive logging.

### Structured Error Handling

```php
use Psr\Log\LoggerInterface;

final class ProductionAgent
{
    public function __construct(
        private readonly Agent $agent,
        private readonly LoggerInterface $logger
    ) {}

    public function prompt(string $input, array $context = []): object
    {
        $startTime = microtime(true);

        try {
            $this->logger->info('Agent request', [
                'agent' => $this->agent->getName(),
                'input_length' => strlen($input),
                'context' => $context,
            ]);

            $response = $this->agent->prompt($input);

            $duration = microtime(true) - $startTime;

            $this->logger->info('Agent response', [
                'agent' => $this->agent->getName(),
                'duration_ms' => round($duration * 1000, 2),
                'tokens' => $response->tokens ?? 0,
                'model' => $response->model ?? 'unknown',
            ]);

            return $response;

        } catch (GuardException $e) {
            $this->logger->warning('Guard violation', [
                'agent' => $this->agent->getName(),
                'guard' => $e->guardName ?? get_class($e),
                'input' => substr($input, 0, 100),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            // Return safe fallback
            return (object) [
                'content' => 'I cannot process that request.',
                'guard_triggered' => $e->guardName ?? get_class($e),
                'model' => 'fallback',
                'tokens' => 0,
            ];

        } catch (RuntimeException $e) {
            $this->logger->error('Agent runtime error', [
                'agent' => $this->agent->getName(),
                'error' => $e->getMessage(),
                'input' => substr($input, 0, 100),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            throw $e;

        } catch (Exception $e) {
            $this->logger->critical('Unexpected agent error', [
                'agent' => $this->agent->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new RuntimeException('An unexpected error occurred', 0, $e);
        }
    }
}
```

### Timeout Protection

Prevent hanging requests with timeout handling:

```php
function promptWithTimeout(Agent $agent, string $input, int $timeoutSeconds = 30): object
{
    $startTime = time();

    set_error_handler(function() use ($startTime, $timeoutSeconds) {
        if (time() - $startTime > $timeoutSeconds) {
            throw new RuntimeException('Agent prompt timed out after ' . $timeoutSeconds . 's');
        }
    });

    try {
        $response = $agent->prompt($input);
        restore_error_handler();
        return $response;
    } catch (Exception $e) {
        restore_error_handler();
        throw $e;
    }
}
```

## Scaling Strategies

Pagent agents are designed to scale horizontally. Understanding the architecture is crucial for production deployments.

### Stateless Agent Architecture

Agents are **stateless by default**—they do not share state across PHP processes:

```php
// Each request creates its own agent instance
function handleRequest(string $input): object
{
    $agent = agent('stateless-handler')
        ->provider('anthropic')
        ->system('You are a helpful assistant.');

    return $agent->prompt($input);
}
```

This pattern works perfectly in:

- PHP-FPM with multiple worker processes
- Containerized environments (Docker, Kubernetes)
- Serverless functions (AWS Lambda, Google Cloud Functions)
- Horizontal scaling with load balancers

### Persistent Memory for Stateful Conversations

When conversations need persistence across requests, use memory adapters:

```php
use Pagent\Memory\Adapters\SqliteAdapter;

$agent = agent('persistent-chat')
    ->provider('anthropic')
    ->memory('Sqlite', [
        'path' => '/data/conversations.db',
    ])
    ->system('You are a helpful assistant.');

// Each user has their own session
$sessionId = 'user-' . $userId;

// Load conversation history from database
$agent->recall($sessionId);

// Continue conversation
$response = $agent->prompt($userInput);

// Automatically saved to database
```

The memory adapter handles persistence. Multiple processes can safely access the same database.

### Kubernetes Deployment

Example Kubernetes configuration for production agents:

```yaml
# agent-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: agent-service
spec:
  replicas: 3
  selector:
    matchLabels:
      app: agent-service
  template:
    metadata:
      labels:
        app: agent-service
    spec:
      containers:
        - name: php-fpm
          image: your-registry/agent-service:latest
          env:
            - name: ANTHROPIC_API_KEY
              valueFrom:
                secretKeyRef:
                  name: agent-secrets
                  key: anthropic-api-key
            - name: TELEMETRY_ENABLED
              value: "true"
            - name: TELEMETRY_ENDPOINT
              value: "http://otel-collector:4318/v1/traces"
          resources:
            requests:
              memory: "256Mi"
              cpu: "250m"
            limits:
              memory: "512Mi"
              cpu: "500m"
          livenessProbe:
            httpGet:
              path: /health
              port: 9000
            initialDelaySeconds: 10
            periodSeconds: 30
          readinessProbe:
            httpGet:
              path: /ready
              port: 9000
            initialDelaySeconds: 5
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: agent-service
spec:
  selector:
    app: agent-service
  ports:
    - port: 80
      targetPort: 9000
  type: LoadBalancer
```

### Auto-Scaling Based on Load

Configure Horizontal Pod Autoscaler:

```yaml
# agent-hpa.yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: agent-service-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: agent-service
  minReplicas: 2
  maxReplicas: 10
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 70
    - type: Resource
      resource:
        name: memory
        target:
          type: Utilization
          averageUtilization: 80
```

### Health Check Endpoints

Implement health checks for load balancers:

```php
// health.php
header('Content-Type: application/json');

try {
    // Check database connectivity
    $db = new PDO('sqlite:/data/conversations.db');

    // Quick agent test (without actual API call)
    $agent = agent('health-check')->provider('mock');

    // Check telemetry
    $telemetryEnabled = telemetry_enabled();

    echo json_encode([
        'status' => 'ok',
        'timestamp' => time(),
        'checks' => [
            'database' => 'ok',
            'agent' => 'ok',
            'telemetry' => $telemetryEnabled ? 'enabled' : 'disabled',
        ],
    ]);

} catch (Exception $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => 'Health check failed',
        'timestamp' => time(),
    ]);
}
```

## Incident Response

When things go wrong in production, rapid response is essential.

### Monitoring Alerts

Set up alerts for critical metrics:

```php
// Monitor guard violations in real-time
function monitorGuardViolations(): void
{
    $cache = app('cache');
    $key = 'guard_violations:' . date('Y-m-d-H');

    $count = $cache->increment($key, 1);
    $cache->expire($key, 3600); // Expire after 1 hour

    if ($count > 100) {
        // Alert via PagerDuty, Slack, etc.
        notifyAlert('High guard violation rate: ' . $count . ' in last hour');
    }
}

// Call in guard fallback
$agent->fallback(function ($e) {
    monitorGuardViolations();
    logger()->warning('Guard triggered', ['error' => $e]);
    return 'I cannot process that request.';
});
```

### Circuit Breaker Pattern

Prevent cascading failures when providers are down:

```php
final class CircuitBreakerMiddleware implements \Pagent\Contracts\Middleware
{
    private const FAILURE_THRESHOLD = 5;
    private const TIMEOUT_SECONDS = 60;

    private int $failureCount = 0;
    private ?int $circuitOpenTime = null;

    public function before(string $message, array $options): array
    {
        if ($this->isOpen()) {
            throw new RuntimeException('Circuit breaker open - service unavailable');
        }

        return [$message, $options];
    }

    public function after(object $response): object
    {
        // Success - reset circuit
        $this->failureCount = 0;
        $this->circuitOpenTime = null;

        return $response;
    }

    public function onError(Exception $e): void
    {
        $this->failureCount++;

        if ($this->failureCount >= self::FAILURE_THRESHOLD) {
            $this->circuitOpenTime = time();
            logger()->critical('Circuit breaker opened after ' . self::FAILURE_THRESHOLD . ' failures');
        }
    }

    private function isOpen(): bool
    {
        if ($this->circuitOpenTime === null) {
            return false;
        }

        // Check if timeout has passed
        if (time() - $this->circuitOpenTime > self::TIMEOUT_SECONDS) {
            // Attempt to close circuit
            $this->circuitOpenTime = null;
            $this->failureCount = 0;
            return false;
        }

        return true;
    }
}
```

### Graceful Degradation

Provide fallback behavior when primary systems fail:

```php
final class FallbackAgent
{
    public function __construct(
        private readonly Agent $primary,
        private readonly Agent $fallback
    ) {}

    public function prompt(string $input): object
    {
        try {
            return $this->primary->prompt($input);
        } catch (Exception $e) {
            logger()->warning('Primary agent failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            try {
                return $this->fallback->prompt($input);
            } catch (Exception $fallbackError) {
                logger()->error('Both agents failed', [
                    'primary_error' => $e->getMessage(),
                    'fallback_error' => $fallbackError->getMessage(),
                ]);

                return (object) [
                    'content' => 'Service temporarily unavailable. Please try again later.',
                    'model' => 'fallback',
                    'tokens' => 0,
                ];
            }
        }
    }
}

// Usage
$primary = agent('gpt4')->provider('openai')->model('gpt-4-turbo');
$fallback = agent('claude')->provider('anthropic')->model('claude-sonnet-4-6');

$robust = new FallbackAgent($primary, $fallback);
```

## Production Checklist

Before deploying to production, verify:

**Configuration:**

- [ ] API keys loaded from environment variables or secret store
- [ ] No hardcoded credentials in code
- [ ] Environment-specific configuration files
- [ ] Proper .gitignore to exclude secrets

**Security:**

- [ ] Guards configured (PII, content filter, prompt injection)
- [ ] Fallback responses for guard violations
- [ ] Rate limiting implemented
- [ ] Input validation and sanitization

**Observability:**

- [ ] Telemetry enabled with OTLP exporter
- [ ] Custom attributes added for business context
- [ ] Monitoring dashboards configured
- [ ] Alerts set up for error rates, latency, guard violations

**Scaling:**

- [ ] Stateless agent architecture
- [ ] Memory adapters for persistent conversations
- [ ] Health check endpoints implemented
- [ ] Auto-scaling rules configured

**Error Handling:**

- [ ] Structured logging with context
- [ ] Exception handling for all error types
- [ ] Circuit breaker for provider failures
- [ ] Graceful degradation strategy

**Testing:**

- [ ] Integration tests with real providers
- [ ] Load testing under expected traffic
- [ ] Chaos testing for failure scenarios
- [ ] Rollback plan documented

---

Production deployment transforms your agent from experiment to service. With proper configuration, security, observability, and error handling, you can deploy agents that are reliable, scalable, and maintainable. In the next chapter, we'll explore building complex multi-agent systems that coordinate to solve sophisticated problems.
