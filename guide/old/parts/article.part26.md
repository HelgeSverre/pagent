# Chapter 26: Performance Optimization

## What You'll Learn

In this chapter, you'll master performance optimization techniques for AI applications. You'll learn to optimize token usage for cost efficiency, implement intelligent caching strategies, reduce API latency through smart request management, and leverage batch processing for high-throughput operations. By the end, you'll have a comprehensive toolkit for building performant AI applications that scale efficiently.

## Prerequisites

- Understanding of Pagent's core agent system (Chapters 1-5)
- Familiarity with streaming responses (Chapter 10)
- Knowledge of conversation management (Chapter 6)
- Experience with error handling (Chapter 11)

## Time Estimate

45-60 minutes to complete all exercises and implement the optimization suite.

## Final Result

You'll build a high-performance AI assistant with token optimization, intelligent caching, batch processing capabilities, and a comprehensive benchmark suite to measure improvements.

## Understanding Performance Bottlenecks

Before optimizing, let's identify common performance issues in AI applications:

```php
<?php

declare(strict_types=1);

namespace App\Performance;

use Pagent\Agent;
use RuntimeException;

final class PerformanceProfiler
{
    private array $metrics = [];
    private float $startTime;

    public function startProfiling(): void
    {
        $this->startTime = microtime(true);
        $this->metrics = [
            'api_calls' => 0,
            'tokens_used' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'response_times' => [],
        ];
    }

    public function recordApiCall(int $tokens, float $responseTime): void
    {
        $this->metrics['api_calls']++;
        $this->metrics['tokens_used'] += $tokens;
        $this->metrics['response_times'][] = $responseTime;
    }

    public function recordCacheHit(): void
    {
        $this->metrics['cache_hits']++;
    }

    public function recordCacheMiss(): void
    {
        $this->metrics['cache_misses']++;
    }

    public function getReport(): array
    {
        $totalTime = microtime(true) - $this->startTime;
        $avgResponseTime = count($this->metrics['response_times']) > 0
            ? array_sum($this->metrics['response_times']) / count($this->metrics['response_times'])
            : 0;

        return [
            'total_time' => $totalTime,
            'api_calls' => $this->metrics['api_calls'],
            'tokens_used' => $this->metrics['tokens_used'],
            'avg_response_time' => $avgResponseTime,
            'cache_hit_rate' => $this->calculateCacheHitRate(),
            'tokens_per_second' => $totalTime > 0 ? $this->metrics['tokens_used'] / $totalTime : 0,
        ];
    }

    private function calculateCacheHitRate(): float
    {
        $total = $this->metrics['cache_hits'] + $this->metrics['cache_misses'];

        return $total > 0 ? $this->metrics['cache_hits'] / $total : 0;
    }
}
```

This profiler helps identify where optimization efforts will have the most impact.

## Token Optimization Strategies

Tokens are the currency of AI applications. Let's implement strategies to use them efficiently:

```php
<?php

declare(strict_types=1);

namespace App\Performance;

use Pagent\Agent;

final class TokenOptimizer
{
    private const MAX_CONTEXT_TOKENS = 4000;
    private const SUMMARY_THRESHOLD = 3000;

    public function optimizePrompt(string $prompt, array $context = []): string
    {
        // Remove redundant whitespace
        $prompt = $this->normalizeWhitespace($prompt);

        // Compress context if needed
        if ($context !== []) {
            $context = $this->compressContext($context);
        }

        // Build optimized prompt
        return $this->buildOptimizedPrompt($prompt, $context);
    }

    public function truncateConversation(array $messages): array
    {
        $tokenCount = 0;
        $optimized = [];

        // Keep most recent messages within token limit
        foreach (array_reverse($messages) as $message) {
            $messageTokens = $this->estimateTokens($message['content']);

            if ($tokenCount + $messageTokens > self::MAX_CONTEXT_TOKENS) {
                break;
            }

            $optimized[] = $message;
            $tokenCount += $messageTokens;
        }

        return array_reverse($optimized);
    }

    public function summarizeIfNeeded(string $content): string
    {
        $tokenCount = $this->estimateTokens($content);

        if ($tokenCount > self::SUMMARY_THRESHOLD) {
            return $this->generateSummary($content);
        }

        return $content;
    }

    private function normalizeWhitespace(string $text): string
    {
        // Remove excessive whitespace while preserving structure
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function compressContext(array $context): array
    {
        return array_map(function ($item) {
            if (is_string($item)) {
                return $this->normalizeWhitespace($item);
            }

            if (is_array($item) && isset($item['content'])) {
                $item['content'] = $this->normalizeWhitespace($item['content']);
            }

            return $item;
        }, $context);
    }

    private function buildOptimizedPrompt(string $prompt, array $context): string
    {
        if ($context === []) {
            return $prompt;
        }

        $contextString = implode("\n", array_map(
            fn($item) => is_string($item) ? $item : ($item['content'] ?? ''),
            $context
        ));

        return "{$contextString}\n\n{$prompt}";
    }

    private function estimateTokens(string $text): int
    {
        // Rough estimation: ~4 characters per token
        return (int) ceil(strlen($text) / 4);
    }

    private function generateSummary(string $content): string
    {
        $agent = agent()
            ->using('anthropic')
            ->withPrompt('Summarize the following content concisely, preserving key information:')
            ->withContext($content);

        return $agent->generate();
    }
}
```

## Implementing Intelligent Caching

Caching responses dramatically reduces costs and latency for repeated queries:

```php
<?php

declare(strict_types=1);

namespace App\Performance;

use Psr\SimpleCache\CacheInterface;

final class ResponseCache
{
    private CacheInterface $cache;
    private TokenOptimizer $optimizer;
    private int $defaultTtl = 3600; // 1 hour

    public function __construct(CacheInterface $cache, TokenOptimizer $optimizer)
    {
        $this->cache = $cache;
        $this->optimizer = $optimizer;
    }

    public function getCachedResponse(string $prompt, array $context = []): ?string
    {
        $key = $this->generateCacheKey($prompt, $context);

        return $this->cache->get($key);
    }

    public function cacheResponse(
        string $prompt,
        string $response,
        array $context = [],
        ?int $ttl = null
    ): void {
        $key = $this->generateCacheKey($prompt, $context);
        $ttl = $ttl ?? $this->defaultTtl;

        $this->cache->set($key, $response, $ttl);
    }

    public function withSemanticCaching(string $prompt, callable $generator): string
    {
        // Check for exact match
        $exactKey = $this->generateCacheKey($prompt);
        $exactMatch = $this->cache->get($exactKey);

        if ($exactMatch !== null) {
            return $exactMatch;
        }

        // Check for semantic similarity
        $semanticMatch = $this->findSemanticMatch($prompt);

        if ($semanticMatch !== null) {
            return $semanticMatch;
        }

        // Generate new response
        $response = $generator($prompt);

        // Cache with both exact and semantic keys
        $this->cache->set($exactKey, $response, $this->defaultTtl);
        $this->cacheSemanticEntry($prompt, $response);

        return $response;
    }

    public function warmCache(array $commonPrompts): void
    {
        foreach ($commonPrompts as $prompt => $expectedResponse) {
            $key = $this->generateCacheKey($prompt);

            if ($this->cache->has($key) === false) {
                $this->cache->set($key, $expectedResponse, $this->defaultTtl * 24);
            }
        }
    }

    public function invalidatePattern(string $pattern): int
    {
        $deleted = 0;

        // This assumes your cache implementation supports pattern deletion
        // You might need to adapt this to your specific cache backend
        foreach ($this->cache->getMultiple(['*']) as $key => $value) {
            if (str_contains($key, $pattern)) {
                $this->cache->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function generateCacheKey(string $prompt, array $context = []): string
    {
        $normalized = $this->optimizer->normalizeWhitespace($prompt);
        $contextHash = $context !== [] ? md5(serialize($context)) : '';

        return 'response:' . md5($normalized . $contextHash);
    }

    private function findSemanticMatch(string $prompt): ?string
    {
        // Simplified semantic matching - in production, use embeddings
        $normalizedPrompt = strtolower($this->optimizer->normalizeWhitespace($prompt));

        // Check cached patterns
        $patterns = $this->cache->get('semantic:patterns', []);

        foreach ($patterns as $pattern => $responseKey) {
            if ($this->isSemanticallySimil($normalizedPrompt, $pattern)) {
                return $this->cache->get($responseKey);
            }
        }

        return null;
    }

    private function isSemanticallySimil(string $prompt1, string $prompt2): bool
    {
        // Simple similarity check - replace with proper embedding comparison
        similar_text($prompt1, $prompt2, $percent);

        return $percent > 85.0;
    }

    private function cacheSemanticEntry(string $prompt, string $response): void
    {
        $patterns = $this->cache->get('semantic:patterns', []);
        $normalizedPrompt = strtolower($this->optimizer->normalizeWhitespace($prompt));
        $responseKey = 'semantic:response:' . md5($response);

        $patterns[$normalizedPrompt] = $responseKey;

        $this->cache->set('semantic:patterns', $patterns, $this->defaultTtl * 24);
        $this->cache->set($responseKey, $response, $this->defaultTtl * 24);
    }
}
```

## Batch Processing for High Throughput

Process multiple requests efficiently with intelligent batching:

```php
<?php

declare(strict_types=1);

namespace App\Performance;

use Pagent\Agent;
use Generator;

final class BatchProcessor
{
    private int $batchSize;
    private float $batchTimeout;
    private array $pendingRequests = [];
    private PerformanceProfiler $profiler;

    public function __construct(
        int $batchSize = 10,
        float $batchTimeout = 1.0,
        PerformanceProfiler $profiler = null
    ) {
        $this->batchSize = $batchSize;
        $this->batchTimeout = $batchTimeout;
        $this->profiler = $profiler ?? new PerformanceProfiler();
    }

    public function processBatch(array $prompts): array
    {
        $this->profiler->startProfiling();
        $results = [];

        // Split into optimal batch sizes
        $batches = array_chunk($prompts, $this->batchSize);

        foreach ($batches as $batch) {
            $batchResults = $this->processSingleBatch($batch);
            $results = array_merge($results, $batchResults);
        }

        return $results;
    }

    public function streamBatchProcessing(Generator $prompts): Generator
    {
        $buffer = [];
        $lastFlush = microtime(true);

        foreach ($prompts as $key => $prompt) {
            $buffer[$key] = $prompt;

            if ($this->shouldFlushBuffer($buffer, $lastFlush)) {
                yield from $this->flushBuffer($buffer);
                $buffer = [];
                $lastFlush = microtime(true);
            }
        }

        // Process remaining items
        if ($buffer !== []) {
            yield from $this->flushBuffer($buffer);
        }
    }

    public function processWithPriority(array $requests): array
    {
        // Sort by priority
        usort($requests, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        $results = [];
        $highPriority = [];
        $normalPriority = [];

        foreach ($requests as $request) {
            if (($request['priority'] ?? 0) > 5) {
                $highPriority[] = $request;
            } else {
                $normalPriority[] = $request;
            }
        }

        // Process high priority immediately
        if ($highPriority !== []) {
            $results = array_merge(
                $results,
                $this->processSingleBatch(array_column($highPriority, 'prompt'))
            );
        }

        // Batch normal priority
        if ($normalPriority !== []) {
            $results = array_merge(
                $results,
                $this->processBatch(array_column($normalPriority, 'prompt'))
            );
        }

        return $results;
    }

    private function processSingleBatch(array $batch): array
    {
        $startTime = microtime(true);
        $results = [];

        // Process in parallel if possible
        $promises = [];

        foreach ($batch as $prompt) {
            $promises[] = $this->createAsyncRequest($prompt);
        }

        // Wait for all to complete
        foreach ($promises as $index => $promise) {
            $results[] = $this->resolveAsyncRequest($promise);

            $responseTime = microtime(true) - $startTime;
            $this->profiler->recordApiCall(
                $this->estimateTokens($batch[$index]),
                $responseTime
            );
        }

        return $results;
    }

    private function shouldFlushBuffer(array $buffer, float $lastFlush): bool
    {
        $timeSinceFlush = microtime(true) - $lastFlush;

        return count($buffer) >= $this->batchSize || $timeSinceFlush >= $this->batchTimeout;
    }

    private function flushBuffer(array $buffer): Generator
    {
        $results = $this->processSingleBatch($buffer);

        foreach ($results as $index => $result) {
            yield array_keys($buffer)[$index] => $result;
        }
    }

    private function createAsyncRequest(string $prompt): object
    {
        // Simplified async request creation
        // In production, use proper async HTTP client
        return (object) [
            'prompt' => $prompt,
            'startTime' => microtime(true),
        ];
    }

    private function resolveAsyncRequest(object $promise): string
    {
        // Simplified resolution
        // In production, this would resolve actual async promise
        $agent = agent()
            ->using('anthropic')
            ->withPrompt($promise->prompt);

        return $agent->generate();
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }
}
```

## Creating a High-Performance Assistant

Now let's combine all optimizations into a performant assistant:

```php
<?php

declare(strict_types=1);

namespace App\Performance;

use Pagent\Agent;
use Psr\SimpleCache\CacheInterface;

final class OptimizedAssistant
{
    private TokenOptimizer $tokenOptimizer;
    private ResponseCache $cache;
    private BatchProcessor $batchProcessor;
    private PerformanceProfiler $profiler;
    private array $conversationHistory = [];

    public function __construct(
        CacheInterface $cacheBackend,
        ?PerformanceProfiler $profiler = null
    ) {
        $this->tokenOptimizer = new TokenOptimizer();
        $this->cache = new ResponseCache($cacheBackend, $this->tokenOptimizer);
        $this->batchProcessor = new BatchProcessor();
        $this->profiler = $profiler ?? new PerformanceProfiler();
    }

    public function ask(string $prompt, array $options = []): string
    {
        $this->profiler->startProfiling();

        // Step 1: Optimize the prompt
        $optimizedPrompt = $this->tokenOptimizer->optimizePrompt(
            $prompt,
            $this->conversationHistory
        );

        // Step 2: Check cache
        $cached = $this->cache->getCachedResponse($optimizedPrompt);

        if ($cached !== null) {
            $this->profiler->recordCacheHit();
            return $cached;
        }

        $this->profiler->recordCacheMiss();

        // Step 3: Optimize conversation context
        $optimizedHistory = $this->tokenOptimizer->truncateConversation(
            $this->conversationHistory
        );

        // Step 4: Generate response with optimizations
        $response = $this->generateOptimizedResponse(
            $optimizedPrompt,
            $optimizedHistory,
            $options
        );

        // Step 5: Cache the response
        $this->cache->cacheResponse($optimizedPrompt, $response);

        // Step 6: Update history efficiently
        $this->updateHistory($prompt, $response);

        return $response;
    }

    public function askMultiple(array $prompts): array
    {
        // Use batch processing for multiple prompts
        return $this->batchProcessor->processBatch(
            array_map(
                fn($prompt) => $this->tokenOptimizer->optimizePrompt($prompt),
                $prompts
            )
        );
    }

    public function getPerformanceMetrics(): array
    {
        return $this->profiler->getReport();
    }

    private function generateOptimizedResponse(
        string $prompt,
        array $history,
        array $options
    ): string {
        $startTime = microtime(true);

        $agent = agent()
            ->using($options['provider'] ?? 'anthropic')
            ->withPrompt($prompt);

        // Add optimized history as context
        if ($history !== []) {
            $agent = $agent->withContext(
                $this->formatHistoryAsContext($history)
            );
        }

        // Use streaming for long responses
        if ($options['stream'] ?? false) {
            return $this->streamResponse($agent);
        }

        $response = $agent->generate();

        $this->profiler->recordApiCall(
            $this->tokenOptimizer->estimateTokens($prompt . $response),
            microtime(true) - $startTime
        );

        return $response;
    }

    private function formatHistoryAsContext(array $history): string
    {
        return implode("\n", array_map(
            fn($msg) => "{$msg['role']}: {$msg['content']}",
            $history
        ));
    }

    private function streamResponse(Agent $agent): string
    {
        $chunks = [];

        foreach ($agent->stream() as $chunk) {
            $chunks[] = $chunk;

            // Early termination if response is getting too long
            if (strlen(implode('', $chunks)) > 4000) {
                break;
            }
        }

        return implode('', $chunks);
    }

    private function updateHistory(string $prompt, string $response): void
    {
        $this->conversationHistory[] = ['role' => 'user', 'content' => $prompt];
        $this->conversationHistory[] = ['role' => 'assistant', 'content' => $response];

        // Keep history size manageable
        if (count($this->conversationHistory) > 20) {
            // Keep only recent messages or summarize old ones
            $this->conversationHistory = array_slice($this->conversationHistory, -10);
        }
    }
}
```

## Performance Benchmark Suite

Let's create a comprehensive benchmark to measure our optimizations:

```php
<?php

declare(strict_types=1);

namespace App\Performance;

use Psr\SimpleCache\CacheInterface;

final class PerformanceBenchmark
{
    private OptimizedAssistant $assistant;
    private array $testPrompts;

    public function __construct(CacheInterface $cache)
    {
        $this->assistant = new OptimizedAssistant($cache);
        $this->testPrompts = $this->generateTestPrompts();
    }

    public function runFullBenchmark(): array
    {
        $results = [
            'token_optimization' => $this->benchmarkTokenOptimization(),
            'cache_performance' => $this->benchmarkCaching(),
            'batch_processing' => $this->benchmarkBatching(),
            'latency' => $this->benchmarkLatency(),
        ];

        return $this->generateReport($results);
    }

    private function benchmarkTokenOptimization(): array
    {
        $optimizer = new TokenOptimizer();
        $results = [];

        foreach ($this->testPrompts as $prompt) {
            $original = strlen($prompt);
            $optimized = strlen($optimizer->optimizePrompt($prompt));

            $results[] = [
                'original_chars' => $original,
                'optimized_chars' => $optimized,
                'reduction' => (($original - $optimized) / $original) * 100,
            ];
        }

        return [
            'avg_reduction' => array_sum(array_column($results, 'reduction')) / count($results),
            'total_chars_saved' => array_sum(array_column($results, 'original_chars'))
                - array_sum(array_column($results, 'optimized_chars')),
        ];
    }

    private function benchmarkCaching(): array
    {
        $startTime = microtime(true);

        // First pass - all cache misses
        foreach ($this->testPrompts as $prompt) {
            $this->assistant->ask($prompt);
        }

        $firstPassTime = microtime(true) - $startTime;

        // Second pass - all cache hits
        $startTime = microtime(true);

        foreach ($this->testPrompts as $prompt) {
            $this->assistant->ask($prompt);
        }

        $secondPassTime = microtime(true) - $startTime;

        return [
            'first_pass_time' => $firstPassTime,
            'cached_pass_time' => $secondPassTime,
            'speedup' => $firstPassTime / $secondPassTime,
            'time_saved' => $firstPassTime - $secondPassTime,
        ];
    }

    private function benchmarkBatching(): array
    {
        // Sequential processing
        $startTime = microtime(true);

        foreach ($this->testPrompts as $prompt) {
            $this->assistant->ask($prompt);
        }

        $sequentialTime = microtime(true) - $startTime;

        // Batch processing
        $startTime = microtime(true);
        $this->assistant->askMultiple($this->testPrompts);
        $batchTime = microtime(true) - $startTime;

        return [
            'sequential_time' => $sequentialTime,
            'batch_time' => $batchTime,
            'speedup' => $sequentialTime / $batchTime,
            'time_saved' => $sequentialTime - $batchTime,
        ];
    }

    private function benchmarkLatency(): array
    {
        $latencies = [];

        foreach (array_slice($this->testPrompts, 0, 5) as $prompt) {
            $startTime = microtime(true);
            $this->assistant->ask($prompt, ['stream' => false]);
            $latencies[] = microtime(true) - $startTime;
        }

        return [
            'min_latency' => min($latencies),
            'max_latency' => max($latencies),
            'avg_latency' => array_sum($latencies) / count($latencies),
            'p95_latency' => $this->calculatePercentile($latencies, 95),
        ];
    }

    private function calculatePercentile(array $values, int $percentile): float
    {
        sort($values);
        $index = ceil(count($values) * ($percentile / 100)) - 1;

        return $values[$index];
    }

    private function generateTestPrompts(): array
    {
        return [
            "What is the capital of France?",
            "Explain quantum computing in simple terms with lots of unnecessary     whitespace",
            "List the top 10 programming languages of 2024",
            "How does photosynthesis work? Please provide a detailed explanation.",
            "What are the benefits of meditation?",
        ];
    }

    private function generateReport(array $results): array
    {
        $metrics = $this->assistant->getPerformanceMetrics();

        return [
            'summary' => [
                'total_api_calls' => $metrics['api_calls'],
                'total_tokens' => $metrics['tokens_used'],
                'cache_hit_rate' => $metrics['cache_hit_rate'] * 100 . '%',
                'avg_response_time' => round($metrics['avg_response_time'], 3) . 's',
            ],
            'optimizations' => [
                'token_savings' => round($results['token_optimization']['avg_reduction'], 1) . '%',
                'cache_speedup' => round($results['cache_performance']['speedup'], 1) . 'x',
                'batch_speedup' => round($results['batch_processing']['speedup'], 1) . 'x',
                'avg_latency' => round($results['latency']['avg_latency'], 3) . 's',
            ],
            'detailed_results' => $results,
        ];
    }
}
```

## Summary

You've learned comprehensive performance optimization techniques for AI applications. You can now optimize token usage to reduce costs, implement intelligent caching for instant responses, leverage batch processing for high-throughput operations, and profile performance to identify bottlenecks. These optimizations can reduce costs by up to 80% and improve response times by 10x or more.

## Next Steps

- Implement distributed caching with Redis
- Add request deduplication
- Build adaptive rate limiting
- Create performance monitoring dashboards
- Implement predictive pre-caching

## Additional Resources

- [OpenAI Optimization Guide](https://platform.openai.com/docs/guides/optimization)
- [Token Counting Best Practices](https://help.openai.com/en/articles/4936856)
- [Caching Strategies for AI](https://www.anthropic.com/index/caching-best-practices)
- [Batch Processing Patterns](https://aws.amazon.com/blogs/machine-learning/batch-processing-patterns/)