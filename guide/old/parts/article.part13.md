# Chapter 13: Advanced Memory Patterns

## What You'll Learn

After completing this chapter, you'll be able to:
- Build semantic memory search systems using vector embeddings
- Implement intelligent memory summarization for long conversations
- Create hierarchical memory structures for efficient retrieval
- Handle memory migrations between storage tiers
- Optimize memory performance for production systems

**Prerequisites**: Complete understanding of Chapter 12's memory management concepts

**Time Estimate**: 45 minutes

**Final Result**: A production-ready memory system with semantic search, automatic summarization, and multi-tier storage

## Understanding Advanced Memory Architecture

Traditional memory systems treat all memories equally - they're stored chronologically and retrieved sequentially. But human memory doesn't work this way. We remember important events more vividly, group related memories together, and gradually forget irrelevant details. Advanced memory patterns bring these capabilities to AI assistants.

### The Memory Hierarchy

Think of memory like a library. Recent conversations are like books on your desk - immediately accessible. Important contexts are like reference books on nearby shelves. Historical data resembles archived materials in the basement - retrievable but requiring more effort.

```php
use Pagent\Memory\Hierarchy;
use Pagent\Memory\Tier;

class MemoryHierarchy extends Hierarchy
{
    protected array $tiers = [
        'hot' => [       // Active working memory
            'capacity' => 10,
            'ttl' => 300,     // 5 minutes
            'storage' => 'memory',
        ],
        'warm' => [      // Recent context cache
            'capacity' => 100,
            'ttl' => 3600,    // 1 hour
            'storage' => 'redis',
        ],
        'cold' => [      // Long-term storage
            'capacity' => null,  // Unlimited
            'ttl' => null,       // Permanent
            'storage' => 'database',
        ],
    ];
}
```

## Building Semantic Memory Search

Semantic search transforms memories from text into mathematical representations (embeddings) that capture meaning. This enables finding relevant memories based on conceptual similarity rather than keyword matching.

### Step 1: Creating the Embedding Service

First, implement a service that converts text into vector embeddings:

```php
use OpenAI\Client;
use Pagent\Memory\Embeddings\EmbeddingService;

class OpenAIEmbeddingService implements EmbeddingService
{
    private Client $client;
    private string $model = 'text-embedding-3-small';

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function embed(string $text): array
    {
        $response = $this->client->embeddings()->create([
            'model' => $this->model,
            'input' => $text,
        ]);

        return $response->embeddings[0]->embedding;
    }

    public function embedBatch(array $texts): array
    {
        $response = $this->client->embeddings()->create([
            'model' => $this->model,
            'input' => $texts,
        ]);

        return array_map(
            fn($embedding) => $embedding->embedding,
            $response->embeddings
        );
    }

    public function similarity(array $vec1, array $vec2): float
    {
        // Cosine similarity calculation
        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;

        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] ** 2;
            $norm2 += $vec2[$i] ** 2;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }
}
```

### Step 2: Implementing Semantic Memory Store

Now create a memory store that uses embeddings for intelligent retrieval:

```php
use Pagent\Memory\SemanticMemoryStore;

class VectorMemoryStore implements SemanticMemoryStore
{
    private EmbeddingService $embeddings;
    private array $memories = [];
    private array $vectors = [];

    public function store(string $id, mixed $content, array $metadata = []): void
    {
        $text = $this->extractText($content);
        $vector = $this->embeddings->embed($text);

        $this->memories[$id] = [
            'content' => $content,
            'metadata' => $metadata,
            'timestamp' => time(),
            'access_count' => 0,
        ];

        $this->vectors[$id] = $vector;
    }

    public function search(string $query, int $limit = 5): array
    {
        $queryVector = $this->embeddings->embed($query);
        $similarities = [];

        foreach ($this->vectors as $id => $vector) {
            $similarities[$id] = $this->embeddings->similarity(
                $queryVector,
                $vector
            );
        }

        arsort($similarities);
        $topIds = array_slice(array_keys($similarities), 0, $limit);

        $results = [];
        foreach ($topIds as $id) {
            $this->memories[$id]['access_count']++;
            $results[] = [
                'memory' => $this->memories[$id],
                'similarity' => $similarities[$id],
            ];
        }

        return $results;
    }

    private function extractText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return json_encode($content);
        }

        if (is_object($content) && method_exists($content, '__toString')) {
            return (string) $content;
        }

        return serialize($content);
    }
}
```

### Step 3: Creating a Semantic Search Assistant

Combine these components into an intelligent assistant:

```php
use Pagent\Agent;

class SemanticAssistant
{
    private Agent $agent;
    private VectorMemoryStore $memory;
    private EmbeddingService $embeddings;

    public function __construct()
    {
        $this->embeddings = new OpenAIEmbeddingService(
            OpenAI::client(getenv('OPENAI_API_KEY'))
        );

        $this->memory = new VectorMemoryStore($this->embeddings);

        $this->agent = openai('gpt-4')
            ->withSystemPrompt('You are a helpful assistant with semantic memory.');
    }

    public function chat(string $message): string
    {
        // Search for relevant memories
        $relevantMemories = $this->memory->search($message, 3);

        // Build context from memories
        $context = $this->buildContext($relevantMemories);

        // Generate response with context
        $response = $this->agent
            ->withContext($context)
            ->ask($message);

        // Store the interaction
        $this->memory->store(
            uniqid('chat_'),
            [
                'user' => $message,
                'assistant' => $response,
            ],
            ['type' => 'conversation']
        );

        return $response;
    }

    private function buildContext(array $memories): string
    {
        if (empty($memories)) {
            return '';
        }

        $context = "Relevant previous conversations:\n\n";

        foreach ($memories as $memory) {
            if ($memory['similarity'] > 0.7) {  // High relevance threshold
                $content = $memory['memory']['content'];
                $context .= "- User: {$content['user']}\n";
                $context .= "  Assistant: {$content['assistant']}\n\n";
            }
        }

        return $context;
    }
}
```

## Implementing Memory Summarization

As conversations grow, maintaining full history becomes impractical. Memory summarization compresses older memories while preserving essential information.

### Automatic Summarization Pipeline

```php
use Pagent\Memory\Summarizer;

class MemorySummarizer implements Summarizer
{
    private Agent $summarizer;
    private int $chunkSize = 10;
    private float $compressionRatio = 0.3;

    public function __construct()
    {
        $this->summarizer = anthropic('claude-3-haiku')
            ->withSystemPrompt(
                'Summarize conversations preserving key facts, decisions, and context. ' .
                'Be concise but retain all important information.'
            );
    }

    public function summarize(array $memories): array
    {
        $chunks = array_chunk($memories, $this->chunkSize);
        $summaries = [];

        foreach ($chunks as $chunk) {
            $text = $this->formatMemories($chunk);

            $summary = $this->summarizer->ask(
                "Summarize these conversations into key points:\n\n{$text}"
            );

            $summaries[] = [
                'summary' => $summary,
                'original_count' => count($chunk),
                'timestamp' => time(),
                'ids' => array_column($chunk, 'id'),
            ];
        }

        return $summaries;
    }

    public function shouldSummarize(array $memories): bool
    {
        // Summarize when memories exceed threshold
        if (count($memories) < 50) {
            return false;
        }

        // Check memory age
        $oldestMemory = min(array_column($memories, 'timestamp'));
        $ageInHours = (time() - $oldestMemory) / 3600;

        return $ageInHours > 24;  // Summarize daily
    }

    private function formatMemories(array $memories): string
    {
        $formatted = [];

        foreach ($memories as $memory) {
            $formatted[] = sprintf(
                "[%s] User: %s\nAssistant: %s",
                date('Y-m-d H:i', $memory['timestamp']),
                $memory['content']['user'],
                $memory['content']['assistant']
            );
        }

        return implode("\n---\n", $formatted);
    }
}
```

### Progressive Summarization Strategy

Implement a multi-level summarization system that progressively compresses memories:

```php
class ProgressiveSummarizer
{
    private array $levels = [
        'detail' => ['age' => 1, 'ratio' => 1.0],     // Full detail for 1 day
        'summary' => ['age' => 7, 'ratio' => 0.5],    // 50% compression after a week
        'abstract' => ['age' => 30, 'ratio' => 0.2],  // 80% compression after a month
        'essence' => ['age' => 365, 'ratio' => 0.05], // 95% compression after a year
    ];

    public function process(array $memories): array
    {
        $processed = [];
        $now = time();

        foreach ($memories as $memory) {
            $ageInDays = ($now - $memory['timestamp']) / 86400;
            $level = $this->determineLevel($ageInDays);

            if ($memory['level'] !== $level) {
                $processed[] = $this->compressToLevel($memory, $level);
            } else {
                $processed[] = $memory;
            }
        }

        return $processed;
    }

    private function determineLevel(float $ageInDays): string
    {
        foreach (array_reverse($this->levels) as $level => $config) {
            if ($ageInDays >= $config['age']) {
                return $level;
            }
        }

        return 'detail';
    }

    private function compressToLevel(array $memory, string $targetLevel): array
    {
        $compressionRatio = $this->levels[$targetLevel]['ratio'];

        // Use different compression strategies based on level
        switch ($targetLevel) {
            case 'summary':
                $compressed = $this->extractKeyPoints($memory['content']);
                break;

            case 'abstract':
                $compressed = $this->generateAbstract($memory['content']);
                break;

            case 'essence':
                $compressed = $this->distillEssence($memory['content']);
                break;

            default:
                $compressed = $memory['content'];
        }

        return [
            ...$memory,
            'level' => $targetLevel,
            'content' => $compressed,
            'original_size' => strlen(json_encode($memory['content'])),
            'compressed_size' => strlen(json_encode($compressed)),
        ];
    }
}
```

## Creating Memory Hierarchies

Build a multi-tier memory system that automatically promotes and demotes memories based on usage patterns:

```php
class HierarchicalMemoryManager
{
    private array $tiers;
    private array $policies;

    public function __construct()
    {
        $this->initializeTiers();
        $this->initializePolicies();
    }

    private function initializeTiers(): void
    {
        $this->tiers = [
            'working' => new WorkingMemory(capacity: 10),
            'active' => new ActiveMemory(capacity: 100),
            'archive' => new ArchiveMemory(capacity: null),
        ];
    }

    private function initializePolicies(): void
    {
        $this->policies = [
            new RecencyPolicy(threshold: 300),      // 5 minutes
            new FrequencyPolicy(threshold: 5),      // 5 accesses
            new ImportancePolicy(threshold: 0.8),   // Importance score
        ];
    }

    public function store(string $id, mixed $content, array $metadata = []): void
    {
        // Start in working memory
        $this->tiers['working']->store($id, $content, $metadata);

        // Check for overflow and cascade
        $this->rebalance();
    }

    public function retrieve(string $query): ?array
    {
        // Search tiers in order
        foreach ($this->tiers as $tier) {
            if ($result = $tier->search($query)) {
                $this->updateAccessMetrics($result);
                $this->considerPromotion($result);
                return $result;
            }
        }

        return null;
    }

    private function rebalance(): void
    {
        foreach ($this->tiers as $name => $tier) {
            if ($tier->isOverCapacity()) {
                $victims = $tier->selectEvictionCandidates();
                $nextTier = $this->getNextTier($name);

                foreach ($victims as $victim) {
                    $tier->evict($victim['id']);
                    $nextTier?->store(
                        $victim['id'],
                        $victim['content'],
                        $victim['metadata']
                    );
                }
            }
        }
    }

    private function considerPromotion(array $memory): void
    {
        foreach ($this->policies as $policy) {
            if ($policy->shouldPromote($memory)) {
                $this->promote($memory);
                break;
            }
        }
    }
}
```

## Handling Memory Migrations

Implement strategies for migrating memories between storage systems:

```php
class MemoryMigrator
{
    private array $adapters;
    private Logger $logger;

    public function migrate(
        string $source,
        string $destination,
        array $criteria = []
    ): MigrationResult {
        $sourceAdapter = $this->adapters[$source];
        $destAdapter = $this->adapters[$destination];

        $result = new MigrationResult();
        $memories = $sourceAdapter->query($criteria);

        foreach ($memories as $memory) {
            try {
                // Transform if needed
                $transformed = $this->transform($memory, $source, $destination);

                // Store in destination
                $destAdapter->store($transformed);

                // Verify migration
                if ($this->verify($memory, $transformed)) {
                    $sourceAdapter->delete($memory['id']);
                    $result->addSuccess($memory['id']);
                } else {
                    $result->addFailure($memory['id'], 'Verification failed');
                }

            } catch (Exception $e) {
                $result->addFailure($memory['id'], $e->getMessage());
                $this->logger->error('Migration failed', [
                    'memory' => $memory['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function transform(
        array $memory,
        string $source,
        string $destination
    ): array {
        // Apply transformation rules based on source and destination
        $transformers = [
            'redis->database' => fn($m) => $this->expandMetadata($m),
            'database->redis' => fn($m) => $this->compressContent($m),
            'memory->file' => fn($m) => $this->serializeForFile($m),
        ];

        $key = "{$source}->{$destination}";

        if (isset($transformers[$key])) {
            return $transformers[$key]($memory);
        }

        return $memory;
    }
}
```

## Optimizing Memory Performance

### Memory Analytics Dashboard

Build a system to monitor and optimize memory usage:

```php
class MemoryAnalytics
{
    private MetricsCollector $metrics;

    public function analyze(): array
    {
        return [
            'usage' => $this->analyzeUsage(),
            'performance' => $this->analyzePerformance(),
            'patterns' => $this->analyzePatterns(),
            'recommendations' => $this->generateRecommendations(),
        ];
    }

    private function analyzeUsage(): array
    {
        return [
            'total_memories' => $this->metrics->count('memories'),
            'by_tier' => $this->metrics->groupBy('tier'),
            'by_age' => $this->metrics->histogram('age', 'days'),
            'compression_ratio' => $this->metrics->average('compression'),
        ];
    }

    private function analyzePerformance(): array
    {
        return [
            'avg_retrieval_time' => $this->metrics->percentile('retrieval_ms', 50),
            'p95_retrieval_time' => $this->metrics->percentile('retrieval_ms', 95),
            'cache_hit_rate' => $this->metrics->ratio('cache_hits', 'total_requests'),
            'promotion_rate' => $this->metrics->rate('promotions', 'hour'),
        ];
    }

    private function analyzePatterns(): array
    {
        return [
            'access_frequency' => $this->metrics->timeSeries('accesses', '1h'),
            'popular_queries' => $this->metrics->topK('queries', 10),
            'memory_growth' => $this->metrics->trend('memory_count', '7d'),
        ];
    }

    private function generateRecommendations(): array
    {
        $recommendations = [];

        if ($this->metrics->average('retrieval_ms') > 100) {
            $recommendations[] = 'Consider increasing cache size';
        }

        if ($this->metrics->ratio('archive', 'total') > 0.8) {
            $recommendations[] = 'Archive tier is large - implement compression';
        }

        return $recommendations;
    }
}
```

## Summary

You've learned to build sophisticated memory systems that go beyond simple storage and retrieval. Your assistants can now:

- Find relevant memories through semantic search rather than keyword matching
- Automatically summarize conversations to manage growing memory stores
- Organize memories in hierarchical tiers for optimal performance
- Migrate memories between storage systems seamlessly
- Monitor and optimize memory usage with analytics

These advanced patterns enable building production-ready assistants that maintain context over extended periods while remaining performant and cost-effective.

## Next Steps

In Chapter 14, we'll explore Testing and Validation strategies to ensure your Pagent assistants behave reliably and predictably in production environments.

## Additional Resources

- [OpenAI Embeddings Documentation](https://platform.openai.com/docs/guides/embeddings)
- [Vector Database Comparisons](https://github.com/erikbern/ann-benchmarks)
- [Memory Management Best Practices](https://arxiv.org/abs/2304.03442)
- [Hierarchical Memory Networks](https://arxiv.org/abs/2016.01273)