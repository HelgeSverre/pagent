# Chapter 15: Reliability Patterns

## What You'll Learn

In this chapter, you'll master reliability patterns that transform fragile AI integrations into robust production systems. You'll implement retry strategies that handle transient failures gracefully, build circuit breakers that prevent cascade failures, configure intelligent timeouts that balance performance and reliability, and create fallback mechanisms that maintain service availability. By the end, you'll have a toolkit for building AI systems that gracefully handle the inevitable failures of distributed systems.

## Prerequisites

- Completed Chapter 14: Observability Setup
- Understanding of PHP exception handling
- Familiarity with async patterns
- Basic knowledge of distributed systems concepts
- Experience with API rate limiting

## Key Concepts

Before diving into implementation, let's understand the reliability patterns we'll be working with:

**Retry Policies**: Automated retry mechanisms that handle transient failures by attempting operations multiple times with configurable backoff strategies.

**Circuit Breaker Pattern**: A protective mechanism that monitors failure rates and temporarily stops calling failing services, preventing cascade failures and giving systems time to recover.

**Timeout Configuration**: Time limits on operations that prevent indefinite waiting and ensure predictable system behavior under load.

**Fallback Strategies**: Alternative paths when primary operations fail, maintaining functionality even when optimal paths aren't available.

## Building a Resilient API Gateway

Let's start by creating a resilient API gateway that incorporates all our reliability patterns. This gateway will manage connections to multiple AI providers with automatic failover:

```php
<?php

declare(strict_types=1);

namespace App\Reliability;

use Pagent\Agent;
use Pagent\AgentBuilder;
use Psr\Log\LoggerInterface;

final class ResilientGateway
{
    private array $providers = [];
    private array $circuitBreakers = [];
    private array $retryConfigs = [];
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->initializeProviders();
    }

    private function initializeProviders(): void
    {
        // Primary provider with aggressive retry
        $this->providers['anthropic'] = [
            'builder' => fn() => anthropic('claude-3-5-sonnet-latest'),
            'priority' => 1,
            'timeout' => 30,
        ];

        // Secondary provider with moderate retry
        $this->providers['openai'] = [
            'builder' => fn() => openai('gpt-4'),
            'priority' => 2,
            'timeout' => 25,
        ];

        // Fallback provider with minimal retry
        $this->providers['ollama'] = [
            'builder' => fn() => ollama('llama3'),
            'priority' => 3,
            'timeout' => 20,
        ];

        // Initialize circuit breakers for each provider
        foreach ($this->providers as $name => $config) {
            $this->circuitBreakers[$name] = new CircuitBreaker(
                failureThreshold: 3,
                recoveryTimeout: 60,
                halfOpenAttempts: 2
            );

            $this->retryConfigs[$name] = new RetryConfig(
                maxAttempts: 3 - $config['priority'] + 1,
                baseDelay: 1000 * $config['priority'],
                maxDelay: 10000,
                multiplier: 2.0
            );
        }
    }

    public function query(string $prompt, array $context = []): string
    {
        $sortedProviders = $this->getSortedAvailableProviders();

        foreach ($sortedProviders as $name => $config) {
            $breaker = $this->circuitBreakers[$name];

            // Skip if circuit is open
            if ($breaker->isOpen()) {
                $this->logger->info("Circuit breaker open for {$name}");
                continue;
            }

            try {
                $result = $this->executeWithRetry(
                    $name,
                    $config,
                    $prompt,
                    $context
                );

                $breaker->recordSuccess();
                return $result;

            } catch (\Exception $e) {
                $breaker->recordFailure();
                $this->logger->error(
                    "Provider {$name} failed: {$e->getMessage()}"
                );

                // Continue to next provider
                continue;
            }
        }

        // All providers failed, use fallback
        return $this->executeFallback($prompt, $context);
    }

    private function executeWithRetry(
        string $name,
        array $config,
        string $prompt,
        array $context
    ): string {
        $retry = $this->retryConfigs[$name];
        $attempt = 0;
        $lastException = null;

        while ($attempt < $retry->maxAttempts) {
            $attempt++;

            try {
                return $this->executeWithTimeout(
                    $config,
                    $prompt,
                    $context,
                    $config['timeout']
                );
            } catch (\Exception $e) {
                $lastException = $e;

                // Check if error is retryable
                if (!$this->isRetryableError($e)) {
                    throw $e;
                }

                if ($attempt < $retry->maxAttempts) {
                    $delay = $this->calculateBackoff($retry, $attempt);
                    $this->logger->info(
                        "Retrying {$name} after {$delay}ms (attempt {$attempt})"
                    );
                    usleep($delay * 1000);
                }
            }
        }

        throw $lastException ?? new \RuntimeException(
            "Max retry attempts reached for {$name}"
        );
    }

    private function executeWithTimeout(
        array $config,
        string $prompt,
        array $context,
        int $timeout
    ): string {
        $builder = $config['builder']();

        // Configure timeout
        $agent = $builder
            ->withContext($context)
            ->withTimeout($timeout)
            ->build();

        // Execute with timeout monitoring
        $startTime = microtime(true);

        try {
            $result = $agent->query($prompt);

            $duration = microtime(true) - $startTime;
            $this->logger->info(sprintf(
                "Query completed in %.2fs",
                $duration
            ));

            return $result;

        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;

            if ($duration >= $timeout) {
                throw new TimeoutException(
                    "Operation timed out after {$timeout}s",
                    previous: $e
                );
            }

            throw $e;
        }
    }

    private function calculateBackoff(
        RetryConfig $config,
        int $attempt
    ): int {
        $delay = $config->baseDelay * pow($config->multiplier, $attempt - 1);

        // Add jitter to prevent thundering herd
        $jitter = random_int(-$delay / 4, $delay / 4);
        $delay += $jitter;

        return (int) min($delay, $config->maxDelay);
    }

    private function isRetryableError(\Exception $e): bool
    {
        // Rate limiting errors
        if ($e->getCode() === 429) {
            return true;
        }

        // Temporary network errors
        if ($e instanceof NetworkException) {
            return true;
        }

        // Service unavailable
        if ($e->getCode() >= 500 && $e->getCode() < 600) {
            return true;
        }

        // Timeout errors
        if ($e instanceof TimeoutException) {
            return true;
        }

        return false;
    }

    private function executeFallback(
        string $prompt,
        array $context
    ): string {
        $this->logger->warning("All providers failed, using fallback");

        // Try cached response first
        $cacheKey = $this->getCacheKey($prompt, $context);
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        // Use degraded response
        return $this->generateDegradedResponse($prompt);
    }

    private function generateDegradedResponse(string $prompt): string
    {
        // Simple rule-based fallback
        if (str_contains(strtolower($prompt), 'help')) {
            return "I'm experiencing technical difficulties. " .
                   "Please try again later or contact support.";
        }

        if (str_contains(strtolower($prompt), 'status')) {
            return "System is currently in degraded mode. " .
                   "Core services are being restored.";
        }

        return "I apologize, but I'm unable to process your " .
               "request at this time. Please try again shortly.";
    }

    private function getSortedAvailableProviders(): array
    {
        $available = [];

        foreach ($this->providers as $name => $config) {
            if (!$this->circuitBreakers[$name]->isOpen()) {
                $available[$name] = $config;
            }
        }

        // Sort by priority
        uasort($available, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $available;
    }
}
```

## Circuit Breaker Implementation

Now let's implement a proper circuit breaker that monitors failure patterns and protects our system:

```php
<?php

declare(strict_types=1);

namespace App\Reliability;

final class CircuitBreaker
{
    private int $failureCount = 0;
    private int $successCount = 0;
    private ?float $lastFailureTime = null;
    private CircuitState $state;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $recoveryTimeout = 60,
        private readonly int $halfOpenAttempts = 3
    ) {
        $this->state = CircuitState::Closed;
    }

    public function isOpen(): bool
    {
        $this->updateState();
        return $this->state === CircuitState::Open;
    }

    public function recordSuccess(): void
    {
        if ($this->state === CircuitState::HalfOpen) {
            $this->successCount++;

            if ($this->successCount >= $this->halfOpenAttempts) {
                $this->close();
            }
        } elseif ($this->state === CircuitState::Closed) {
            // Reset failure count on success in closed state
            $this->failureCount = 0;
        }
    }

    public function recordFailure(): void
    {
        $this->lastFailureTime = microtime(true);

        if ($this->state === CircuitState::HalfOpen) {
            // Single failure in half-open trips the breaker
            $this->open();
        } elseif ($this->state === CircuitState::Closed) {
            $this->failureCount++;

            if ($this->failureCount >= $this->failureThreshold) {
                $this->open();
            }
        }
    }

    private function updateState(): void
    {
        if ($this->state === CircuitState::Open) {
            if ($this->shouldAttemptReset()) {
                $this->halfOpen();
            }
        }
    }

    private function shouldAttemptReset(): bool
    {
        if ($this->lastFailureTime === null) {
            return false;
        }

        $elapsed = microtime(true) - $this->lastFailureTime;
        return $elapsed >= $this->recoveryTimeout;
    }

    private function open(): void
    {
        $this->state = CircuitState::Open;
        $this->failureCount = 0;
        $this->successCount = 0;
    }

    private function close(): void
    {
        $this->state = CircuitState::Closed;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->lastFailureTime = null;
    }

    private function halfOpen(): void
    {
        $this->state = CircuitState::HalfOpen;
        $this->successCount = 0;
    }

    public function getMetrics(): array
    {
        return [
            'state' => $this->state->value,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'last_failure' => $this->lastFailureTime,
        ];
    }
}

enum CircuitState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case HalfOpen = 'half_open';
}
```

## High-Availability Assistant

Let's build a high-availability assistant that uses health checks and automatic recovery:

```php
<?php

declare(strict_types=1);

namespace App\Reliability;

use Pagent\Agent;

final class HighAvailabilityAssistant
{
    private array $healthChecks = [];
    private array $metrics = [];

    public function __construct(
        private readonly ResilientGateway $gateway,
        private readonly HealthMonitor $monitor,
        private readonly MetricsCollector $collector
    ) {
        $this->initializeHealthChecks();
    }

    public function process(string $input): string
    {
        // Pre-flight health check
        if (!$this->isHealthy()) {
            return $this->handleUnhealthy($input);
        }

        // Track request
        $requestId = $this->generateRequestId();
        $this->collector->startTimer($requestId);

        try {
            // Process with monitoring
            $result = $this->processWithMonitoring($input, $requestId);

            // Record success metrics
            $this->collector->recordSuccess($requestId);

            return $result;

        } catch (\Exception $e) {
            // Record failure metrics
            $this->collector->recordFailure($requestId, $e);

            // Attempt self-healing
            return $this->attemptRecovery($input, $e);
        } finally {
            $this->collector->endTimer($requestId);
        }
    }

    private function processWithMonitoring(
        string $input,
        string $requestId
    ): string {
        // Add request context
        $context = [
            'request_id' => $requestId,
            'timestamp' => time(),
            'health_score' => $this->getHealthScore(),
        ];

        // Process with deadline
        $deadline = time() + 60; // 60 second deadline

        if (time() > $deadline) {
            throw new DeadlineExceededException(
                "Request deadline exceeded"
            );
        }

        return $this->gateway->query($input, $context);
    }

    private function attemptRecovery(
        string $input,
        \Exception $error
    ): string {
        // Log the error
        $this->monitor->logError($error);

        // Try recovery strategies
        $strategies = [
            'retry_simplified' => fn() => $this->retrySimplified($input),
            'use_cache' => fn() => $this->useCachedResponse($input),
            'degrade_gracefully' => fn() => $this->degradeGracefully($input),
        ];

        foreach ($strategies as $name => $strategy) {
            try {
                $result = $strategy();
                $this->monitor->logRecovery($name);
                return $result;
            } catch (\Exception $e) {
                continue;
            }
        }

        // All recovery failed
        throw new RecoveryFailedException(
            "All recovery strategies failed",
            previous: $error
        );
    }

    private function retrySimplified(string $input): string
    {
        // Simplify the query
        $simplified = $this->simplifyQuery($input);

        // Try with reduced requirements
        return $this->gateway->query($simplified, [
            'mode' => 'simplified',
            'max_tokens' => 100,
        ]);
    }

    private function isHealthy(): bool
    {
        $checks = [
            'gateway' => $this->checkGatewayHealth(),
            'memory' => $this->checkMemoryHealth(),
            'rate_limits' => $this->checkRateLimits(),
        ];

        $healthy = array_filter($checks);
        return count($healthy) === count($checks);
    }

    private function checkGatewayHealth(): bool
    {
        try {
            // Quick health probe
            $this->gateway->query("ping", ['health_check' => true]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkMemoryHealth(): bool
    {
        $usage = memory_get_usage(true);
        $limit = $this->getMemoryLimit();

        // Alert if using more than 80% of memory
        return $usage < ($limit * 0.8);
    }

    private function checkRateLimits(): bool
    {
        $limits = $this->monitor->getRateLimitStatus();

        foreach ($limits as $provider => $status) {
            if ($status['remaining'] < 10) {
                return false;
            }
        }

        return true;
    }

    private function getHealthScore(): float
    {
        $scores = [
            'gateway' => $this->checkGatewayHealth() ? 1.0 : 0.0,
            'memory' => $this->checkMemoryHealth() ? 1.0 : 0.5,
            'rate_limits' => $this->checkRateLimits() ? 1.0 : 0.3,
        ];

        return array_sum($scores) / count($scores);
    }
}
```

## Testing Your Reliability Patterns

Let's create comprehensive tests to verify our reliability mechanisms work correctly:

```php
<?php

declare(strict_types=1);

namespace Tests\Reliability;

use App\Reliability\ResilientGateway;
use App\Reliability\CircuitBreaker;

test('circuit breaker opens after threshold failures', function () {
    $breaker = new CircuitBreaker(
        failureThreshold: 3,
        recoveryTimeout: 1,
        halfOpenAttempts: 2
    );

    expect($breaker->isOpen())->toBeFalse();

    // Record failures
    $breaker->recordFailure();
    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeFalse();

    // Third failure opens the circuit
    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();
});

test('gateway retries with exponential backoff', function () {
    $gateway = new ResilientGateway(mockLogger());

    $attempts = [];

    // Mock provider that fails twice then succeeds
    $mockProvider = mockProvider()
        ->shouldFail(2)
        ->thenSucceed()
        ->trackAttempts($attempts);

    $result = $gateway->query("test prompt");

    expect($attempts)->toHaveCount(3)
        ->and($result)->toBe("success");

    // Verify backoff delays
    $delays = array_map(fn($a) => $a['delay'], $attempts);
    expect($delays[1])->toBeGreaterThan($delays[0])
        ->and($delays[2])->toBeGreaterThan($delays[1]);
});

test('fallback activates when all providers fail', function () {
    $gateway = new ResilientGateway(mockLogger());

    // Force all providers to fail
    forceAllProvidersOffline();

    $result = $gateway->query("help me");

    expect($result)->toContain("technical difficulties");
});

test('timeout prevents hanging requests', function () {
    $gateway = new ResilientGateway(mockLogger());

    // Mock slow provider
    $slowProvider = mockProvider()
        ->withDelay(35) // Exceeds 30s timeout
        ->build();

    expect(fn() => $gateway->query("test"))
        ->toThrow(TimeoutException::class);
});
```

## Monitoring and Alerting

Finally, let's add monitoring to track our reliability metrics:

```php
<?php

declare(strict_types=1);

namespace App\Reliability;

final class ReliabilityMonitor
{
    private array $metrics = [];

    public function recordMetric(string $name, float $value): void
    {
        $this->metrics[$name][] = [
            'value' => $value,
            'timestamp' => microtime(true),
        ];
    }

    public function getReliabilityScore(): float
    {
        $scores = [
            'success_rate' => $this->getSuccessRate(),
            'latency_score' => $this->getLatencyScore(),
            'availability' => $this->getAvailability(),
        ];

        return array_sum($scores) / count($scores);
    }

    private function getSuccessRate(): float
    {
        $total = count($this->metrics['requests'] ?? []);
        $success = count(array_filter(
            $this->metrics['requests'] ?? [],
            fn($r) => $r['success'] === true
        ));

        return $total > 0 ? $success / $total : 0.0;
    }

    private function getLatencyScore(): float
    {
        $latencies = $this->metrics['latency'] ?? [];
        if (empty($latencies)) {
            return 0.0;
        }

        $p95 = $this->calculatePercentile($latencies, 95);

        // Score based on P95 latency
        if ($p95 < 1.0) return 1.0;
        if ($p95 < 5.0) return 0.8;
        if ($p95 < 10.0) return 0.6;
        if ($p95 < 30.0) return 0.4;

        return 0.2;
    }
}
```

## Summary

You've now mastered reliability patterns that transform brittle AI integrations into production-ready systems. You've implemented retry strategies with exponential backoff and jitter, built circuit breakers that prevent cascade failures, configured intelligent timeouts that balance performance and reliability, and created fallback mechanisms that maintain service availability even when primary systems fail.

These patterns work together to create a self-healing system that gracefully handles the challenges of distributed AI services. Your gateway can automatically route around failures, recover from transient errors, and provide degraded but functional responses when necessary.

## Next Steps

In Chapter 16, we'll explore performance optimization techniques including response caching, request batching, and connection pooling. You'll learn to build systems that not only survive failures but thrive under load, delivering consistent sub-second responses even during traffic spikes.