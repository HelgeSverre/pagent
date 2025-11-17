# Chapter 25: Custom Middleware

In Chapter 24, we explored workflow patterns and orchestration. But what if you need to intercept and modify every agent interaction? What if you want to add logging, metrics collection, rate limiting, or caching to all your LLM calls without cluttering your business logic? What if you need an audit trail of every prompt and response that flows through your system?

This is where middleware comes in. Pagent's middleware system provides a clean, composable way to add cross-cutting concerns to your agents. Like Laravel's HTTP middleware or Express.js middleware, Pagent middleware lets you hook into the request/response cycle - transforming inputs before they reach the LLM and modifying outputs after they return.

In this chapter, we'll explore how to build custom middleware, chain multiple middleware together, and implement practical patterns like rate limiting, response caching, and audit logging.

## Understanding the Middleware Architecture

Pagent's middleware system is based on a simple but powerful interface. Every middleware implements two methods: `before()` and `after()`.

The `Middleware` interface looks like this:

```php
namespace Pagent\Contracts;

interface Middleware
{
    public function before(string $message, array $options): array;
    public function after(object $response): object;
}
```

When you call `prompt()` on an agent, here's what happens:

1. All `before()` middleware run in registration order, potentially modifying the options
2. The provider is called with the final options
3. All `after()` middleware run in registration order, potentially transforming the response
4. The final response is returned to your code

This creates a clean pipeline where each middleware can inspect, modify, or even reject requests and responses. The middleware don't know about each other - they just do their job and pass control to the next layer.

## Your First Custom Middleware

Let's start with something simple: a middleware that tracks how many times each agent has been called.

```php
use Pagent\Contracts\Middleware;

class CallCounterMiddleware implements Middleware
{
    private array $counts = [];

    public function before(string $message, array $options): array
    {
        // Extract agent name from options or use a default
        $agentName = $options['agent_name'] ?? 'unknown';

        if (!isset($this->counts[$agentName])) {
            $this->counts[$agentName] = 0;
        }

        $this->counts[$agentName]++;

        // Pass through options unchanged
        return $options;
    }

    public function after(object $response): object
    {
        // Pass through response unchanged
        return $response;
    }

    public function getCount(string $agentName): int
    {
        return $this->counts[$agentName] ?? 0;
    }

    public function getAllCounts(): array
    {
        return $this->counts;
    }
}
```

To use this middleware, simply add it to your agent:

```php
$counter = new CallCounterMiddleware();

$agent = agent('my-agent')
    ->provider('anthropic')
    ->middleware($counter);

$agent->prompt('Hello');
$agent->prompt('How are you?');

echo $counter->getCount('my-agent'); // 2
```

This middleware demonstrates the core concept: you can maintain state across calls, inspect the inputs and outputs, and expose that data to your application.

## Modifying Options with Middleware

The `before()` method receives the options array and must return it. This gives you the power to modify or inject options before they reach the provider.

Here's a middleware that adds a custom system prompt prefix to every request:

```php
class SystemPromptPrefixMiddleware implements Middleware
{
    public function __construct(
        private readonly string $prefix
    ) {}

    public function before(string $message, array $options): array
    {
        // Get existing system prompt or use empty string
        $existingSystem = $options['system'] ?? '';

        // Prepend our prefix
        $options['system'] = $this->prefix . "\n\n" . $existingSystem;

        return $options;
    }

    public function after(object $response): object
    {
        return $response;
    }
}
```

Use it to add consistent instructions to all agent calls:

```php
$agent = agent('customer-support')
    ->provider('anthropic')
    ->middleware(new SystemPromptPrefixMiddleware(
        'You are a helpful customer support agent. Always be polite and professional.'
    ))
    ->prompt('How do I reset my password?');
```

Every prompt will now automatically include that system prompt prefix, without you having to remember to add it manually.

## Transforming Responses with Middleware

The `after()` method receives the response object and can modify it before returning it. This is useful for adding metadata, filtering content, or logging.

Here's a middleware that adds a timestamp to every response:

```php
class TimestampMiddleware implements Middleware
{
    public function before(string $message, array $options): array
    {
        // Store the start time in options
        $options['_middleware_start_time'] = microtime(true);
        return $options;
    }

    public function after(object $response): object
    {
        // Add timestamps to the response
        $response->timestamp = time();
        $response->received_at = date('Y-m-d H:i:s');

        return $response;
    }
}
```

Now every response will have timing information attached:

```php
$agent = agent('timestamped')
    ->provider('anthropic')
    ->middleware(new TimestampMiddleware());

$response = $agent->prompt('What time is it?');

echo $response->received_at; // "2025-10-28 14:30:22"
```

## Built-in Middleware Examples

Pagent ships with three useful middleware implementations that demonstrate common patterns. Let's examine each one.

### LoggingMiddleware

The `LoggingMiddleware` logs every prompt and response using PSR-3 compatible loggers:

```php
use Pagent\Middleware\LoggingMiddleware;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('agent');
$logger->pushHandler(new StreamHandler('agent.log', Logger::INFO));

$agent = agent('logged-agent')
    ->provider('anthropic')
    ->middleware(new LoggingMiddleware($logger));

$agent->prompt('Analyze this data');

// agent.log now contains:
// [2025-10-28 14:30:22] INFO: Agent prompt initiated {"message":"Analyze this data",...}
// [2025-10-28 14:30:24] INFO: Agent response received {"tokens":250,...}
```

This is invaluable for debugging production issues and understanding how your agents are being used.

### MetricsMiddleware

The `MetricsMiddleware` tracks performance metrics like duration and token usage:

```php
use Pagent\Middleware\MetricsMiddleware;

$metrics = new MetricsMiddleware();

$agent = agent('tracked-agent')
    ->provider('anthropic')
    ->middleware($metrics);

$agent->prompt('Generate a report');
$agent->prompt('Summarize the results');

echo $metrics->getAverageDuration(); // Average milliseconds per call
echo $metrics->getTotalTokens();      // Total tokens used
print_r($metrics->getMetrics());      // Full metrics array
```

This gives you insight into performance patterns and costs over time.

### RateLimitMiddleware

The `RateLimitMiddleware` prevents your agent from exceeding rate limits:

```php
use Pagent\Middleware\RateLimitMiddleware;

$rateLimit = new RateLimitMiddleware(
    maxRequests: 100,
    windowSeconds: 3600
);

$agent = agent('rate-limited')
    ->provider('anthropic')
    ->middleware($rateLimit);

// After 100 requests in an hour:
try {
    $agent->prompt('One more request');
} catch (RuntimeException $e) {
    echo $e->getMessage(); // "Rate limit exceeded. Try again in 1200 seconds..."
}

echo $rateLimit->getRemainingRequests(); // 0
```

This protects you from accidentally hitting API limits or spending too much on tokens.

## Building a Response Cache Middleware

Let's build something more sophisticated: a middleware that caches responses to avoid redundant LLM calls.

```php
use Pagent\Contracts\Middleware;

class ResponseCacheMiddleware implements Middleware
{
    private array $cache = [];

    public function __construct(
        private readonly int $ttlSeconds = 3600
    ) {}

    public function before(string $message, array $options): array
    {
        $cacheKey = md5(json_encode([$message, $options['model'] ?? '']));
        $options['_cache_key'] = $cacheKey;

        if (isset($this->cache[$cacheKey])) {
            $entry = $this->cache[$cacheKey];
            if (time() < $entry['expires_at']) {
                $options['_cached_response'] = $entry['response'];
            }
        }

        return $options;
    }

    public function after(object $response): object
    {
        if (isset($response->_cache_key)) {
            $this->cache[$response->_cache_key] = [
                'response' => clone $response,
                'expires_at' => time() + $this->ttlSeconds,
            ];
        }

        $response->cached = isset($response->_cached_response);
        return $response;
    }
}
```

Use it to avoid redundant API calls:

```php
$cache = new ResponseCacheMiddleware(ttlSeconds: 3600);

$agent = agent('cached-agent')
    ->provider('anthropic')
    ->middleware($cache);

$response1 = $agent->prompt('What is 2+2?');
$response2 = $agent->prompt('What is 2+2?');

echo $response2->cached; // true
```

## Creating an Audit Trail Middleware

Security and compliance often require detailed audit logs. Here's a middleware that creates a complete audit trail:

```php
use Pagent\Contracts\Middleware;

class AuditTrailMiddleware implements Middleware
{
    private array $trail = [];

    public function __construct(
        private readonly ?string $userId = null
    ) {}

    public function before(string $message, array $options): array
    {
        $requestId = uniqid('req_', true);

        $this->trail[$requestId] = [
            'id' => $requestId,
            'user_id' => $this->userId,
            'timestamp' => microtime(true),
            'prompt' => $message,
            'model' => $options['model'] ?? null,
        ];

        $options['_audit_request_id'] = $requestId;
        return $options;
    }

    public function after(object $response): object
    {
        if (isset($response->_audit_request_id)) {
            $requestId = $response->_audit_request_id;

            if (isset($this->trail[$requestId])) {
                $this->trail[$requestId]['completed_at'] = microtime(true);
                $this->trail[$requestId]['duration_ms'] = round(
                    ($this->trail[$requestId]['completed_at'] - $this->trail[$requestId]['timestamp']) * 1000,
                    2
                );
                $this->trail[$requestId]['tokens'] = $response->tokens ?? null;
            }
        }

        return $response;
    }

    public function getTrail(): array
    {
        return array_values($this->trail);
    }
}
```

Use it to track every interaction:

```php
$audit = new AuditTrailMiddleware(userId: 'user_123');

$agent = agent('audited-agent')
    ->provider('anthropic')
    ->middleware($audit);

$agent->prompt('Show me sensitive data');
$agent->prompt('Delete this record');

print_r($audit->getTrail());
// Complete record of all interactions with timestamps, durations, and token usage
```

## Chaining Multiple Middleware

Middleware becomes truly powerful when you chain multiple pieces together. Each middleware focuses on one concern, and together they create a robust pipeline.

```php
$logger = new Logger('agent');
$logger->pushHandler(new StreamHandler('agent.log'));

$agent = agent('production-agent')
    ->provider('anthropic')
    ->middleware(new RateLimitMiddleware(maxRequests: 1000, windowSeconds: 3600))
    ->middleware(new LoggingMiddleware($logger))
    ->middleware(new MetricsMiddleware())
    ->middleware(new AuditTrailMiddleware(userId: $currentUser->id))
    ->middleware(new ResponseCacheMiddleware(ttlSeconds: 1800));

// Now every request:
// 1. Checks rate limits
// 2. Logs the prompt
// 3. Records metrics
// 4. Creates audit entry
// 5. Checks cache (or caches new response)
```

The middleware run in the order you add them. For `before()` methods, earlier middleware run first. For `after()` methods, earlier middleware also run first (same order, not reversed).

This means you can control the order of operations by carefully ordering your middleware registration.

## Adding Middleware by Name

Pagent provides a convenient shorthand for built-in middleware. Instead of instantiating the class, you can use a string:

```php
$agent = agent('my-agent')
    ->provider('anthropic')
    ->middleware('logging')    // Creates LoggingMiddleware
    ->middleware('metrics')     // Creates MetricsMiddleware
    ->middleware('rateLimit');  // Creates RateLimitMiddleware
```

This uses a simple naming convention: the string is capitalized and "Middleware" is appended, then the class is loaded from `Pagent\Middleware\` namespace.

For custom middleware, you'll need to pass an instance:

```php
$agent->middleware(new MyCustomMiddleware());
```

## Managing Middleware at Runtime

You can inspect and clear middleware after creation:

```php
$agent = agent('my-agent')
    ->provider('anthropic')
    ->middleware('logging')
    ->middleware('metrics');

// Get all middleware
$middlewares = $agent->getMiddleware();
echo count($middlewares); // 2

// Clear all middleware
$agent->clearMiddleware();

$middlewares = $agent->getMiddleware();
echo count($middlewares); // 0
```

This is useful for testing or for dynamically reconfiguring agents based on runtime conditions.

## Middleware Best Practices

**Keep middleware focused.** Each middleware should do one thing well. Don't create a "SuperMiddleware" - create separate pieces and chain them together.

**Avoid side effects in before().** The `before()` method should focus on modifying options. Save expensive operations like database writes for `after()`.

**Don't assume response structure.** Always use `??` null coalescing and `isset()` checks when accessing response properties, as they may vary between providers.

**Consider performance.** Middleware runs on every request. Keep methods fast - use background queues for expensive operations.

**Test in isolation.** Write unit tests that call `before()` and `after()` directly with mock data.

## What Middleware Can't Do

Pagent's middleware system has intentional limitations for simplicity:

**No short-circuiting.** You can't stop the provider call from `before()`. The provider is always invoked.

**No middleware priority.** Middleware run in registration order only.

**No conditional execution.** All middleware always run for every request.

**No async middleware.** All methods are synchronous. Use queues for async operations.

These limitations keep the system predictable and easy to reason about.

## Wrapping Up

Middleware provides a clean, composable way to add cross-cutting concerns to your agents. By implementing the simple `Middleware` interface, you can build reusable components for logging, metrics, rate limiting, caching, auditing, and more.

The key insight is that middleware operates on the request/response cycle - transforming inputs before they reach the LLM and transforming outputs before they reach your code. This separation of concerns keeps your business logic clean while adding powerful capabilities through composition.

In the next chapter, we'll explore performance optimization techniques - including how to use middleware to implement efficient caching strategies, reduce token usage, and minimize API latency. You'll learn how to make your agents faster and more cost-effective in production.
