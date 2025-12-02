# Chapter 27: Production Deployment

## What You'll Learn

By the end of this chapter, you'll be able to:

- Configure Pagent for production environments with optimal settings
- Implement secure API key management using industry best practices
- Set up comprehensive monitoring and alerting for AI operations
- Design and implement scaling strategies for high-throughput workloads
- Handle production incidents effectively with automated recovery

**Prerequisites:** Solid understanding of Chapters 1-24, especially error handling, rate limiting, and observability concepts.

**Time Estimate:** 45-60 minutes

**Final Result:** A production-ready Pagent deployment with monitoring, scaling, and incident response capabilities.

## Production Environment Configuration

### Understanding Production Requirements

Production AI systems require careful consideration of reliability, security, and performance. Let's start with a comprehensive production configuration:

```php
// config/pagent-production.php
<?php

declare(strict_types=1);

return [
    'environment' => 'production',

    // Provider configurations with failover
    'providers' => [
        'primary' => [
            'type' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_uri' => env('ANTHROPIC_BASE_URI', 'https://api.anthropic.com'),
            'timeout' => 30,
            'retry' => [
                'times' => 3,
                'sleep' => 1000,
                'multiplier' => 2,
            ],
        ],
        'fallback' => [
            'type' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'timeout' => 30,
            'retry' => [
                'times' => 2,
                'sleep' => 500,
            ],
        ],
    ],

    // Rate limiting configuration
    'rate_limiting' => [
        'enabled' => true,
        'requests_per_minute' => env('PAGENT_RATE_LIMIT', 60),
        'burst_limit' => env('PAGENT_BURST_LIMIT', 100),
        'storage' => env('RATE_LIMIT_STORAGE', 'redis'),
    ],

    // Observability settings
    'observability' => [
        'enabled' => true,
        'sampling_rate' => env('TRACE_SAMPLING_RATE', 0.1),
        'export_interval' => 60,
        'batch_size' => 100,
        'exporters' => [
            'otlp' => [
                'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
                'headers' => env('OTEL_EXPORTER_OTLP_HEADERS'),
            ],
        ],
    ],

    // Cache configuration
    'cache' => [
        'enabled' => true,
        'driver' => 'redis',
        'ttl' => 3600,
        'prefix' => 'pagent:prod:',
    ],

    // Security settings
    'security' => [
        'sanitize_logs' => true,
        'mask_api_keys' => true,
        'allowed_models' => env('ALLOWED_MODELS', 'claude-3-opus,gpt-4'),
        'max_tokens' => env('MAX_TOKENS', 4096),
    ],
];
```

### Production Initialization

Create a production-ready factory that implements all safety measures:

```php
// src/Production/ProductionAgentFactory.php
<?php

declare(strict_types=1);

namespace Pagent\Production;

use Pagent\Agent;
use Pagent\AgentBuilder;
use Pagent\Contracts\Provider;
use Pagent\Observability\TelemetryManager;
use Pagent\Production\Monitoring\HealthChecker;
use Pagent\Production\Security\SecretManager;
use Psr\Log\LoggerInterface;

final class ProductionAgentFactory
{
    private SecretManager $secrets;
    private TelemetryManager $telemetry;
    private HealthChecker $health;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecretManager $secrets,
        TelemetryManager $telemetry,
        HealthChecker $health,
        LoggerInterface $logger,
        array $config
    ) {
        $this->secrets = $secrets;
        $this->telemetry = $telemetry;
        $this->health = $health;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function create(string $purpose = 'default'): Agent
    {
        // Validate environment
        $this->validateProductionEnvironment();

        // Create primary provider with monitoring
        $primary = $this->createProvider($this->config['providers']['primary']);

        // Create fallback provider if configured
        $fallback = isset($this->config['providers']['fallback'])
            ? $this->createProvider($this->config['providers']['fallback'])
            : null;

        // Build agent with all production features
        $builder = (new AgentBuilder())
            ->withProvider($primary)
            ->withTelemetry($this->telemetry)
            ->withLogger($this->logger)
            ->withMiddleware($this->createMiddlewareStack())
            ->withMetadata([
                'environment' => 'production',
                'purpose' => $purpose,
                'instance_id' => gethostname(),
                'deployed_at' => date('c'),
            ]);

        if ($fallback !== null) {
            $builder->withFallbackProvider($fallback);
        }

        // Register health check
        $agent = $builder->build();
        $this->health->register($agent);

        return $agent;
    }

    private function createProvider(array $config): Provider
    {
        // Fetch API key from secret manager
        $apiKey = $this->secrets->getSecret($config['api_key_secret'] ?? $config['api_key']);

        // Create provider based on type
        return match ($config['type']) {
            'anthropic' => new \Pagent\Providers\Anthropic(
                apiKey: $apiKey,
                baseUri: $config['base_uri'],
                timeout: $config['timeout'],
                retry: $config['retry']
            ),
            'openai' => new \Pagent\Providers\OpenAI(
                apiKey: $apiKey,
                timeout: $config['timeout'],
                retry: $config['retry']
            ),
            default => throw new \InvalidArgumentException("Unknown provider: {$config['type']}")
        };
    }

    private function createMiddlewareStack(): array
    {
        return [
            new \Pagent\Middleware\RateLimitMiddleware($this->config['rate_limiting']),
            new \Pagent\Middleware\CircuitBreakerMiddleware(),
            new \Pagent\Middleware\ValidationMiddleware($this->config['security']),
            new \Pagent\Middleware\CacheMiddleware($this->config['cache']),
            new \Pagent\Middleware\MetricsMiddleware(),
        ];
    }

    private function validateProductionEnvironment(): void
    {
        $checks = [
            'PHP version >= 8.3' => PHP_VERSION_ID >= 80300,
            'OpenSSL enabled' => extension_loaded('openssl'),
            'Redis available' => extension_loaded('redis'),
            'APCu enabled' => extension_loaded('apcu'),
            'Memory limit >= 256M' => $this->getMemoryLimitBytes() >= 268435456,
        ];

        foreach ($checks as $check => $passed) {
            if (!$passed) {
                throw new \RuntimeException("Production requirement failed: $check");
            }
        }
    }

    private function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
```

## Secure Key Management

### Implementing Secret Manager

Never store API keys in code or environment variables directly. Use a dedicated secret management system:

```php
// src/Production/Security/SecretManager.php
<?php

declare(strict_types=1);

namespace Pagent\Production\Security;

use Aws\SecretsManager\SecretsManagerClient;
use Psr\Cache\CacheItemPoolInterface;

final class SecretManager
{
    private SecretsManagerClient $client;
    private CacheItemPoolInterface $cache;
    private string $keyPrefix;
    private int $cacheTtl;

    public function __construct(
        SecretsManagerClient $client,
        CacheItemPoolInterface $cache,
        string $keyPrefix = 'pagent',
        int $cacheTtl = 300
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->keyPrefix = $keyPrefix;
        $this->cacheTtl = $cacheTtl;
    }

    public function getSecret(string $name): string
    {
        $cacheKey = "secret:{$this->keyPrefix}:{$name}";
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        try {
            $result = $this->client->getSecretValue([
                'SecretId' => "{$this->keyPrefix}/{$name}",
            ]);

            $secret = $result['SecretString'] ?? '';

            // Cache with short TTL for rotation support
            $item->set($secret);
            $item->expiresAfter($this->cacheTtl);
            $this->cache->save($item);

            return $secret;
        } catch (\Exception $e) {
            throw new SecretNotFoundException(
                "Failed to retrieve secret: {$name}",
                previous: $e
            );
        }
    }

    public function rotateSecret(string $name): void
    {
        // Clear cache immediately
        $cacheKey = "secret:{$this->keyPrefix}:{$name}";
        $this->cache->deleteItem($cacheKey);

        // Trigger rotation in AWS
        $this->client->rotateSecret([
            'SecretId' => "{$this->keyPrefix}/{$name}",
            'RotateImmediately' => true,
        ]);
    }
}
```

### Environment-Specific Configuration

Use environment detection to load appropriate secrets:

```php
// src/Production/Security/EnvironmentDetector.php
<?php

declare(strict_types=1);

namespace Pagent\Production\Security;

final class EnvironmentDetector
{
    public static function getEnvironment(): string
    {
        // Check multiple sources in priority order
        if ($env = getenv('APP_ENV')) {
            return $env;
        }

        if ($env = $_SERVER['APP_ENV'] ?? null) {
            return $env;
        }

        // Check for cloud provider metadata
        if (self::isAWS()) {
            return self::getAWSEnvironment();
        }

        if (self::isGCP()) {
            return self::getGCPEnvironment();
        }

        // Default to development if not detected
        return 'development';
    }

    private static function isAWS(): bool
    {
        return file_exists('/opt/aws/bin/ec2-metadata') ||
               getenv('AWS_EXECUTION_ENV') !== false;
    }

    private static function getAWSEnvironment(): string
    {
        // Query instance tags
        $metadata = @file_get_contents(
            'http://169.254.169.254/latest/meta-data/tags/instance/Environment'
        );

        return $metadata ?: 'production';
    }

    private static function isGCP(): bool
    {
        return getenv('GCP_PROJECT') !== false ||
               file_exists('/sys/class/dmi/id/product_name') &&
               str_contains(
                   file_get_contents('/sys/class/dmi/id/product_name'),
                   'Google'
               );
    }

    private static function getGCPEnvironment(): string
    {
        // Query instance metadata
        $opts = [
            'http' => [
                'header' => "Metadata-Flavor: Google\r\n",
            ],
        ];

        $context = stream_context_create($opts);
        $metadata = @file_get_contents(
            'http://metadata.google.internal/computeMetadata/v1/instance/attributes/environment',
            false,
            $context
        );

        return $metadata ?: 'production';
    }
}
```

## Monitoring and Alerting Setup

### Health Check System

Implement comprehensive health checks for production monitoring:

```php
// src/Production/Monitoring/HealthChecker.php
<?php

declare(strict_types=1);

namespace Pagent\Production\Monitoring;

use Pagent\Agent;
use Psr\Log\LoggerInterface;

final class HealthChecker
{
    private array $agents = [];
    private array $checks = [];
    private LoggerInterface $logger;
    private MetricsCollector $metrics;

    public function __construct(
        LoggerInterface $logger,
        MetricsCollector $metrics
    ) {
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function register(Agent $agent): void
    {
        $this->agents[spl_object_id($agent)] = $agent;
    }

    public function addCheck(string $name, callable $check): void
    {
        $this->checks[$name] = $check;
    }

    public function runHealthCheck(): HealthReport
    {
        $report = new HealthReport();

        // Check API connectivity
        foreach ($this->agents as $agent) {
            try {
                $start = microtime(true);
                $response = $agent->ask('ping')->wait();
                $latency = (microtime(true) - $start) * 1000;

                $report->addCheck('api_connectivity', true, [
                    'latency_ms' => $latency,
                    'provider' => $agent->getProvider()->getName(),
                ]);

                $this->metrics->recordLatency('health_check', $latency);
            } catch (\Exception $e) {
                $report->addCheck('api_connectivity', false, [
                    'error' => $e->getMessage(),
                    'provider' => $agent->getProvider()->getName(),
                ]);

                $this->logger->error('Health check failed', [
                    'check' => 'api_connectivity',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Run custom checks
        foreach ($this->checks as $name => $check) {
            try {
                $result = $check();
                $report->addCheck($name, $result['healthy'], $result['metadata'] ?? []);
            } catch (\Exception $e) {
                $report->addCheck($name, false, ['error' => $e->getMessage()]);
            }
        }

        // Check system resources
        $report->addCheck('memory_usage', $this->checkMemoryUsage());
        $report->addCheck('disk_space', $this->checkDiskSpace());
        $report->addCheck('redis_connection', $this->checkRedisConnection());

        return $report;
    }

    private function checkMemoryUsage(): array
    {
        $used = memory_get_usage(true);
        $limit = $this->getMemoryLimit();
        $percentage = ($used / $limit) * 100;

        return [
            'healthy' => $percentage < 80,
            'metadata' => [
                'used_mb' => round($used / 1048576, 2),
                'limit_mb' => round($limit / 1048576, 2),
                'percentage' => round($percentage, 2),
            ],
        ];
    }

    private function checkDiskSpace(): array
    {
        $free = disk_free_space('/');
        $total = disk_total_space('/');
        $percentage = (($total - $free) / $total) * 100;

        return [
            'healthy' => $percentage < 90,
            'metadata' => [
                'free_gb' => round($free / 1073741824, 2),
                'total_gb' => round($total / 1073741824, 2),
                'used_percentage' => round($percentage, 2),
            ],
        ];
    }

    private function checkRedisConnection(): array
    {
        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $redis->ping();

            return [
                'healthy' => true,
                'metadata' => [
                    'connected' => true,
                    'version' => $redis->info()['redis_version'] ?? 'unknown',
                ],
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'metadata' => [
                    'connected' => false,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
}
```

## Kubernetes Deployment

### Deployment Configuration

Deploy Pagent in Kubernetes with proper resource management:

```yaml
# k8s/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: pagent-app
  namespace: production
  labels:
    app: pagent
    version: v1.0.0
spec:
  replicas: 3
  selector:
    matchLabels:
      app: pagent
  template:
    metadata:
      labels:
        app: pagent
        version: v1.0.0
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9090"
        prometheus.io/path: "/metrics"
    spec:
      serviceAccountName: pagent
      containers:
        - name: pagent
          image: your-registry/pagent:1.0.0
          ports:
            - containerPort: 8080
              name: http
            - containerPort: 9090
              name: metrics
          env:
            - name: APP_ENV
              value: production
            - name: OTEL_EXPORTER_OTLP_ENDPOINT
              value: http://otel-collector:4317
            - name: REDIS_HOST
              value: redis-service.production.svc.cluster.local
          envFrom:
            - secretRef:
                name: pagent-secrets
          resources:
            requests:
              memory: "256Mi"
              cpu: "250m"
            limits:
              memory: "512Mi"
              cpu: "1000m"
          livenessProbe:
            httpGet:
              path: /health/live
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 10
          readinessProbe:
            httpGet:
              path: /health/ready
              port: 8080
            initialDelaySeconds: 5
            periodSeconds: 5
          volumeMounts:
            - name: config
              mountPath: /app/config
              readOnly: true
      volumes:
        - name: config
          configMap:
            name: pagent-config
```

### Horizontal Pod Autoscaling

Configure auto-scaling based on AI workload metrics:

```yaml
# k8s/hpa.yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: pagent-hpa
  namespace: production
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: pagent-app
  minReplicas: 3
  maxReplicas: 20
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
    - type: Pods
      pods:
        metric:
          name: pagent_requests_per_second
        target:
          type: AverageValue
          averageValue: "100"
    - type: External
      external:
        metric:
          name: pagent_api_latency_p95
          selector:
            matchLabels:
              queue: ai_requests
        target:
          type: Value
          value: "2000m" # 2 seconds
  behavior:
    scaleUp:
      stabilizationWindowSeconds: 60
      policies:
        - type: Percent
          value: 100
          periodSeconds: 60
        - type: Pods
          value: 4
          periodSeconds: 60
    scaleDown:
      stabilizationWindowSeconds: 300
      policies:
        - type: Percent
          value: 50
          periodSeconds: 180
```

## Incident Response Automation

### Automated Recovery System

Implement self-healing capabilities for common production issues:

```php
// src/Production/Recovery/AutoRecovery.php
<?php

declare(strict_types=1);

namespace Pagent\Production\Recovery;

use Pagent\Agent;
use Psr\Log\LoggerInterface;

final class AutoRecovery
{
    private LoggerInterface $logger;
    private AlertManager $alertManager;
    private array $recoveryStrategies = [];

    public function registerStrategy(string $errorType, callable $strategy): void
    {
        $this->recoveryStrategies[$errorType] = $strategy;
    }

    public function handleIncident(Agent $agent, \Throwable $error): bool
    {
        $errorType = $this->classifyError($error);

        $this->logger->warning('Incident detected', [
            'type' => $errorType,
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ]);

        // Attempt automatic recovery
        if (isset($this->recoveryStrategies[$errorType])) {
            try {
                $recovered = $this->recoveryStrategies[$errorType]($agent, $error);

                if ($recovered) {
                    $this->logger->info('Automatic recovery successful', [
                        'type' => $errorType,
                    ]);

                    $this->alertManager->sendRecoveryNotification($errorType);
                    return true;
                }
            } catch (\Exception $e) {
                $this->logger->error('Recovery strategy failed', [
                    'type' => $errorType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Escalate if recovery fails
        $this->escalateIncident($errorType, $error);
        return false;
    }

    private function classifyError(\Throwable $error): string
    {
        return match (true) {
            $error instanceof RateLimitException => 'rate_limit',
            $error instanceof AuthenticationException => 'auth_failure',
            $error instanceof TimeoutException => 'timeout',
            $error instanceof QuotaExceededException => 'quota_exceeded',
            str_contains($error->getMessage(), 'connection') => 'connection_error',
            default => 'unknown',
        };
    }

    private function escalateIncident(string $type, \Throwable $error): void
    {
        $this->alertManager->createIncident([
            'severity' => $this->getSeverity($type),
            'title' => "Auto-recovery failed: $type",
            'description' => $error->getMessage(),
            'runbook_url' => "https://docs.example.com/runbooks/$type",
            'labels' => [
                'service' => 'pagent',
                'environment' => 'production',
                'auto_recovery' => 'failed',
            ],
        ]);
    }
}
```

## Summary

You've successfully learned how to deploy Pagent in production environments. You can now:

✅ Configure production environments with optimal settings
✅ Implement secure API key management using AWS Secrets Manager
✅ Set up comprehensive monitoring with health checks and metrics
✅ Deploy to Kubernetes with auto-scaling capabilities
✅ Handle incidents with automated recovery strategies

### Key Takeaways

1. **Environment Configuration**: Always validate production requirements before deployment
2. **Secret Management**: Never store API keys in code; use dedicated secret managers
3. **Monitoring**: Implement health checks at multiple levels (API, system, dependencies)
4. **Scaling**: Use HPA with custom metrics for AI workload scaling
5. **Incident Response**: Automate recovery for common issues to reduce MTTR

### Next Steps

- Implement blue-green deployments for zero-downtime updates
- Set up canary releases for gradual rollouts
- Create custom Grafana dashboards for AI metrics
- Implement cost optimization strategies
- Build compliance and audit logging systems

### Additional Resources

- [Kubernetes Best Practices for AI Workloads](https://kubernetes.io/docs/concepts/workloads/)
- [AWS Secrets Manager Documentation](https://docs.aws.amazon.com/secretsmanager/)
- [OpenTelemetry Production Deployment Guide](https://opentelemetry.io/docs/reference/specification/)
- [SRE Practices for ML Systems](https://sre.google/workbook/ml-sre/)

Remember: Production deployment is an iterative process. Start with basic monitoring and scaling, then gradually add more sophisticated features based on observed behavior and requirements. Always prioritize security and reliability over feature velocity in production environments.
