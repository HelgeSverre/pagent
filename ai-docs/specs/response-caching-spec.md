# Response Caching Specification

**Status:** Planning
**Target Version:** v0.10.0 - Caching & Performance
**Created:** 2025-10-29
**Estimated Effort:** 4-6 hours

## Overview

Implement response caching to avoid redundant LLM API calls for identical or similar prompts. This will reduce costs, improve response times, and enable offline development/testing scenarios.

## Problem Statement

**Current behavior:**
```php
$agent = agent('assistant')->provider(anthropic());

// First call: hits API ($0.003 per 1k tokens)
$response1 = $agent->prompt('What is 2+2?');

// Second identical call: hits API AGAIN ($0.003 per 1k tokens)
$response2 = $agent->prompt('What is 2+2?');

// Cost: $0.006, Time: 2x latency
```

**Desired behavior:**
```php
$agent = agent('assistant')
    ->provider(anthropic())
    ->cache('file'); // or 'memory', 'redis'

// First call: hits API ($0.003)
$response1 = $agent->prompt('What is 2+2?');

// Second identical call: returns cached response ($0.000)
$response2 = $agent->prompt('What is 2+2?');

// Cost: $0.003, Time: 2ms vs 500ms
```

## Goals

1. **Transparent caching** - Works without changing existing code
2. **Multiple backends** - Memory, File, Redis, custom adapters
3. **Configurable TTL** - Cache expiration (default: 1 hour)
4. **Smart invalidation** - Clear cache when config changes
5. **Opt-in per agent** - Not enabled globally by default
6. **Development-friendly** - Easy to disable for testing

## Non-Goals (Out of Scope)

- ❌ **Semantic caching** - "What is 2+2?" ≠ "Calculate two plus two" (requires embeddings, too complex)
- ❌ **Partial response caching** - Streaming with cached chunks (too complex for v1)
- ❌ **Cross-agent caching** - Sharing cache between different agents (different system prompts)
- ❌ **Automatic cache warming** - Pre-populating cache (user's responsibility)
- ❌ **Distributed cache coordination** - Cache sync across servers (use Redis if needed)

## Architecture

### Cache Key Generation

**Cache key components:**
1. Agent system prompt (affects responses)
2. User message (the actual prompt)
3. Model name (different models = different responses)
4. Temperature (affects randomness)
5. Max tokens (affects truncation)
6. Tools available (affects tool calling)

**Cache key format:**
```
pagent:cache:{agent_name}:{hash}
```

**Hash calculation:**
```php
$cacheData = [
    'system' => $agent->system ?? '',
    'message' => $message,
    'model' => $agent->model ?? '',
    'temperature' => $agent->temperature ?? 0.7,
    'max_tokens' => $agent->maxTokens ?? null,
    'tools' => array_map(fn($t) => $t->name, $agent->getTools()),
];

$hash = hash('sha256', json_encode($cacheData));
```

### Implementation via Middleware

```php
class CacheMiddleware implements Middleware
{
    public function __construct(
        private CacheAdapter $adapter,
        private int $ttl = 3600, // 1 hour default
    ) {}

    public function before(string $message, array $options): array
    {
        $cacheKey = $this->generateCacheKey($message, $options);

        if ($cached = $this->adapter->get($cacheKey)) {
            // Return cached response, skip provider call
            throw new CachedResponseException($cached);
        }

        // Store key in options for after() hook
        $options['__cache_key'] = $cacheKey;

        return $options;
    }

    public function after(object $response): object
    {
        // Store response in cache
        $cacheKey = $response->__cache_key ?? null;

        if ($cacheKey) {
            $this->adapter->set($cacheKey, $response, $this->ttl);
        }

        return $response;
    }
}
```

## Cache Adapters

### 1. Memory Adapter (Default for Testing)

```php
class MemoryCacheAdapter implements CacheAdapter
{
    private array $cache = [];
    private array $expiry = [];

    public function get(string $key): ?object
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        // Check expiry
        if (isset($this->expiry[$key]) && $this->expiry[$key] < time()) {
            unset($this->cache[$key], $this->expiry[$key]);
            return null;
        }

        return $this->cache[$key];
    }

    public function set(string $key, object $value, int $ttl): void
    {
        $this->cache[$key] = $value;
        $this->expiry[$key] = time() + $ttl;
    }

    public function delete(string $key): void
    {
        unset($this->cache[$key], $this->expiry[$key]);
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->expiry = [];
    }
}
```

### 2. File Adapter (Persistent, No Dependencies)

```php
class FileCacheAdapter implements CacheAdapter
{
    public function __construct(
        private string $cacheDir = '/tmp/pagent-cache'
    ) {
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }

    public function get(string $key): ?object
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = unserialize(file_get_contents($file));

        // Check expiry
        if ($data['expiry'] < time()) {
            unlink($file);
            return null;
        }

        return $data['response'];
    }

    public function set(string $key, object $value, int $ttl): void
    {
        $file = $this->getFilePath($key);

        $data = [
            'response' => $value,
            'expiry' => time() + $ttl,
        ];

        file_put_contents($file, serialize($data));
    }

    public function delete(string $key): void
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear(): void
    {
        $files = glob($this->cacheDir . '/pagent-*.cache');

        foreach ($files as $file) {
            unlink($file);
        }
    }

    private function getFilePath(string $key): string
    {
        return $this->cacheDir . '/pagent-' . hash('sha256', $key) . '.cache';
    }
}
```

### 3. Redis Adapter (Production, Distributed)

```php
class RedisCacheAdapter implements CacheAdapter
{
    public function __construct(
        private Redis $redis,
        private string $prefix = 'pagent:cache:'
    ) {}

    public function get(string $key): ?object
    {
        $data = $this->redis->get($this->prefix . $key);

        if ($data === false) {
            return null;
        }

        return unserialize($data);
    }

    public function set(string $key, object $value, int $ttl): void
    {
        $this->redis->setex(
            $this->prefix . $key,
            $ttl,
            serialize($value)
        );
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function clear(): void
    {
        $keys = $this->redis->keys($this->prefix . '*');

        if ($keys) {
            $this->redis->del(...$keys);
        }
    }
}
```

## API Design

### Basic Usage

```php
// Enable caching with memory adapter (default)
$agent = agent('assistant')
    ->provider(anthropic())
    ->cache();

// Enable caching with file adapter
$agent = agent('assistant')
    ->provider(anthropic())
    ->cache('file');

// Enable caching with custom TTL (seconds)
$agent = agent('assistant')
    ->provider(anthropic())
    ->cache('file', ttl: 3600); // 1 hour

// Enable caching with Redis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$agent = agent('assistant')
    ->provider(anthropic())
    ->cache(new RedisCacheAdapter($redis));
```

### Cache Management

```php
// Clear cache for specific agent
$agent->clearCache();

// Disable cache temporarily
$agent->disableCache();
$response = $agent->prompt('Fresh response');
$agent->enableCache();

// Check if response was cached
$response = $agent->prompt('Hello');

if ($response->from_cache ?? false) {
    echo "Cache hit!";
}
```

### Manual Cache Control

```php
// Bust cache for specific prompt
$agent->bustCache('What is 2+2?');

// Warm cache with expected responses
$agent->warmCache([
    'What is 2+2?' => (object)['content' => '4', 'tokens' => 10],
    'What is PHP?' => (object)['content' => 'A programming language', 'tokens' => 20],
]);
```

## Cache Invalidation Strategy

**Automatic invalidation when:**
1. System prompt changes
2. Model changes
3. Temperature changes
4. Max tokens changes
5. Tools added/removed

**Implementation:**
```php
class Agent
{
    public function system(string $prompt): self
    {
        // If cache is enabled and system prompt changed, clear cache
        if ($this->hasCache() && $this->system !== $prompt) {
            $this->clearCache();
        }

        $this->system = $prompt;

        return $this;
    }

    public function model(string $model): self
    {
        if ($this->hasCache() && $this->model !== $model) {
            $this->clearCache();
        }

        $this->model = $model;

        return $this;
    }

    // Similar for temperature(), maxTokens(), tool()
}
```

## Implementation Plan

### Phase 1: Core Cache Infrastructure (2 hours)
1. Create `Contracts/CacheAdapter.php` interface
2. Implement `MemoryCacheAdapter` (for testing)
3. Implement `FileCacheAdapter` (for persistence)
4. Add `CacheMiddleware` class
5. Add tests for adapters

### Phase 2: Agent Integration (1 hour)
1. Add `cache()` method to `Agent` class
2. Add `clearCache()`, `bustCache()` methods
3. Add cache invalidation to config methods (system, model, etc.)
4. Add `from_cache` flag to responses

### Phase 3: Redis Adapter (1 hour)
1. Implement `RedisCacheAdapter`
2. Add Redis tests (skip if Redis not available)
3. Document Redis setup

### Phase 4: Documentation & Testing (2 hours)
1. Write comprehensive tests for all adapters
2. Write integration tests (cache hits/misses)
3. Update README with caching examples
4. Add cache configuration to docs
5. Add performance benchmarks

## Configuration

### Global Config (Optional)

```php
// config/pagent.php
return [
    'cache' => [
        'enabled' => env('PAGENT_CACHE_ENABLED', false),
        'adapter' => env('PAGENT_CACHE_ADAPTER', 'memory'),
        'ttl' => env('PAGENT_CACHE_TTL', 3600),
        'file_path' => env('PAGENT_CACHE_PATH', storage_path('pagent-cache')),
    ],
];
```

### Environment Variables

```bash
PAGENT_CACHE_ENABLED=true
PAGENT_CACHE_ADAPTER=file
PAGENT_CACHE_TTL=3600
PAGENT_CACHE_PATH=/var/cache/pagent
```

## Testing Strategy

### Unit Tests

```php
test('it caches identical prompts', function () {
    $callCount = 0;

    $mockProvider = new class($callCount) implements Provider {
        public function __construct(private int &$count) {}

        public function prompt(string $message, array $options = []): object {
            $this->count++;
            return (object)['content' => 'response', 'tokens' => 10];
        }
    };

    $agent = new Agent('test');
    $agent->provider($mockProvider);
    $agent->cache(); // Enable memory cache

    $response1 = $agent->prompt('test');
    $response2 = $agent->prompt('test');

    expect($callCount)->toBe(1); // Only called once
    expect($response2->from_cache)->toBeTrue();
});

test('it invalidates cache when config changes', function () {
    $agent = testAgent();
    $agent->cache();

    $agent->prompt('test');
    expect($agent->getCacheHits())->toBe(0);

    $agent->prompt('test');
    expect($agent->getCacheHits())->toBe(1);

    // Change system prompt - should clear cache
    $agent->system('New system prompt');

    $agent->prompt('test');
    expect($agent->getCacheHits())->toBe(1); // Still 1 (not 2) because cache was cleared
});

test('file adapter persists across instances', function () {
    $cacheDir = sys_get_temp_dir() . '/pagent-test-' . uniqid();

    $agent1 = testAgent();
    $agent1->cache(new FileCacheAdapter($cacheDir));
    $agent1->prompt('test');

    // Create new agent with same cache dir
    $agent2 = testAgent();
    $agent2->cache(new FileCacheAdapter($cacheDir));

    $response = $agent2->prompt('test');

    expect($response->from_cache)->toBeTrue();

    // Cleanup
    (new FileCacheAdapter($cacheDir))->clear();
    rmdir($cacheDir);
});
```

## Performance Expectations

**Cache Hit Latency:**
- Memory: < 1ms
- File: < 5ms
- Redis (local): < 2ms
- Redis (network): < 10ms

**Cost Savings:**
- 100 identical prompts without cache: $0.30
- 100 identical prompts with cache: $0.003 (99% savings)

## Files to Create/Modify

1. **src/Contracts/CacheAdapter.php** - New interface
2. **src/Cache/MemoryCacheAdapter.php** - New class
3. **src/Cache/FileCacheAdapter.php** - New class
4. **src/Cache/RedisCacheAdapter.php** - New class
5. **src/Middleware/CacheMiddleware.php** - New class
6. **src/Agent.php** - Add cache methods
7. **tests/Unit/Cache/** - New test directory
8. **README.md** - Add caching documentation

## Edge Cases & Considerations

### 1. Tool Calling
```php
// Different tool calls = different cache keys
$agent->tool('weather', 'Get weather', fn($loc) => "...");

$r1 = $agent->prompt('Weather in NYC?');   // Cache miss
$r2 = $agent->prompt('Weather in NYC?');   // Cache HIT
$r3 = $agent->prompt('Weather in LA?');    // Cache miss (different prompt)
```

### 2. Conversation History
```php
// With conversation history, cache key includes full history
$agent->prompt('Hello');     // Cache miss
$agent->prompt('How are you?'); // Cache miss (different history)
$agent->prompt('Hello');     // Still cache miss (history different from first call)
```

**Solution:** Only cache single-turn prompts, not conversational agents. Or make history part of cache key.

### 3. Streaming Responses
```php
// Streaming responses should NOT be cached (too complex)
$agent->cache()->stream('Tell me a story', function($chunk) {
    echo $chunk;
});
// Cache middleware should detect streaming and skip caching
```

### 4. Non-Deterministic Responses
```php
// High temperature = non-deterministic
$agent->temperature(1.0)->cache();

$r1 = $agent->prompt('Tell me a joke');
$r2 = $agent->prompt('Tell me a joke'); // Returns SAME cached joke

// Maybe: Add warning if temperature > 0.8 and caching enabled
```

## Success Metrics

- Cache hit rate > 50% in typical usage
- < 5ms cache lookup latency
- 99% cost reduction for repeated prompts
- Zero breaking changes to existing code
- Positive developer feedback

## Future Enhancements (v2.0+)

- Semantic caching with embeddings
- Cache analytics dashboard
- Automatic cache warming from logs
- Cache compression for large responses
- Distributed cache coordination
