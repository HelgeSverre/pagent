# Chapter 15: Reliability Patterns

When building production LLM applications, you need to handle failures gracefully. Network requests time out. APIs return errors. Rate limits get hit. Unsafe content gets generated. In this chapter, we'll explore how Pagent helps you build resilient agents through fallback mechanisms, error handling strategies, and custom middleware patterns.

The key insight: Pagent doesn't provide built-in retry logic or circuit breakers. Instead, it gives you the hooks and patterns to implement exactly the reliability strategy your application needs. This philosophy keeps the library lightweight while giving you complete control over how failures are handled.

## Understanding Pagent's Built-In Safety Features

Before implementing custom reliability patterns, let's understand what Pagent provides out of the box. The library includes several foundational safety mechanisms:

**Fallback Handling** - When guards detect unsafe content, you can specify fallback responses instead of throwing exceptions to users.

**Exception Propagation** - All provider errors, network failures, and runtime issues throw clear exceptions with actionable messages.

**Middleware Hooks** - The middleware system lets you intercept requests before and after LLM calls, perfect for implementing reliability patterns.

**Depth Limiting** - Tool calling automatically stops after 10 recursive rounds to prevent infinite loops (as covered in Chapter 8).

**Rate Limiting** - The built-in `RateLimitMiddleware` prevents exceeding request quotas.

Let's start with the simplest reliability pattern: fallbacks.

## The Fallback Pattern

Fallbacks provide safe default responses when guards detect violations. This pattern prevents your application from crashing or exposing unsafe content to users:

```php
$agent = agent('content-moderator')
    ->provider(anthropic())
    ->system('You are a helpful assistant.')
    ->guard('profanity')
    ->fallback(function ($exception) {
        // $exception is a GuardException
        $guardName = $exception->guardName;
        $input = $exception->input;
        $output = $exception->output;

        error_log("Guard '{$guardName}' triggered for input: {$input}");

        return "I apologize, but I cannot provide that response. Please rephrase your request.";
    })
    ->build();

// This will trigger the profanity guard
$response = $agent->prompt('Generate offensive content');

// Instead of throwing an exception, you get the fallback
echo $response->content;  // "I apologize, but I cannot provide..."

// The response object indicates a fallback was used
echo $response->provider;  // "fallback"
echo $response->model;     // "fallback"
echo $response->guard_triggered;  // "profanity"
```

The fallback closure receives a `GuardException` with full context about what went wrong. You can log the violation, return context-aware messages, or even attempt corrective action.

### Dynamic Fallback Responses

Make fallbacks context-aware by inspecting the exception:

```php
$agent->fallback(function ($exception) {
    // Provide specific guidance based on which guard failed
    return match($exception->guardName) {
        'profanity' => "Please keep the conversation respectful.",
        'pii' => "I cannot provide responses containing personal information.",
        'length' => "That response was too long. Please ask a more specific question.",
        default => "I cannot complete that request. Please try again.",
    };
});
```

### Fallback Without Guards

Fallbacks only trigger for `GuardException` violations. Other exceptions (network errors, provider failures, timeouts) propagate normally. This is by design - you want different handling for content violations versus infrastructure failures:

```php
try {
    $response = $agent->prompt('Hello');
} catch (GuardException $e) {
    // This is caught by fallback handler
    // You'll only reach this if no fallback is configured
    echo "Content violation: " . $e->getMessage();

} catch (RuntimeException $e) {
    // Provider errors, network issues, etc. - fallback doesn't catch these
    echo "Infrastructure error: " . $e->getMessage();
    // Implement retry logic here
}
```

## Error Handling Strategies

Pagent throws exceptions for failures. Understanding the exception hierarchy helps you implement appropriate recovery strategies:

```php
use RuntimeException;
use Pagent\Exceptions\GuardException;

$agent = agent('robust')->provider(anthropic())->build();

try {
    $response = $agent->prompt('What is PHP?');

} catch (GuardException $e) {
    // Guard violations - content-related issues
    error_log("Guard '{$e->guardName}' failed");
    // Show user-friendly message
    $response = (object)['content' => 'Request denied for safety reasons'];

} catch (RuntimeException $e) {
    // Infrastructure failures - network, auth, provider errors
    error_log("System error: " . $e->getMessage());

    // Could implement retry with exponential backoff here
    sleep(1);
    try {
        $response = $agent->prompt('What is PHP?');
    } catch (RuntimeException $e2) {
        // Still failing, give up
        throw new Exception('Service temporarily unavailable');
    }
}
```

## Implementing Retry Logic with Middleware

Pagent doesn't include built-in retry middleware, but the middleware interface makes it straightforward to implement your own. Here's a complete retry middleware with exponential backoff:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Contracts\Middleware;
use RuntimeException;

final class RetryMiddleware implements Middleware
{
    private int $currentAttempt = 0;

    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 1000,
        private readonly float $backoffMultiplier = 2.0,
    ) {}

    public function before(string $message, array $options): array
    {
        // Store the original options for potential retries
        $options['_retry_metadata'] = [
            'attempt' => $this->currentAttempt,
            'max_attempts' => $this->maxAttempts,
        ];

        return $options;
    }

    public function after(object $response): object
    {
        // Reset attempt counter on success
        $this->currentAttempt = 0;
        return $response;
    }

    public function handleException(\Throwable $e, callable $retry): object
    {
        $this->currentAttempt++;

        if ($this->currentAttempt >= $this->maxAttempts) {
            // Max attempts reached, give up
            throw new RuntimeException(
                "Failed after {$this->maxAttempts} attempts: " . $e->getMessage(),
                0,
                $e
            );
        }

        // Calculate delay with exponential backoff
        $delay = $this->baseDelayMs * ($this->backoffMultiplier ** ($this->currentAttempt - 1));
        usleep((int)($delay * 1000)); // Convert ms to microseconds

        error_log("Retry attempt {$this->currentAttempt}/{$this->maxAttempts} after {$delay}ms");

        // Retry the operation
        return $retry();
    }
}
```

However, note that the current middleware interface doesn't support exception handling directly. Instead, you'd wrap the agent's `prompt()` call with retry logic:

```php
function promptWithRetry($agent, $message, $options = [], $maxAttempts = 3)
{
    $attempt = 0;
    $baseDelay = 1000; // 1 second
    $backoff = 2.0;

    while ($attempt < $maxAttempts) {
        try {
            return $agent->prompt($message, $options);

        } catch (RuntimeException $e) {
            $attempt++;

            if ($attempt >= $maxAttempts) {
                throw new RuntimeException(
                    "Failed after {$maxAttempts} attempts: " . $e->getMessage(),
                    0,
                    $e
                );
            }

            $delay = $baseDelay * ($backoff ** ($attempt - 1));
            error_log("Retry attempt {$attempt}/{$maxAttempts} after {$delay}ms");

            usleep((int)($delay * 1000));
        }
    }
}

// Usage
$agent = agent('api')->provider(anthropic())->build();

try {
    $response = promptWithRetry($agent, 'What is machine learning?', [], 3);
    echo $response->content;
} catch (RuntimeException $e) {
    echo "All retry attempts failed: " . $e->getMessage();
}
```

This helper function implements:
- Configurable maximum attempts
- Exponential backoff (1s, 2s, 4s, ...)
- Clear error messages showing attempt count
- Original exception preservation

## Circuit Breaker Pattern

Circuit breakers prevent cascading failures by stopping requests to a failing service. After detecting too many failures, the circuit "opens" and fast-fails for a cooldown period before trying again.

Here's a simple circuit breaker implementation:

```php
<?php

final class CircuitBreaker
{
    private int $failureCount = 0;
    private ?int $openedAt = null;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 60,
    ) {}

    public function call(callable $operation): mixed
    {
        // Check if circuit is open
        if ($this->isOpen()) {
            if ($this->shouldAttemptReset()) {
                // Try to close the circuit
                error_log('Circuit breaker: attempting reset');
            } else {
                throw new RuntimeException(
                    "Circuit breaker is open. Try again in {$this->getRemainingCooldown()}s"
                );
            }
        }

        try {
            $result = $operation();

            // Success - reset failure count
            $this->onSuccess();

            return $result;

        } catch (\Throwable $e) {
            $this->onFailure();
            throw $e;
        }
    }

    private function isOpen(): bool
    {
        return $this->openedAt !== null;
    }

    private function shouldAttemptReset(): bool
    {
        if (!$this->isOpen()) {
            return false;
        }

        return (time() - $this->openedAt) >= $this->cooldownSeconds;
    }

    private function getRemainingCooldown(): int
    {
        if (!$this->isOpen()) {
            return 0;
        }

        return max(0, $this->cooldownSeconds - (time() - $this->openedAt));
    }

    private function onSuccess(): void
    {
        $this->failureCount = 0;
        $this->openedAt = null;
    }

    private function onFailure(): void
    {
        $this->failureCount++;

        if ($this->failureCount >= $this->failureThreshold) {
            $this->openedAt = time();
            error_log("Circuit breaker opened after {$this->failureCount} failures");
        }
    }
}

// Usage
$breaker = new CircuitBreaker(failureThreshold: 3, cooldownSeconds: 30);
$agent = agent('protected')->provider(anthropic())->build();

try {
    $response = $breaker->call(function () use ($agent) {
        return $agent->prompt('What is PHP?');
    });

    echo $response->content;

} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'Circuit breaker is open')) {
        echo "Service temporarily unavailable. Please try again later.";
    } else {
        echo "Request failed: " . $e->getMessage();
    }
}
```

The circuit breaker:
- Tracks consecutive failures
- Opens after hitting the threshold (default 5)
- Rejects requests during cooldown (default 60s)
- Automatically attempts to close after cooldown
- Resets on any successful request

## Timeout Configuration

Pagent providers use HTTP clients with configurable timeouts. While there's no agent-level timeout setting, you can control this at the provider level:

```php
// Anthropic provider with custom timeout
$provider = new \Pagent\Providers\Anthropic([
    'api_key' => env('ANTHROPIC_API_KEY'),
    'timeout' => 30, // 30-second timeout
]);

$agent = agent('time-limited')
    ->provider($provider)
    ->build();

try {
    $response = $agent->prompt('Complex task that might take a while');
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'timeout')) {
        echo "Request timed out after 30 seconds";
    }
}
```

For more granular control, combine timeouts with retry logic:

```php
function promptWithTimeout($agent, $message, $timeoutSeconds = 30)
{
    $start = time();

    try {
        return $agent->prompt($message);
    } catch (RuntimeException $e) {
        if ((time() - $start) >= $timeoutSeconds) {
            throw new RuntimeException("Request exceeded {$timeoutSeconds}s timeout");
        }
        throw $e;
    }
}
```

## Rate Limiting with Built-In Middleware

Pagent includes `RateLimitMiddleware` to prevent exceeding API quotas:

```php
use Pagent\Middleware\RateLimitMiddleware;

$rateLimit = new RateLimitMiddleware(
    maxRequests: 100,
    windowSeconds: 3600 // 100 requests per hour
);

$agent = agent('rate-limited')
    ->provider(anthropic())
    ->middleware($rateLimit)
    ->build();

// Make requests
for ($i = 0; $i < 150; $i++) {
    try {
        $response = $agent->prompt("Request #{$i}");
        echo "Remaining: {$rateLimit->getRemainingRequests()}\n";

    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'Rate limit exceeded')) {
            echo $e->getMessage() . "\n";
            break;
        }
        throw $e;
    }
}
```

The middleware tracks requests in a sliding window and throws an exception when the limit is exceeded, showing how many seconds to wait.

## Combining Reliability Patterns

Production applications typically need multiple reliability layers. Here's how to combine them:

```php
<?php

use Pagent\Middleware\RateLimitMiddleware;

// Circuit breaker for the provider
$breaker = new CircuitBreaker(
    failureThreshold: 5,
    cooldownSeconds: 60
);

// Rate limiting
$rateLimit = new RateLimitMiddleware(
    maxRequests: 100,
    windowSeconds: 3600
);

// Agent with guards and fallback
$agent = agent('production')
    ->provider(anthropic())
    ->middleware($rateLimit)
    ->guard('profanity')
    ->guard('pii')
    ->fallback(function ($e) {
        error_log("Guard violation: {$e->guardName}");
        return "I cannot provide that response.";
    })
    ->build();

// Helper with retry and circuit breaker
function robustPrompt($agent, $breaker, $message, $maxRetries = 3)
{
    return $breaker->call(function () use ($agent, $message, $maxRetries) {
        return promptWithRetry($agent, $message, [], $maxRetries);
    });
}

// Usage
try {
    $response = robustPrompt($agent, $breaker, 'What is PHP?');
    echo $response->content;

} catch (RuntimeException $e) {
    error_log("Production error: " . $e->getMessage());
    echo "Service temporarily unavailable. Please try again later.";
}
```

This layered approach provides:
1. **Rate limiting** - Prevents quota violations
2. **Content guards** - Blocks unsafe output with fallbacks
3. **Retry logic** - Handles transient failures
4. **Circuit breaker** - Prevents cascading failures
5. **Exception handling** - Graceful degradation

## Monitoring Reliability Metrics

Track reliability metrics to understand your system's behavior:

```php
<?php

final class ReliabilityMetrics
{
    private array $attempts = [];
    private array $failures = [];
    private array $guardViolations = [];

    public function recordAttempt(string $operation): void
    {
        $this->attempts[$operation] = ($this->attempts[$operation] ?? 0) + 1;
    }

    public function recordFailure(string $operation, string $reason): void
    {
        $this->failures[$operation][$reason] =
            ($this->failures[$operation][$reason] ?? 0) + 1;
    }

    public function recordGuardViolation(string $guardName): void
    {
        $this->guardViolations[$guardName] =
            ($this->guardViolations[$guardName] ?? 0) + 1;
    }

    public function getStats(): array
    {
        $totalAttempts = array_sum($this->attempts);
        $totalFailures = array_sum(array_map('array_sum', $this->failures));

        return [
            'total_attempts' => $totalAttempts,
            'total_failures' => $totalFailures,
            'success_rate' => $totalAttempts > 0
                ? round((($totalAttempts - $totalFailures) / $totalAttempts) * 100, 2)
                : 0,
            'attempts_by_operation' => $this->attempts,
            'failures_by_operation' => $this->failures,
            'guard_violations' => $this->guardViolations,
        ];
    }
}

// Usage
$metrics = new ReliabilityMetrics();

function monitoredPrompt($agent, $metrics, $message)
{
    $metrics->recordAttempt('prompt');

    try {
        $response = $agent->prompt($message);

        if (isset($response->guard_triggered)) {
            $metrics->recordGuardViolation($response->guard_triggered);
        }

        return $response;

    } catch (RuntimeException $e) {
        $metrics->recordFailure('prompt', $e->getMessage());
        throw $e;
    }
}

// Make several requests
$agent = agent('monitored')->provider(anthropic())->build();

for ($i = 0; $i < 100; $i++) {
    try {
        monitoredPrompt($agent, $metrics, "Request #{$i}");
    } catch (RuntimeException $e) {
        // Handle error
    }
}

// View reliability stats
print_r($metrics->getStats());
```

## Health Checks for Multi-Provider Setups

When using multiple providers, implement health checks to route to healthy providers:

```php
<?php

final class ProviderHealthCheck
{
    private array $health = [];

    public function check(string $name, \Pagent\Contracts\Provider $provider): bool
    {
        try {
            $agent = agent('health-check')->provider($provider)->build();
            $response = $agent->prompt('ping');

            $this->health[$name] = [
                'healthy' => true,
                'last_check' => time(),
            ];

            return true;

        } catch (\Throwable $e) {
            $this->health[$name] = [
                'healthy' => false,
                'last_check' => time(),
                'error' => $e->getMessage(),
            ];

            return false;
        }
    }

    public function getHealthy(): array
    {
        return array_keys(array_filter(
            $this->health,
            fn($h) => $h['healthy'] ?? false
        ));
    }
}

// Usage
$healthCheck = new ProviderHealthCheck();
$providers = [
    'anthropic' => anthropic(),
    'openai' => openai(),
];

// Check health
foreach ($providers as $name => $provider) {
    $healthCheck->check($name, $provider);
}

// Use a healthy provider
$healthyProviders = $healthCheck->getHealthy();
if (empty($healthyProviders)) {
    throw new RuntimeException('No healthy providers available');
}

$providerName = $healthyProviders[0];
$agent = agent('resilient')->provider($providers[$providerName])->build();
```

## Summary

You've learned how to build reliable LLM applications with Pagent:

- **Fallbacks** provide safe defaults when guards detect violations
- **Exception handling** differentiates between content and infrastructure failures
- **Retry logic** handles transient failures with exponential backoff
- **Circuit breakers** prevent cascading failures during outages
- **Timeouts** limit how long operations can run
- **Rate limiting** prevents quota violations with built-in middleware
- **Layered reliability** combines multiple patterns for production robustness
- **Metrics tracking** provides visibility into system behavior

The key principle: Pagent provides the hooks and patterns, you implement the exact reliability strategy your application needs. This keeps the library lightweight while giving you complete control over failure handling.

## Next Steps

In Chapter 16, we'll explore multi-agent orchestration, learning how to coordinate multiple specialized agents to tackle complex tasks through handoffs, delegation, and pipelines.

## Additional Resources

- [Circuit Breaker Pattern](https://martinfowler.com/bliki/CircuitBreaker.html)
- [Exponential Backoff And Jitter](https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/)
- [Pagent Middleware Implementation](https://github.com/hhelge/pagent/tree/main/src/Middleware)
