# Chapter 26: Performance Optimization

**Learning Objectives:**

- Optimize token usage with context windowing
- Implement response caching strategies
- Reduce API latency through configuration
- Apply batch processing patterns
- Profile performance with telemetry

---

## Why Performance Matters

LLM applications face unique performance challenges. API calls are expensive—both in cost and latency. A single Claude API call can take seconds and consume thousands of tokens. Multiply this across hundreds or thousands of user interactions, and costs balloon while responsiveness suffers.

Performance optimization for AI agents isn't just about speed. It's about:

- **Cost efficiency** - Each token costs money. Reducing unnecessary API calls directly impacts your budget.
- **User experience** - Faster responses mean happier users. Nobody wants to wait 10 seconds for a chatbot reply.
- **Scalability** - Optimized agents handle more concurrent users with the same infrastructure.
- **Resource management** - Memory-efficient patterns prevent crashes under load.

Pagent provides several built-in optimization features and patterns for implementing custom performance improvements. Unlike some frameworks that include automatic caching, Pagent gives you the tools to build exactly the optimization strategy your application needs.

## Token Optimization with Context Windowing

The most expensive part of any LLM interaction is the context window—all the messages sent with each request. Long conversation histories quickly exhaust token limits and increase costs.

### The Problem: Unbounded Context Growth

By default, agents maintain complete conversation history:

```php
use function Pagent\agent;

$agent = agent('chat')
    ->provider('anthropic')
    ->system('You are a helpful assistant.');

// Each prompt adds to history
$agent->prompt('What is PHP?');         // ~100 tokens
$agent->prompt('Tell me more.');        // ~200 tokens total
$agent->prompt('Give me examples.');    // ~400 tokens total
// ... after 50 exchanges, you might be at 10,000+ tokens
```

Every message sent includes the entire history. This grows linearly with conversation length, making long sessions prohibitively expensive.

### Solution: Context Window Management

Use `contextWindow()` to automatically prune messages before sending to the LLM:

```php
$agent = agent('optimized-chat')
    ->provider('anthropic')
    ->contextWindow(4000, 'oldest')  // Max 4000 tokens, remove oldest
    ->system('You are a helpful assistant.');

// Now the agent automatically prunes history to stay under 4000 tokens
for ($i = 0; $i < 100; $i++) {
    $agent->prompt("Question {$i}");
    // Context window automatically manages token limit
}
```

The `contextWindow()` method takes two parameters:

- `$maxTokens` - Maximum tokens to maintain in context (default: 4000)
- `$strategy` - Pruning strategy: `'oldest'` or `'sliding'`

### Pruning Strategies

**Oldest Strategy** - Removes oldest messages first while preserving system prompts:

```php
$agent->contextWindow(2000, 'oldest');

// System prompt is always preserved
// Oldest user/assistant exchanges are removed first
// Most recent messages are kept for context continuity
```

This works well for customer support agents where recent context matters most and you want to preserve the initial system instructions.

**Sliding Window Strategy** - Keeps only the most recent messages:

```php
$agent->contextWindow(2000, 'sliding');

// Maintains a sliding window of recent messages
// System prompt is preserved
// Removes older messages to stay within limit
```

Perfect for chat applications where only immediate context is relevant.

### How Context Pruning Works

Pagent uses the `ContextManager` class to track token usage. It estimates tokens using a 4:1 character-to-token ratio (4 characters ≈ 1 token), which provides a reasonable approximation without external dependencies:

```php
use Pagent\Memory\ContextManager;

$manager = new ContextManager(
    maxTokens: 4000,
    strategy: 'sliding'
);

// Prune messages to fit within limit
$prunedMessages = $manager->prune($agent->messages);

// Count tokens in message history
$tokenCount = $manager->countTokens($agent->messages);
```

The `ContextManager` automatically preserves system messages—they're never pruned, ensuring your agent's instructions remain intact throughout the conversation.

### When to Use Context Windowing

Use context windows when:

- Building long-running chat sessions
- Handling customer support with extended conversations
- Creating agents that need to stay within budget constraints
- Implementing streaming responses where history accumulates

Skip context windowing when:

- Conversations are naturally short (1-3 exchanges)
- You need complete history for compliance/auditing
- Working with summarization agents that need full context

## Tool Schema Caching

When you register tools with an agent, Pagent must convert them into JSON schemas for the provider API. For agents with many tools, this conversion happens on every request—an unnecessary overhead.

### Automatic Schema Caching

Pagent automatically caches tool schemas after the first API call:

```php
$agent = agent('tooled')
    ->provider('anthropic')
    ->tool('calculate', 'Perform math', fn(int $a, int $b) => $a + $b)
    ->tool('search', 'Search database', fn(string $query) => search($query))
    ->tool('fetch', 'Fetch URL', fn(string $url) => file_get_contents($url));

// First call: schemas are generated and cached
$agent->prompt('Calculate 5 + 3');

// Subsequent calls: cached schemas are reused
$agent->prompt('Search for users');
$agent->prompt('Fetch https://example.com');
```

The cache is stored in a private `$cachedToolSchemas` property and invalidated automatically when tools change:

```php
// Cache is invalidated when you modify tools
$agent->tool('new_tool', 'A new tool', fn() => 'result');  // Cache cleared
$agent->clearTools();                                       // Cache cleared
```

This optimization is completely transparent—you don't need to do anything to benefit from it. For agents with 10+ tools, schema caching can reduce overhead by 10-20ms per request.

## Temperature for Deterministic Outputs

LLM responses are probabilistic by default. The same prompt can produce different outputs on each call due to random sampling. This variability makes caching difficult.

### Using Temperature for Consistency

Set temperature to `0.0` for deterministic, repeatable responses:

```php
$agent = agent('deterministic')
    ->provider('anthropic')
    ->temperature(0.0)  // Maximum determinism
    ->system('Answer factual questions concisely.');

// Same input produces same output (usually)
$response1 = $agent->prompt('What is 2 + 2?');
$response2 = $agent->prompt('What is 2 + 2?');
// Both should return "4" with identical phrasing
```

Temperature ranges from `0.0` (deterministic) to `2.0` (highly random):

- `0.0` - Maximum consistency, best for caching and testing
- `0.5` - Balanced creativity, good for general chat
- `1.0` - Default randomness for most models
- `2.0` - Maximum creativity, unpredictable outputs

Lower temperatures enable effective caching because identical prompts produce identical responses. This is particularly valuable for:

- FAQ agents answering common questions
- Data extraction from documents
- JSON generation for structured outputs
- Classification tasks

### Temperature vs Caching

```php
// HIGH temperature - poor cache hit rate
$creative = agent('creative')
    ->temperature(1.5)
    ->system('Write creative stories.');

$creative->prompt('Write about a cat.');  // "Once upon a time..."
$creative->prompt('Write about a cat.');  // "In a distant land..."
// Different responses = cache misses

// LOW temperature - high cache hit rate
$factual = agent('factual')
    ->temperature(0.0)
    ->system('Answer factually.');

$factual->prompt('Capital of France?');  // "Paris"
$factual->prompt('Capital of France?');  // "Paris" (identical)
// Same responses = cache hits
```

## Response Caching with Middleware

Pagent doesn't include built-in response caching, but middleware makes implementing it straightforward. Here's a complete caching middleware implementation:

```php
use Pagent\Contracts\Middleware;

final class CachingMiddleware implements Middleware
{
    private array $cache = [];
    private ?string $currentKey = null;

    public function __construct(
        private readonly int $ttl = 3600  // 1 hour TTL
    ) {}

    public function before(string $message, array $options): array
    {
        // Generate cache key from message and options
        $this->currentKey = $this->generateKey($message, $options);

        // Check if we have a cached response
        if (isset($this->cache[$this->currentKey])) {
            $cached = $this->cache[$this->currentKey];

            // Check if cache entry is still valid
            if ($cached['expires'] > time()) {
                // Store cached response for after() to return
                $options['_cached_response'] = $cached['response'];
            }
        }

        return $options;
    }

    public function after(object $response): object
    {
        // Return cached response if available
        if (isset($options['_cached_response'])) {
            return $options['_cached_response'];
        }

        // Cache new response
        $this->cache[$this->currentKey] = [
            'response' => $response,
            'expires' => time() + $this->ttl,
        ];

        return $response;
    }

    private function generateKey(string $message, array $options): string
    {
        // Include relevant options in cache key
        $keyData = [
            'message' => $message,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
        ];

        return md5(json_encode($keyData));
    }
}
```

Use it with any agent:

```php
$cache = new CachingMiddleware(ttl: 3600);

$agent = agent('cached-agent')
    ->provider('anthropic')
    ->temperature(0.0)  // Deterministic for better caching
    ->middleware($cache);

// First call hits API
$response1 = $agent->prompt('What is PHP?');

// Second call returns cached response
$response2 = $agent->prompt('What is PHP?');
```

### Production Caching with Redis

For production systems, use a real cache backend like Redis:

```php
final class RedisCachingMiddleware implements Middleware
{
    private ?string $currentKey = null;

    public function __construct(
        private readonly Redis $redis,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'agent_cache:'
    ) {}

    public function before(string $message, array $options): array
    {
        $this->currentKey = $this->prefix . $this->generateKey($message, $options);

        // Try to get cached response
        $cached = $this->redis->get($this->currentKey);

        if ($cached !== false) {
            // Unserialize and store in options
            $options['_cached_response'] = unserialize($cached);
        }

        return $options;
    }

    public function after(object $response): object
    {
        // Return cached response if available
        if (isset($options['_cached_response'])) {
            return $options['_cached_response'];
        }

        // Cache new response
        $this->redis->setex(
            $this->currentKey,
            $this->ttl,
            serialize($response)
        );

        return $response;
    }

    private function generateKey(string $message, array $options): string
    {
        $keyData = [
            'message' => $message,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
            'model' => $options['model'] ?? null,
        ];

        return hash('sha256', json_encode($keyData));
    }
}
```

This pattern provides:

- **Shared cache** across multiple processes
- **Persistence** that survives application restarts
- **TTL management** through Redis expiration
- **Eviction policies** via Redis LRU configuration

## Performance Profiling with Telemetry

You can't optimize what you don't measure. Pagent's OpenTelemetry integration provides detailed performance insights into agent operations.

### Enabling Telemetry

```php
use function Pagent\telemetry_jaeger;

// Configure telemetry backend (Jaeger)
telemetry_jaeger();

// Enable telemetry on agent
$agent = agent('profiled')
    ->provider('anthropic')
    ->telemetry(true)  // Enable tracing
    ->tool('slow_tool', 'A slow operation', function() {
        sleep(2);
        return 'result';
    });

$agent->prompt('Use the slow tool');
```

This creates detailed traces showing:

- **LLM API call duration** - How long the provider request took
- **Tool execution time** - Performance of individual tools
- **Context pruning overhead** - Cost of managing conversation history
- **Total request latency** - End-to-end timing

View traces in Jaeger UI (http://localhost:16686) to identify bottlenecks.

### What to Look For

When profiling, watch for:

**Slow tool executions** - Tools that take >1s deserve optimization:

```php
// Before: Slow file reading
$agent->tool('read_file', 'Read file', function(string $path) {
    return file_get_contents($path);  // Blocking I/O
});

// After: Cached reading
$agent->tool('read_file', 'Read file', function(string $path) use ($cache) {
    return $cache->remember($path, 300, fn() => file_get_contents($path));
});
```

**Excessive API calls** - Multiple back-and-forth tool calling cycles indicate poor prompting or tool design.

**Context window overhead** - If pruning takes >50ms, your conversations are too large. Consider more aggressive windowing or summarization.

## Batch Processing Patterns

Pagent doesn't include built-in parallelization, but you can implement batch processing using PHP's process forking or async libraries.

### Sequential Batch Processing

For simple batch operations:

```php
$tasks = [
    'What is PHP?',
    'What is Python?',
    'What is JavaScript?',
    'What is Ruby?',
];

$agent = agent('batch')
    ->provider('anthropic')
    ->temperature(0.0);

$results = [];
foreach ($tasks as $task) {
    $results[] = $agent->prompt($task)->content;
}

// $results contains all responses (processed sequentially)
```

This processes tasks one at a time. For 10 tasks at 2 seconds each, total time is 20 seconds.

### Parallel Processing with Agent Cloning

For parallel processing, clone agents and use a process manager:

```php
use Pagent\Agent;

// Create base agent
$base = agent('parallel-base')
    ->provider('anthropic')
    ->temperature(0.0);

$tasks = ['Task 1', 'Task 2', 'Task 3', 'Task 4'];
$workers = [];

// Clone agent for each task
foreach ($tasks as $i => $task) {
    $workers[$i] = $base->clone("worker-{$i}");
}

// Process with parallel library (e.g., amphp, ReactPHP)
// This is a conceptual example - actual implementation depends on async library
```

Agent cloning creates independent instances that share configuration but maintain separate conversation state. This enables parallel processing without state pollution.

### Memory Optimization for Batches

When processing large batches, manage memory carefully:

```php
$agent = agent('batch')
    ->provider('anthropic')
    ->memory(null);  // Use NullAdapter - no persistence

foreach ($largeBatch as $i => $item) {
    $result = $agent->prompt($item);

    // Process result immediately
    processResult($result);

    // Clear history to prevent memory growth
    if ($i % 100 === 0) {
        $agent->messages = [];  // Reset conversation
    }
}
```

For batch operations, the `NullAdapter` avoids persistence overhead since you typically don't need conversation history between batch items.

## Latency Reduction Techniques

Beyond caching and batching, several techniques reduce perceived and actual latency.

### Provider-Level Timeouts

Configure HTTP timeouts to fail fast rather than waiting indefinitely:

```php
use Pagent\Providers\Anthropic;
use Pagent\Support\HttpClient;

$client = new HttpClient(timeout: 10);  // 10 second timeout

$anthropic = new Anthropic(
    apiKey: getenv('ANTHROPIC_API_KEY'),
    httpClient: $client
);

$agent = agent('fast-fail')
    ->provider($anthropic);

// Will timeout after 10s instead of default 30s
```

Fast failures prevent hanging requests from blocking resources.

### Cleanup for Resource Efficiency

Clear unnecessary state when agents are done:

```php
// After agent completes its task
$agent->clearTools();        // Remove tool definitions
$agent->clearGuards();       // Remove guard validators
$agent->clearMiddleware();   // Remove middleware instances

// This releases memory and reduces overhead for garbage collection
```

For long-running applications, aggressive cleanup prevents memory leaks.

### Memory Adapter Selection

Choose the right memory adapter for your performance profile:

```php
use Pagent\Memory\{FileAdapter, DatabaseAdapter, NullAdapter};

// Fastest: No persistence
$agent->memory(null);  // NullAdapter

// Fast: File-based persistence
$agent->memory(new FileAdapter('/tmp/agents'));

// Slower: Database persistence (but queryable/shareable)
$agent->memory(new DatabaseAdapter($pdo));
```

If you don't need conversation persistence, `NullAdapter` provides maximum performance.

## Real-World Optimization Example

Here's a complete example combining multiple optimization techniques:

```php
use function Pagent\{agent, telemetry_jaeger};
use Pagent\Support\HttpClient;

// Setup telemetry
telemetry_jaeger();

// Create caching middleware
$cache = new RedisCachingMiddleware(
    redis: new Redis(),
    ttl: 1800  // 30 minutes
);

// Create optimized agent
$agent = agent('optimized-support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->temperature(0.0)              // Deterministic for caching
    ->contextWindow(3000, 'sliding') // Limit context growth
    ->middleware($cache)             // Cache responses
    ->telemetry(true)               // Profile performance
    ->memory(null)                   // No persistence needed
    ->system('You are a customer support agent. Be concise.');

// Register tools
$agent->tool('search_faq', 'Search FAQ database', function(string $query) use ($faqCache) {
    // Cache FAQ results
    return $faqCache->remember($query, 3600, fn() => searchFAQ($query));
});

$agent->tool('create_ticket', 'Create support ticket', function(string $issue) {
    return createTicket($issue);
});

// Process support request
$response = $agent->prompt($userMessage);

// Metrics from telemetry show:
// - Cache hit rate: 65% (cache working well)
// - Average response time: 800ms (uncached), 50ms (cached)
// - Context pruning: <10ms (efficient windowing)
// - Tool execution: 200ms average
```

This agent achieves:

- **65% cache hit rate** reducing API costs by 2/3
- **50ms cached response time** vs 800ms uncached
- **Controlled memory usage** via context windowing and NullAdapter
- **Observable performance** through telemetry traces

## Best Practices Summary

**Start with measurement** - Enable telemetry before optimizing. Profile real usage patterns.

**Use context windows for long sessions** - Don't let conversation history grow unbounded.

**Cache deterministic outputs** - Set `temperature(0.0)` for FAQ/classification agents and implement caching.

**Clone agents for parallel work** - Use `clone()` to create independent workers for batch processing.

**Choose the right memory adapter** - Use `NullAdapter` when persistence isn't needed.

**Clear resources aggressively** - Call `clearTools()`, `clearGuards()`, `clearMiddleware()` after use.

**Optimize tools first** - Slow tools hurt more than API latency. Cache tool results when possible.

**Test under load** - Performance characteristics change dramatically under concurrent load.

---

Performance optimization is an ongoing process. Start with built-in features like context windowing and schema caching, add custom caching middleware as needed, and use telemetry to identify bottlenecks. With these techniques, you can build agents that scale efficiently and deliver responsive user experiences while controlling costs.
