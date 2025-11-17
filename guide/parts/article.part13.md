# Chapter 13: Advanced Memory Patterns

In the previous chapter, we explored basic memory persistence with SQLite and file-based storage. While those patterns work well for straightforward conversation history, real-world applications often demand more sophisticated memory management. What happens when conversations grow to thousands of messages? How do you implement semantic search across conversation history? What about hierarchical memory systems that summarize old context while preserving important details?

This chapter explores advanced memory patterns in Pagent, from token-aware context management to custom memory adapters that enable semantic search and multi-tier storage. We'll examine what's built into the framework and what requires custom implementation, giving you the tools to build sophisticated memory systems for production applications.

## Context Window Management

The most immediate memory challenge you'll face is context window limits. LLM providers impose maximum token limits - typically 4,000 to 128,000 tokens depending on the model. A long conversation can easily exceed these limits, causing API errors or degraded performance as the context grows stale.

Pagent's `ContextManager` provides automatic pruning to keep conversations within token budgets:

```php
$agent = agent('support-bot')
    ->provider(anthropic())
    ->model('claude-sonnet-4-20250514')
    ->memory('sqlite', ['path' => 'support.db'])
    ->sessionId('ticket-12345')
    ->contextWindow(4000, 'oldest')  // Max 4000 tokens, remove oldest first
    ->build();

// Over many turns, conversation history grows...
$agent->prompt('What was my original issue?');
$agent->prompt('And what did you suggest?');
$agent->prompt('Can you clarify the third step?');

// Context manager automatically prunes to stay under 4000 tokens
```

The `contextWindow()` method accepts two parameters: maximum tokens and pruning strategy. It creates a `ContextManager` instance that automatically prunes messages before each LLM call.

### Pruning Strategies

Pagent implements two built-in pruning strategies:

**Oldest Strategy** (`'oldest'`): Removes the oldest messages first, preserving recent context. System messages are always preserved. This works well for support conversations where recent context matters most:

```php
$agent->contextWindow(4000, 'oldest');

// If conversation exceeds 4000 tokens:
// 1. System message kept
// 2. Oldest user/assistant pairs removed
// 3. Recent messages preserved
```

**Sliding Window** (`'sliding'`): Keeps the most recent messages that fit within the token limit, creating a sliding window over the conversation. System messages are preserved, and the window slides backward from the most recent message:

```php
$agent->contextWindow(4000, 'sliding');

// If conversation exceeds 4000 tokens:
// 1. System message kept
// 2. Keep most recent messages that fit in 4000 tokens
// 3. Everything else dropped
```

### How Context Pruning Works

Understanding the pruning flow helps you design effective memory strategies. Here's what happens on each `prompt()` call:

1. **Load from Memory**: If conversation history is empty and a session ID exists, load messages from persistent memory
2. **Add User Message**: Append the new user message to history
3. **Apply Context Pruning**: If `contextWindow()` is configured, prune messages to fit within token limit
4. **Send to LLM**: Send pruned messages to the provider
5. **Save Full History**: Save the complete (unpruned) conversation history back to memory

This design ensures your persistent storage contains the full conversation history while the LLM only sees a pruned subset. You can later analyze the complete history, implement custom summarization, or use different pruning strategies without losing data.

### Token Counting

The `ContextManager` estimates token counts using a simple heuristic: 4 characters per token. While not perfectly accurate (actual tokenization varies by model), this approximation works well for pruning decisions:

```php
use Pagent\Memory\ContextManager;

$manager = new ContextManager(maxTokens: 4000, strategy: 'oldest');

$messages = [
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'Hello!'],
    ['role' => 'assistant', 'content' => 'Hi there! How can I help you today?'],
];

$estimatedTokens = $manager->countTokens($messages);
echo "Estimated tokens: $estimatedTokens\n";

// Prune if needed
$pruned = $manager->prune($messages);
```

For more accurate token counting, you can integrate provider-specific tokenizers (like `tiktoken` for OpenAI models) in a custom memory adapter.

## Memory Compression and Summarization

Context pruning solves immediate token limits but loses information. For applications that need long-term memory - customer support bots, personal assistants, research tools - you need compression strategies that preserve semantic meaning while reducing token count.

Pagent doesn't include automatic summarization, but you can implement it using the LLM itself:

```php
class SummarizingMemory implements Memory
{
    public function __construct(
        private Memory $baseMemory,
        private Agent $summarizerAgent,
        private int $summaryThreshold = 20
    ) {}

    public function load(string $sessionId): array
    {
        $messages = $this->baseMemory->load($sessionId);

        // Check if we need summarization
        if (count($messages) > $this->summaryThreshold) {
            $messages = $this->summarizeOldMessages($sessionId, $messages);
        }

        return $messages;
    }

    public function save(string $sessionId, array $messages): void
    {
        $this->baseMemory->save($sessionId, $messages);
    }

    private function summarizeOldMessages(string $sessionId, array $messages): array
    {
        // Keep system message and recent messages
        $systemMessage = null;
        $recentMessages = [];
        $oldMessages = [];

        foreach ($messages as $index => $message) {
            if ($message['role'] === 'system' && $systemMessage === null) {
                $systemMessage = $message;
            } elseif ($index >= count($messages) - 10) {
                $recentMessages[] = $message;
            } else {
                $oldMessages[] = $message;
            }
        }

        if (empty($oldMessages)) {
            return $messages;
        }

        // Build summary of old messages
        $conversationText = $this->formatMessagesForSummary($oldMessages);

        $summary = $this->summarizerAgent->prompt(
            "Summarize this conversation history in 3-5 bullet points, preserving key facts and decisions:\n\n"
            . $conversationText
        );

        // Create summary message
        $summaryMessage = [
            'role' => 'system',
            'content' => "Previous conversation summary:\n" . $summary
        ];

        // Rebuild message list: system + summary + recent messages
        $result = [];
        if ($systemMessage) {
            $result[] = $systemMessage;
        }
        $result[] = $summaryMessage;
        $result = array_merge($result, $recentMessages);

        // Save compressed version
        $this->baseMemory->save($sessionId, $result);

        return $result;
    }

    private function formatMessagesForSummary(array $messages): string
    {
        $formatted = '';
        foreach ($messages as $message) {
            $role = strtoupper($message['role']);
            $content = is_string($message['content'])
                ? $message['content']
                : json_encode($message['content']);
            $formatted .= "$role: $content\n\n";
        }
        return $formatted;
    }

    public function delete(string $sessionId): void
    {
        $this->baseMemory->delete($sessionId);
    }

    public function exists(string $sessionId): bool
    {
        return $this->baseMemory->exists($sessionId);
    }

    public function prune(string $sessionId, int $maxMessages): array
    {
        return $this->baseMemory->prune($sessionId, $maxMessages);
    }
}
```

Using this wrapper:

```php
// Create summarizer agent (separate from main agent)
$summarizer = agent('summarizer')
    ->provider(anthropic())
    ->model('claude-haiku-3-5-20250514')  // Fast, cheap model for summaries
    ->build();

// Wrap base memory with summarization
$memory = new SummarizingMemory(
    baseMemory: new SqliteAdapter(['path' => 'conversations.db']),
    summarizerAgent: $summarizer,
    summaryThreshold: 20
);

// Use with main agent
$agent = agent('assistant')
    ->provider(anthropic())
    ->memory($memory)
    ->sessionId('user-123')
    ->build();

// After 20+ messages, old context automatically compressed to summary
```

This pattern keeps recent context intact while compressing older messages. The LLM still has access to important information from early conversation, but in a token-efficient format.

## Semantic Memory Search

Standard memory implementations retrieve entire conversation histories - a linear list of messages. But what if you need to search conversations semantically? "What did the user say about pricing?" or "Find all conversations where the customer mentioned bugs."

Pagent doesn't include vector embeddings or semantic search, but you can integrate external vector databases through custom memory adapters. Here's the conceptual approach:

```php
class SemanticMemory implements Memory
{
    public function __construct(
        private Memory $baseMemory,
        private VectorDatabase $vectorDb,  // e.g., Pinecone, Weaviate, Qdrant
        private EmbeddingService $embeddings  // e.g., OpenAI embeddings API
    ) {}

    public function save(string $sessionId, array $messages): void
    {
        // Save to base storage
        $this->baseMemory->save($sessionId, $messages);

        // Index new messages in vector database
        foreach ($messages as $index => $message) {
            if (isset($message['content']) && is_string($message['content'])) {
                $embedding = $this->embeddings->embed($message['content']);

                $this->vectorDb->upsert([
                    'id' => "$sessionId:$index",
                    'vector' => $embedding,
                    'metadata' => [
                        'session_id' => $sessionId,
                        'role' => $message['role'],
                        'content' => $message['content'],
                        'index' => $index,
                    ]
                ]);
            }
        }
    }

    public function searchSemantic(string $query, int $limit = 5): array
    {
        // Generate embedding for query
        $queryEmbedding = $this->embeddings->embed($query);

        // Search vector database
        $results = $this->vectorDb->query(
            vector: $queryEmbedding,
            limit: $limit
        );

        // Return matched messages with similarity scores
        return array_map(fn($result) => [
            'session_id' => $result['metadata']['session_id'],
            'role' => $result['metadata']['role'],
            'content' => $result['metadata']['content'],
            'similarity' => $result['score']
        ], $results);
    }

    // Standard Memory interface methods...
    public function load(string $sessionId): array
    {
        return $this->baseMemory->load($sessionId);
    }

    public function delete(string $sessionId): void
    {
        $this->baseMemory->delete($sessionId);
        // Also delete from vector database
        $this->vectorDb->deleteBySessionId($sessionId);
    }

    public function exists(string $sessionId): bool
    {
        return $this->baseMemory->exists($sessionId);
    }

    public function prune(string $sessionId, int $maxMessages): array
    {
        return $this->baseMemory->prune($sessionId, $maxMessages);
    }
}
```

This pattern maintains two storage layers: traditional message storage for conversation continuity and vector embeddings for semantic search. You can query across all conversations or within specific sessions:

```php
// Find relevant context across all conversations
$relevant = $memory->searchSemantic('pricing information', limit: 10);

foreach ($relevant as $match) {
    echo "Session: {$match['session_id']}\n";
    echo "Similarity: " . round($match['similarity'], 2) . "\n";
    echo "Content: {$match['content']}\n\n";
}
```

For production implementations, consider using established vector databases like Pinecone, Weaviate, Qdrant, or even PostgreSQL with the `pgvector` extension. Each offers different trade-offs in performance, cost, and feature sets.

## Hierarchical Memory Systems

Some applications benefit from multiple memory tiers - hot storage for recent conversations, warm storage for archived sessions, and cold storage for long-term analytics. You can implement this pattern by composing memory adapters:

```php
class TieredMemory implements Memory
{
    public function __construct(
        private Memory $hotStorage,    // Fast, limited capacity (e.g., Redis)
        private Memory $warmStorage,   // Medium speed (e.g., SQLite)
        private Memory $coldStorage,   // Slow, unlimited (e.g., S3, PostgreSQL)
        private int $hotThreshold = 100,
        private int $warmThreshold = 1000
    ) {}

    public function load(string $sessionId): array
    {
        // Try hot storage first
        if ($this->hotStorage->exists($sessionId)) {
            return $this->hotStorage->load($sessionId);
        }

        // Try warm storage
        if ($this->warmStorage->exists($sessionId)) {
            $messages = $this->warmStorage->load($sessionId);
            // Promote to hot storage
            $this->hotStorage->save($sessionId, $messages);
            return $messages;
        }

        // Try cold storage
        if ($this->coldStorage->exists($sessionId)) {
            $messages = $this->coldStorage->load($sessionId);
            // Promote to warm storage (not hot - cold sessions stay cold)
            $this->warmStorage->save($sessionId, $messages);
            return $messages;
        }

        return [];
    }

    public function save(string $sessionId, array $messages): void
    {
        $messageCount = count($messages);

        if ($messageCount < $this->hotThreshold) {
            // Active conversation - hot storage
            $this->hotStorage->save($sessionId, $messages);
        } elseif ($messageCount < $this->warmThreshold) {
            // Moderate activity - warm storage
            $this->warmStorage->save($sessionId, $messages);
            // Remove from hot if present
            if ($this->hotStorage->exists($sessionId)) {
                $this->hotStorage->delete($sessionId);
            }
        } else {
            // Long conversation - cold storage
            $this->coldStorage->save($sessionId, $messages);
            // Remove from other tiers
            if ($this->hotStorage->exists($sessionId)) {
                $this->hotStorage->delete($sessionId);
            }
            if ($this->warmStorage->exists($sessionId)) {
                $this->warmStorage->delete($sessionId);
            }
        }
    }

    public function delete(string $sessionId): void
    {
        $this->hotStorage->delete($sessionId);
        $this->warmStorage->delete($sessionId);
        $this->coldStorage->delete($sessionId);
    }

    public function exists(string $sessionId): bool
    {
        return $this->hotStorage->exists($sessionId)
            || $this->warmStorage->exists($sessionId)
            || $this->coldStorage->exists($sessionId);
    }

    public function prune(string $sessionId, int $maxMessages): array
    {
        // Load from any tier
        $messages = $this->load($sessionId);

        if (count($messages) <= $maxMessages) {
            return $messages;
        }

        // Prune and save
        $pruned = array_slice($messages, -$maxMessages);
        $this->save($sessionId, $pruned);

        return $pruned;
    }
}
```

This approach balances performance and cost. Active conversations live in fast storage, while inactive or lengthy conversations migrate to cheaper storage tiers.

## Memory Migration Patterns

As your application evolves, you may need to migrate conversations between storage backends or upgrade schema formats. Pagent's `Memory` interface makes this straightforward:

```php
class MemoryMigrator
{
    public function migrate(
        Memory $source,
        Memory $destination,
        array $sessionIds
    ): array {
        $stats = [
            'migrated' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($sessionIds as $sessionId) {
            try {
                if (!$source->exists($sessionId)) {
                    continue;
                }

                $messages = $source->load($sessionId);
                $destination->save($sessionId, $messages);

                $stats['migrated']++;
            } catch (Exception $e) {
                $stats['failed']++;
                $stats['errors'][$sessionId] = $e->getMessage();
            }
        }

        return $stats;
    }

    public function migrateAllSessions(
        Memory $source,
        Memory $destination,
        callable $sessionIdProvider
    ): array {
        $sessionIds = $sessionIdProvider();
        return $this->migrate($source, $destination, $sessionIds);
    }
}
```

Example usage:

```php
$migrator = new MemoryMigrator();

// Migrate from file storage to SQLite
$fileMemory = new FileAdapter(['path' => 'storage/sessions']);
$sqliteMemory = new SqliteAdapter(['path' => 'conversations.db']);

// Get all session IDs from file storage
$sessionIds = array_map(
    fn($file) => basename($file, '.json'),
    glob('storage/sessions/*.json')
);

// Perform migration
$stats = $migrator->migrate($fileMemory, $sqliteMemory, $sessionIds);

echo "Migrated: {$stats['migrated']}\n";
echo "Failed: {$stats['failed']}\n";
```

## Performance Optimization

For high-throughput applications, memory operations can become bottlenecks. Consider these optimization patterns:

**Lazy Loading**: Only load conversation history when needed, not on every agent instantiation:

```php
// Don't load history until first prompt
$agent = agent('assistant')
    ->provider(anthropic())
    ->memory('sqlite', ['path' => 'conversations.db'])
    ->sessionId('user-123')
    ->build();

// History loads on first prompt() call
$response = $agent->prompt('Hello');  // Triggers load
```

**Batch Operations**: When processing multiple sessions, use connection pooling and batch queries in your custom adapter:

```php
class BatchSqliteAdapter extends SqliteAdapter
{
    public function loadBatch(array $sessionIds): array
    {
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT session_id, messages FROM sessions WHERE session_id IN ($placeholders)"
        );
        $stmt->execute($sessionIds);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['session_id']] = json_decode($row['messages'], true);
        }

        return $results;
    }
}
```

**Caching**: Add a caching layer for frequently accessed sessions:

```php
class CachedMemory implements Memory
{
    private array $cache = [];

    public function __construct(
        private Memory $baseMemory,
        private int $cacheSize = 100
    ) {}

    public function load(string $sessionId): array
    {
        if (isset($this->cache[$sessionId])) {
            return $this->cache[$sessionId];
        }

        $messages = $this->baseMemory->load($sessionId);

        // Simple LRU: if cache full, remove oldest
        if (count($this->cache) >= $this->cacheSize) {
            array_shift($this->cache);
        }

        $this->cache[$sessionId] = $messages;
        return $messages;
    }

    public function save(string $sessionId, array $messages): void
    {
        $this->baseMemory->save($sessionId, $messages);
        $this->cache[$sessionId] = $messages;
    }

    // Implement other Memory methods...
}
```

## Bringing It Together

Advanced memory patterns enable sophisticated conversation systems. Here's a complete example combining multiple techniques:

```php
// Create base storage
$sqliteStorage = new SqliteAdapter(['path' => 'conversations.db']);

// Add caching
$cachedStorage = new CachedMemory($sqliteStorage, cacheSize: 200);

// Add summarization for long conversations
$summarizer = agent('summarizer')
    ->provider(anthropic())
    ->model('claude-haiku-3-5-20250514')
    ->build();

$summarizingMemory = new SummarizingMemory(
    baseMemory: $cachedStorage,
    summarizerAgent: $summarizer,
    summaryThreshold: 30
);

// Create agent with context window management
$agent = agent('support-bot')
    ->provider(anthropic())
    ->model('claude-sonnet-4-20250514')
    ->memory($summarizingMemory)
    ->sessionId('ticket-789')
    ->contextWindow(8000, 'sliding')
    ->build();

// Handle long conversation efficiently
// - Context window keeps LLM calls under 8000 tokens
// - Summarization compresses history after 30 messages
// - Cache reduces database load
// - Full history preserved in SQLite
$response = $agent->prompt('What was the original issue reported?');
```

This architecture scales from dozens to thousands of concurrent conversations while maintaining performance and controlling costs.

## What's Next

In this chapter, we've explored advanced memory patterns - from context window management to semantic search and hierarchical storage. You've learned how to build custom memory adapters, implement compression strategies, and optimize performance for production workloads.

The next chapter moves to safety and reliability. We'll examine Pagent's guard system for detecting PII, filtering content, and protecting against prompt injection attacks - essential features for deploying LLM applications in production.
