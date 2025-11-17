# Chapter 25: Custom Middleware

## What You'll Learn

In this chapter, you'll master the art of creating custom middleware for Pagent agents. You'll implement middleware chains that intercept and transform requests and responses, build sophisticated rate limiting strategies that protect your API quotas, add intelligent caching layers that reduce costs and improve response times, and create comprehensive audit logging for compliance and debugging. By the end, you'll have the skills to extend Pagent's functionality through powerful middleware components that solve real-world production challenges.

## Prerequisites

- Completed Chapters 1-15 of the Pagent tutorial series
- Understanding of PHP interfaces and dependency injection
- Familiarity with the Chain of Responsibility pattern
- Basic knowledge of caching strategies
- Experience with Pagent's agent configuration

## Time Estimate

45 minutes to complete all exercises and build four production-ready middleware components.

## Final Result

You'll build a complete middleware suite including a sliding window rate limiter, an intelligent response cache, a comprehensive audit logger, and a custom content transformer - all working together in a middleware chain that makes your AI agents production-ready.

## Understanding Middleware in Pagent

Middleware in Pagent follows a simple but powerful pattern. Each middleware component implements the `Middleware` interface with two key methods:

```php
interface Middleware
{
    public function before(string $message, array $options): array;
    public function after(object $response): object;
}
```

The `before` method runs before the provider is called, allowing you to modify the request or options. The `after` method processes the response, enabling transformations, logging, or caching. This bidirectional flow gives you complete control over the agent's behavior.

## Building Your First Custom Middleware

Let's start with a practical example - a middleware that adds request IDs for tracing:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Contracts\Middleware;

final class RequestIdMiddleware implements Middleware
{
    private string $currentRequestId;

    public function before(string $message, array $options): array
    {
        $this->currentRequestId = $this->generateRequestId();

        // Add request ID to options for provider tracking
        $options['metadata'] = array_merge(
            $options['metadata'] ?? [],
            ['request_id' => $this->currentRequestId]
        );

        return $options;
    }

    public function after(object $response): object
    {
        // Attach request ID to response for correlation
        $response->requestId = $this->currentRequestId;

        return $response;
    }

    private function generateRequestId(): string
    {
        return sprintf(
            'req_%s_%s',
            date('Ymd_His'),
            bin2hex(random_bytes(4))
        );
    }
}
```

Now attach this middleware to an agent:

```php
use App\Middleware\RequestIdMiddleware;

$agent = agent('assistant')
    ->middleware(new RequestIdMiddleware())
    ->prompt('Generate a summary');

echo $agent->last()->requestId; // Output: req_20250117_143052_a7c3f821
```

The middleware seamlessly adds request tracking without modifying your application code. Each request gets a unique identifier that flows through the entire processing chain.

## Implementing Advanced Rate Limiting

Pagent includes a basic rate limiter, but let's build a more sophisticated token bucket algorithm that provides smoother rate limiting:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Contracts\Middleware;
use RuntimeException;

final class TokenBucketRateLimiter implements Middleware
{
    private float $tokens;
    private float $lastRefill;
    private array $buckets = [];

    public function __construct(
        private readonly int $capacity = 100,
        private readonly float $refillRate = 1.0,
        private readonly bool $perModel = true,
    ) {
        $this->tokens = (float) $capacity;
        $this->lastRefill = microtime(true);
    }

    public function before(string $message, array $options): array
    {
        $bucketKey = $this->perModel
            ? ($options['model'] ?? 'default')
            : 'global';

        $this->refillBucket($bucketKey);

        if (! $this->consumeToken($bucketKey)) {
            $waitTime = $this->calculateWaitTime($bucketKey);

            throw new RuntimeException(
                "Rate limit exceeded. Tokens available in {$waitTime}s. "
                ."Model: {$bucketKey}, Capacity: {$this->capacity}"
            );
        }

        return $options;
    }

    public function after(object $response): object
    {
        // Optionally refund tokens for cached responses
        if ($response->cached ?? false) {
            $bucketKey = $this->perModel
                ? ($response->model ?? 'default')
                : 'global';

            $this->refundToken($bucketKey, 0.5);
        }

        return $response;
    }

    private function refillBucket(string $key): void
    {
        if (! isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'tokens' => (float) $this->capacity,
                'lastRefill' => microtime(true),
            ];
        }

        $now = microtime(true);
        $elapsed = $now - $this->buckets[$key]['lastRefill'];
        $tokensToAdd = $elapsed * $this->refillRate;

        $this->buckets[$key]['tokens'] = min(
            $this->capacity,
            $this->buckets[$key]['tokens'] + $tokensToAdd
        );

        $this->buckets[$key]['lastRefill'] = $now;
    }

    private function consumeToken(string $key): bool
    {
        if ($this->buckets[$key]['tokens'] >= 1.0) {
            $this->buckets[$key]['tokens'] -= 1.0;
            return true;
        }

        return false;
    }

    private function refundToken(string $key, float $amount): void
    {
        $this->buckets[$key]['tokens'] = min(
            $this->capacity,
            $this->buckets[$key]['tokens'] + $amount
        );
    }

    private function calculateWaitTime(string $key): float
    {
        $tokensNeeded = 1.0 - $this->buckets[$key]['tokens'];

        return round($tokensNeeded / $this->refillRate, 2);
    }

    public function getTokensRemaining(string $model = 'default'): float
    {
        $key = $this->perModel ? $model : 'global';
        $this->refillBucket($key);

        return $this->buckets[$key]['tokens'] ?? (float) $this->capacity;
    }
}
```

This token bucket implementation provides smooth rate limiting with automatic refill. Use it to protect expensive API endpoints:

```php
$rateLimiter = new TokenBucketRateLimiter(
    capacity: 10,      // 10 tokens max
    refillRate: 0.5,   // Refill 0.5 tokens per second
    perModel: true     // Separate buckets per model
);

$agent = agent('assistant')
    ->middleware($rateLimiter)
    ->model('gpt-4');

// Check available tokens before making request
$available = $rateLimiter->getTokensRemaining('gpt-4');
if ($available < 1) {
    echo "Waiting for rate limit to reset...";
}
```

## Creating an Intelligent Response Cache

Caching AI responses reduces costs and improves performance. Let's build a content-aware cache middleware:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Contracts\Middleware;
use Psr\Cache\CacheItemPoolInterface;

final class ResponseCacheMiddleware implements Middleware
{
    private ?string $currentCacheKey = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl = 3600,
        private readonly bool $cacheByModel = true,
    ) {}

    public function before(string $message, array $options): array
    {
        $this->currentCacheKey = $this->generateCacheKey($message, $options);

        $item = $this->cache->getItem($this->currentCacheKey);

        if ($item->isHit()) {
            // Create cached response object
            $cachedResponse = $item->get();
            $cachedResponse->cached = true;
            $cachedResponse->cacheHit = true;

            // Skip provider call by setting cached response
            $options['_cached_response'] = $cachedResponse;
        }

        return $options;
    }

    public function after(object $response): object
    {
        // Don't cache if already from cache
        if ($response->cached ?? false) {
            return $response;
        }

        // Don't cache errors or empty responses
        if (empty($response->content) || isset($response->error)) {
            return $response;
        }

        // Cache the response
        $item = $this->cache->getItem($this->currentCacheKey);

        $cacheData = clone $response;
        $cacheData->cachedAt = time();

        $item->set($cacheData);
        $item->expiresAfter($this->ttl);

        $this->cache->save($item);

        $response->cached = false;
        $response->cacheHit = false;

        return $response;
    }

    private function generateCacheKey(string $message, array $options): string
    {
        $keyParts = [
            'pagent',
            'response',
            md5($message),
        ];

        if ($this->cacheByModel && isset($options['model'])) {
            $keyParts[] = $options['model'];
        }

        // Include temperature in key for different creative outputs
        if (isset($options['temperature'])) {
            $keyParts[] = 'temp' . $options['temperature'];
        }

        // Include system prompt if present
        if (isset($options['system'])) {
            $keyParts[] = md5($options['system']);
        }

        return implode('_', $keyParts);
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    public function getCacheStats(): array
    {
        // Implementation depends on cache backend
        return [
            'keys' => $this->cache->hasItem('_stats')
                ? $this->cache->getItem('_stats')->get()
                : [],
        ];
    }
}
```

Integrate the cache with a PSR-6 compatible cache pool:

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$cache = new FilesystemAdapter('pagent', 3600, '/tmp/cache');
$cacheMiddleware = new ResponseCacheMiddleware($cache, ttl: 7200);

$agent = agent('assistant')
    ->middleware($cacheMiddleware)
    ->temperature(0); // Deterministic for better caching

// First call hits the API
$response1 = $agent->prompt('Explain quantum computing');

// Second identical call uses cache
$response2 = $agent->prompt('Explain quantum computing');

var_dump($response2->cacheHit); // true
```

## Building a Comprehensive Audit Logger

For compliance and debugging, create an audit trail of all AI interactions:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Contracts\Middleware;
use Psr\Log\LoggerInterface;

final class AuditLoggerMiddleware implements Middleware
{
    private array $currentContext = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $userId = 'system',
        private readonly bool $logContent = true,
        private readonly int $maxContentLength = 1000,
    ) {}

    public function before(string $message, array $options): array
    {
        $this->currentContext = [
            'timestamp' => microtime(true),
            'user_id' => $this->userId,
            'session_id' => $options['session_id'] ?? uniqid('session_'),
            'model' => $options['model'] ?? 'unknown',
            'provider' => $options['provider'] ?? 'unknown',
        ];

        $logData = array_merge($this->currentContext, [
            'event' => 'ai_request_started',
            'message_length' => strlen($message),
        ]);

        if ($this->logContent) {
            $logData['message'] = $this->truncateContent($message);
        }

        // Log with structured data
        $this->logger->info('AI request initiated', $logData);

        // Add audit metadata to options
        $options['_audit_context'] = $this->currentContext;

        return $options;
    }

    public function after(object $response): object
    {
        $duration = microtime(true) - $this->currentContext['timestamp'];

        $logData = array_merge($this->currentContext, [
            'event' => 'ai_request_completed',
            'duration_seconds' => round($duration, 3),
            'tokens_used' => $response->tokens ?? 0,
            'response_length' => strlen($response->content ?? ''),
            'cached' => $response->cached ?? false,
            'success' => ! isset($response->error),
        ]);

        if ($this->logContent && isset($response->content)) {
            $logData['response'] = $this->truncateContent($response->content);
        }

        if (isset($response->error)) {
            $logData['error'] = $response->error;
            $this->logger->error('AI request failed', $logData);
        } else {
            $this->logger->info('AI request completed', $logData);
        }

        // Calculate cost if possible
        if (isset($response->tokens) && isset($response->model)) {
            $logData['estimated_cost'] = $this->estimateCost(
                $response->tokens,
                $response->model
            );
        }

        // Store audit trail
        $this->storeAuditRecord($logData);

        return $response;
    }

    private function truncateContent(string $content): string
    {
        if (strlen($content) <= $this->maxContentLength) {
            return $content;
        }

        return substr($content, 0, $this->maxContentLength) . '... [truncated]';
    }

    private function estimateCost(int $tokens, string $model): float
    {
        // Simplified cost calculation - adjust based on actual pricing
        $costPer1kTokens = match (true) {
            str_contains($model, 'gpt-4') => 0.03,
            str_contains($model, 'gpt-3.5') => 0.002,
            str_contains($model, 'claude') => 0.025,
            default => 0.001,
        };

        return round(($tokens / 1000) * $costPer1kTokens, 4);
    }

    private function storeAuditRecord(array $data): void
    {
        // Store in database, file, or external audit system
        // This is a simplified file-based implementation
        $auditFile = sprintf(
            '/var/log/pagent/audit_%s.jsonl',
            date('Y-m-d')
        );

        $record = json_encode($data) . PHP_EOL;

        // Ensure directory exists
        $dir = dirname($auditFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($auditFile, $record, FILE_APPEND | LOCK_EX);
    }
}
```

## Composing Middleware Chains

The real power comes from combining multiple middleware components into processing chains:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Agent;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;

final class MiddlewareFactory
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public function createProductionStack(Agent $agent): Agent
    {
        return $agent
            // Add request tracking first
            ->middleware(new RequestIdMiddleware())

            // Apply rate limiting
            ->middleware(new TokenBucketRateLimiter(
                capacity: 100,
                refillRate: 2.0,
                perModel: true
            ))

            // Check cache before expensive calls
            ->middleware(new ResponseCacheMiddleware(
                cache: $this->cache,
                ttl: 3600,
                cacheByModel: true
            ))

            // Audit everything
            ->middleware(new AuditLoggerMiddleware(
                logger: $this->logger,
                userId: $this->getCurrentUserId(),
                logContent: true
            ));
    }

    private function getCurrentUserId(): string
    {
        // Get from session, auth, or context
        return $_SESSION['user_id'] ?? 'anonymous';
    }
}
```

Use the factory to create consistently configured agents:

```php
$factory = new MiddlewareFactory($logger, $cache);

$agent = agent('assistant')
    ->model('gpt-4');

// Apply full production middleware stack
$agent = $factory->createProductionStack($agent);

// All middleware runs in order
$response = $agent->prompt('Complex question requiring rate limiting and caching');
```

## Creating Custom Transformers

Let's build a content transformer middleware that modifies responses:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Pagent\Contracts\Middleware;

final class MarkdownEnhancerMiddleware implements Middleware
{
    public function __construct(
        private readonly bool $addLineNumbers = false,
        private readonly bool $highlightCode = true,
        private readonly string $codeTheme = 'github',
    ) {}

    public function before(string $message, array $options): array
    {
        // Request markdown format from the model
        if (! isset($options['format'])) {
            $options['format'] = 'markdown';
        }

        return $options;
    }

    public function after(object $response): object
    {
        if (empty($response->content)) {
            return $response;
        }

        $content = $response->content;

        // Add line numbers to code blocks
        if ($this->addLineNumbers) {
            $content = $this->addCodeLineNumbers($content);
        }

        // Enhance code highlighting
        if ($this->highlightCode) {
            $content = $this->enhanceCodeBlocks($content);
        }

        // Add metadata
        $response->content = $content;
        $response->enhanced = true;
        $response->enhancements = [
            'lineNumbers' => $this->addLineNumbers,
            'highlighting' => $this->highlightCode,
            'theme' => $this->codeTheme,
        ];

        return $response;
    }

    private function addCodeLineNumbers(string $content): string
    {
        return preg_replace_callback(
            '/```(\w+)?\n(.*?)```/s',
            function ($matches) {
                $language = $matches[1] ?? '';
                $code = $matches[2];
                $lines = explode("\n", trim($code));

                $numberedLines = array_map(
                    fn($line, $num) => sprintf('%3d | %s', $num + 1, $line),
                    $lines,
                    array_keys($lines)
                );

                return "```{$language}\n" . implode("\n", $numberedLines) . "\n```";
            },
            $content
        );
    }

    private function enhanceCodeBlocks(string $content): string
    {
        // Add syntax highlighting hints
        return preg_replace(
            '/```(\w+)?/',
            '```$1 {"theme":"' . $this->codeTheme . '"}',
            $content
        );
    }
}
```

## Testing Your Middleware

Always test middleware in isolation before deploying:

```php
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    public function testRateLimiterEnforcement(): void
    {
        $limiter = new TokenBucketRateLimiter(capacity: 2, refillRate: 0.1);

        // First two requests succeed
        $limiter->before('test1', []);
        $limiter->before('test2', []);

        // Third request should fail
        $this->expectException(RuntimeException::class);
        $limiter->before('test3', []);
    }

    public function testCacheKeyGeneration(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $middleware = new ResponseCacheMiddleware($cache);

        $options1 = ['model' => 'gpt-4', 'temperature' => 0.7];
        $options2 = ['model' => 'gpt-4', 'temperature' => 0.9];

        $middleware->before('same message', $options1);
        $key1 = $this->getPrivateProperty($middleware, 'currentCacheKey');

        $middleware->before('same message', $options2);
        $key2 = $this->getPrivateProperty($middleware, 'currentCacheKey');

        // Different temperatures should generate different keys
        $this->assertNotEquals($key1, $key2);
    }
}
```

## Summary

You've now mastered custom middleware development in Pagent. You've learned to create middleware that implements the simple but powerful Middleware interface, build sophisticated rate limiters using token bucket algorithms, implement intelligent caching that reduces costs and latency, add comprehensive audit logging for compliance and debugging, and compose middleware chains for production-ready agents.

Your middleware toolkit now includes request ID tracking for distributed tracing, token bucket rate limiting with per-model buckets, content-aware response caching with PSR-6 integration, audit logging with cost estimation, and content transformation for enhanced output formatting.

## Next Steps

With your custom middleware skills, you're ready to tackle Chapter 26: Performance Optimization, where you'll learn to profile agent performance, optimize token usage, implement batch processing, and build high-throughput AI systems that scale to production workloads.

## Additional Resources

- [PSR-6 Caching Interface Specification](https://www.php-fig.org/psr/psr-6/)
- [Token Bucket Algorithm Deep Dive](https://en.wikipedia.org/wiki/Token_bucket)
- [Distributed Tracing Best Practices](https://opentelemetry.io/docs/)
- [Audit Logging Standards and Compliance](https://www.sans.org/white-papers/)