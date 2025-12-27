# Middleware

Middleware provides hooks to intercept and modify requests before they're sent to the LLM and responses after they're received. Use middleware for logging, metrics, rate limiting, or custom request/response transformations.

## How Middleware Works

Middleware wraps the LLM call with `before()` and `after()` hooks:

```php
interface Middleware
{
    public function before(string $message, array $options): array;
    public function after(object $response): object;
}
```

- `before()` receives the message and options, returns (possibly modified) options
- `after()` receives the response object, returns (possibly modified) response

```
User Message
     ↓
[Middleware 1 before]
     ↓
[Middleware 2 before]
     ↓
   LLM Call
     ↓
[Middleware 2 after]
     ↓
[Middleware 1 after]
     ↓
Response to User
```

## Built-in Middleware

### LoggingMiddleware

Logs all prompts and responses using PSR-3 compatible loggers:

```php
use Pagent\Middleware\LoggingMiddleware;
use Monolog\Logger;

$logger = new Logger('agent');

agent('assistant')
    ->provider('anthropic')
    ->middleware(new LoggingMiddleware($logger))
    ->prompt('Hello');
```

**Logged information:**

Before request:
- Message content
- Model name
- Temperature setting

After response:
- Provider name
- Model used
- Token count
- Response length

### MetricsMiddleware

Collects performance metrics for analysis:

```php
use Pagent\Middleware\MetricsMiddleware;

$metrics = new MetricsMiddleware();

agent('assistant')
    ->provider('anthropic')
    ->middleware($metrics)
    ->prompt('Hello')
    ->prompt('How are you?');

// Get collected metrics
$allMetrics = $metrics->getMetrics();
$avgDuration = $metrics->getAverageDuration();  // in milliseconds
$totalTokens = $metrics->getTotalTokens();
```

**Metrics collected per request:**
- Timestamp
- Duration (ms)
- Token count
- Provider name
- Model name

### RateLimitMiddleware

Prevents exceeding API rate limits:

```php
use Pagent\Middleware\RateLimitMiddleware;

// 100 requests per hour (3600 seconds)
$rateLimiter = new RateLimitMiddleware(
    maxRequests: 100,
    windowSeconds: 3600
);

agent('assistant')
    ->provider('anthropic')
    ->middleware($rateLimiter)
    ->prompt('Hello');

// Check remaining capacity
$remaining = $rateLimiter->getRemainingRequests();
```

When the limit is exceeded, a `RuntimeException` is thrown with the wait time.

## Using Middleware

### By String Name

```php
agent('assistant')
    ->provider('anthropic')
    ->middleware('logging')    // LoggingMiddleware (with NullLogger)
    ->middleware('metrics')    // MetricsMiddleware
    ->middleware('rateLimit')  // RateLimitMiddleware
    ->prompt('Hello');
```

### By Instance

```php
use Pagent\Middleware\LoggingMiddleware;
use Pagent\Middleware\MetricsMiddleware;

agent('assistant')
    ->provider('anthropic')
    ->middleware(new LoggingMiddleware($myLogger))
    ->middleware(new MetricsMiddleware())
    ->prompt('Hello');
```

### Multiple Middleware

Middleware executes in the order registered. `before()` hooks run first-to-last, `after()` hooks run last-to-first:

```php
agent('assistant')
    ->middleware($middleware1)  // before: 1st, after: 3rd
    ->middleware($middleware2)  // before: 2nd, after: 2nd
    ->middleware($middleware3)  // before: 3rd, after: 1st
    ->prompt('Hello');
```

## Creating Custom Middleware

```php
use Pagent\Contracts\Middleware;

final class RequestIdMiddleware implements Middleware
{
    private ?string $requestId = null;

    public function before(string $message, array $options): array
    {
        $this->requestId = uniqid('req_', true);

        // Add request ID to options for tracking
        $options['metadata']['request_id'] = $this->requestId;

        return $options;
    }

    public function after(object $response): object
    {
        // Add request ID to response for correlation
        $response->request_id = $this->requestId;

        return $response;
    }
}

agent('assistant')
    ->middleware(new RequestIdMiddleware())
    ->prompt('Hello');
```

### Modifying Options

```php
final class DefaultsMiddleware implements Middleware
{
    public function before(string $message, array $options): array
    {
        // Set defaults if not provided
        $options['temperature'] ??= 0.7;
        $options['max_tokens'] ??= 1024;

        return $options;
    }

    public function after(object $response): object
    {
        return $response;
    }
}
```

### Transforming Responses

```php
final class SanitizeMiddleware implements Middleware
{
    public function before(string $message, array $options): array
    {
        return $options;
    }

    public function after(object $response): object
    {
        // Sanitize response content
        $response->content = strip_tags($response->content);

        return $response;
    }
}
```

## Middleware vs Guards

| Feature | Middleware | Guards |
|---------|------------|--------|
| Purpose | Transform request/response | Validate response safety |
| Timing | Before and after LLM call | After LLM call only |
| Can modify | Yes (options and response) | No (only pass/fail) |
| On failure | Throw exception or continue | Block response or use fallback |
| Use case | Logging, metrics, defaults | Security, content filtering |

**Use middleware when you need to:**
- Log or track requests
- Collect metrics
- Add default options
- Transform responses
- Rate limit requests

**Use guards when you need to:**
- Validate response safety
- Block sensitive content
- Detect prompt injection
- Enforce content policies

## Clearing Middleware

```php
$agent = agent('assistant')
    ->middleware('logging')
    ->middleware('metrics');

// Clear all middleware
$agent->clearMiddleware();

// Get current middleware
$middlewareList = $agent->getMiddleware();
```

## Best Practices

1. **Order matters**: Place logging first to capture all requests, rate limiting early to fail fast
2. **Keep it simple**: Each middleware should do one thing well
3. **Don't throw in after()**: If `after()` throws, the response is lost
4. **Use PSR-3 loggers**: LoggingMiddleware works with any PSR-3 compatible logger
5. **Share instances**: Use the same MetricsMiddleware instance across agents to aggregate metrics

## See Also

- [Guards](guards.md) - For response validation
- [Events](events.md) - For event-based hooks
- [Observability](observability.md) - For OpenTelemetry integration
