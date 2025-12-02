# Chapter 12: Memory Systems

## What You'll Learn

By the end of this chapter, you'll be able to:

- Implement persistent conversation memory for your agents
- Use SQLite and file-based storage adapters
- Query and search through historical conversations
- Manage memory lifecycle and implement pruning strategies
- Build agents that learn and improve from past interactions

## Prerequisites

- Completed Chapters 1-5 of the Pagent tutorial
- Basic understanding of SQL (for SQLite adapter)
- PHP 8.3+ environment with SQLite extension
- Familiarity with Pagent's core agent concepts

## Time Estimate

45-60 minutes for complete implementation and exercises

## Final Result

You'll build four memory-enabled agents:

1. A personal assistant that remembers user preferences
2. A learning system that improves responses over time
3. A context-aware support bot
4. A knowledge accumulator that builds expertise

---

## Part 1: Understanding Memory Systems

### Why Memory Matters

Agents without memory restart from zero with each interaction. This creates frustrating experiences where users must repeat context, preferences get forgotten, and learning never occurs. Memory systems transform stateless agents into stateful assistants that build relationships and improve over time.

Think of memory as your agent's journal - it records conversations, tracks patterns, and enables continuity across sessions. Just as you wouldn't want a colleague who forgets everything after each meeting, users expect agents to remember and learn.

### Memory Architecture

Pagent's memory system follows a simple but powerful pattern:

```php
interface MemoryAdapter
{
    public function store(Conversation $conversation): void;
    public function retrieve(string $id): ?Conversation;
    public function search(array $criteria): array;
    public function prune(array $criteria): int;
}
```

This interface enables different storage backends while maintaining consistent behavior. Your agent doesn't care whether memories are stored in SQLite, files, or a remote database - it just knows it can store, retrieve, search, and prune.

---

## Part 2: Implementing the SQLite Adapter

### Setting Up SQLite Storage

Let's create a SQLite memory adapter that provides fast, reliable storage with powerful querying capabilities:

```php
<?php

declare(strict_types=1);

namespace Pagent\Memory;

use PDO;
use Pagent\Contracts\MemoryAdapter;
use Pagent\Conversation;
use Pagent\Message;

final class SQLiteAdapter implements MemoryAdapter
{
    private PDO $db;

    public function __construct(string $path = ':memory:')
    {
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initializeSchema();
    }

    private function initializeSchema(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS conversations (
                id TEXT PRIMARY KEY,
                agent_id TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                metadata TEXT
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id TEXT NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                metadata TEXT,
                FOREIGN KEY (conversation_id)
                    REFERENCES conversations(id) ON DELETE CASCADE
            )
        ');

        // Indexes for common queries
        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_conversations_agent
            ON conversations(agent_id)
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_messages_conversation
            ON messages(conversation_id)
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_messages_content
            ON messages(content)
        ');
    }

    public function store(Conversation $conversation): void
    {
        $this->db->beginTransaction();

        try {
            // Store or update conversation
            $stmt = $this->db->prepare('
                INSERT INTO conversations (id, agent_id, created_at, updated_at, metadata)
                VALUES (:id, :agent_id, :created_at, :updated_at, :metadata)
                ON CONFLICT(id) DO UPDATE SET
                    updated_at = :updated_at,
                    metadata = :metadata
            ');

            $stmt->execute([
                'id' => $conversation->getId(),
                'agent_id' => $conversation->getAgentId(),
                'created_at' => time(),
                'updated_at' => time(),
                'metadata' => json_encode($conversation->getMetadata()),
            ]);

            // Store messages
            foreach ($conversation->getMessages() as $message) {
                $this->storeMessage($conversation->getId(), $message);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function storeMessage(string $conversationId, Message $message): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO messages (conversation_id, role, content, timestamp, metadata)
            VALUES (:conversation_id, :role, :content, :timestamp, :metadata)
        ');

        $stmt->execute([
            'conversation_id' => $conversationId,
            'role' => $message->getRole(),
            'content' => $message->getContent(),
            'timestamp' => $message->getTimestamp() ?? time(),
            'metadata' => json_encode($message->getMetadata()),
        ]);
    }

    public function retrieve(string $id): ?Conversation
    {
        $stmt = $this->db->prepare('
            SELECT * FROM conversations WHERE id = :id
        ');
        $stmt->execute(['id' => $id]);
        $conversationData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conversationData) {
            return null;
        }

        // Retrieve messages
        $stmt = $this->db->prepare('
            SELECT * FROM messages
            WHERE conversation_id = :id
            ORDER BY timestamp ASC
        ');
        $stmt->execute(['id' => $id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateConversation($conversationData, $messages);
    }

    public function search(array $criteria): array
    {
        $query = 'SELECT DISTINCT c.* FROM conversations c';
        $joins = [];
        $where = [];
        $params = [];

        // Search by content
        if (isset($criteria['content'])) {
            $joins[] = 'JOIN messages m ON c.id = m.conversation_id';
            $where[] = 'm.content LIKE :content';
            $params['content'] = '%' . $criteria['content'] . '%';
        }

        // Filter by agent
        if (isset($criteria['agent_id'])) {
            $where[] = 'c.agent_id = :agent_id';
            $params['agent_id'] = $criteria['agent_id'];
        }

        // Date range filter
        if (isset($criteria['since'])) {
            $where[] = 'c.updated_at >= :since';
            $params['since'] = $criteria['since'];
        }

        // Build final query
        if ($joins) {
            $query .= ' ' . implode(' ', $joins);
        }
        if ($where) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }

        $query .= ' ORDER BY c.updated_at DESC';

        if (isset($criteria['limit'])) {
            $query .= ' LIMIT :limit';
            $params['limit'] = $criteria['limit'];
        }

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $conversations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $conversation = $this->retrieve($row['id']);
            if ($conversation) {
                $conversations[] = $conversation;
            }
        }

        return $conversations;
    }

    public function prune(array $criteria): int
    {
        $where = [];
        $params = [];

        // Prune old conversations
        if (isset($criteria['older_than'])) {
            $where[] = 'updated_at < :older_than';
            $params['older_than'] = $criteria['older_than'];
        }

        // Prune by agent
        if (isset($criteria['agent_id'])) {
            $where[] = 'agent_id = :agent_id';
            $params['agent_id'] = $criteria['agent_id'];
        }

        if (empty($where)) {
            throw new \InvalidArgumentException('Pruning requires at least one criterion');
        }

        $query = 'DELETE FROM conversations WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    private function hydrateConversation(array $data, array $messages): Conversation
    {
        $conversation = new Conversation(
            $data['id'],
            $data['agent_id'],
            json_decode($data['metadata'], true) ?? []
        );

        foreach ($messages as $messageData) {
            $conversation->addMessage(new Message(
                $messageData['role'],
                $messageData['content'],
                json_decode($messageData['metadata'], true) ?? []
            ));
        }

        return $conversation;
    }
}
```

---

## Part 3: Building a Personal Assistant with Memory

Now let's create a personal assistant that remembers user preferences and past interactions:

```php
<?php

declare(strict_types=1);

namespace App\Agents;

use Pagent\Agent;
use Pagent\Memory\SQLiteAdapter;

final class PersonalAssistant
{
    private Agent $agent;
    private SQLiteAdapter $memory;
    private string $userId;

    public function __construct(string $userId)
    {
        $this->userId = $userId;
        $this->memory = new SQLiteAdapter('assistant_' . $userId . '.db');

        $this->agent = agent()
            ->withSystemPrompt($this->buildSystemPrompt())
            ->withMemory($this->memory);
    }

    private function buildSystemPrompt(): string
    {
        // Load user preferences from memory
        $preferences = $this->loadPreferences();

        return "You are a personal assistant for user {$this->userId}. " .
               "Remember and use these preferences: " . json_encode($preferences) .
               "\n\nAlways be helpful, proactive, and personalized.";
    }

    private function loadPreferences(): array
    {
        // Search for preference conversations
        $conversations = $this->memory->search([
            'agent_id' => 'assistant_' . $this->userId,
            'content' => 'preference:',
            'limit' => 10,
        ]);

        $preferences = [];
        foreach ($conversations as $conversation) {
            foreach ($conversation->getMessages() as $message) {
                if (str_contains($message->getContent(), 'preference:')) {
                    // Extract preference from message
                    preg_match('/preference:\s*(\w+)\s*=\s*(.+)/',
                              $message->getContent(), $matches);
                    if (count($matches) === 3) {
                        $preferences[$matches[1]] = $matches[2];
                    }
                }
            }
        }

        return $preferences;
    }

    public function chat(string $message): string
    {
        // Check for similar past conversations
        $context = $this->findRelevantContext($message);

        if ($context) {
            $this->agent->withContext("Previous related conversation:\n" . $context);
        }

        $response = $this->agent->ask($message);

        // Extract and store any new preferences
        if (str_contains(strtolower($message), 'prefer') ||
            str_contains(strtolower($message), 'like')) {
            $this->extractAndStorePreference($message);
        }

        return $response;
    }

    private function findRelevantContext(string $message): ?string
    {
        $similar = $this->memory->search([
            'agent_id' => 'assistant_' . $this->userId,
            'content' => $this->extractKeywords($message),
            'limit' => 3,
        ]);

        if (empty($similar)) {
            return null;
        }

        // Format context from similar conversations
        $context = [];
        foreach ($similar as $conversation) {
            $summary = $this->summarizeConversation($conversation);
            if ($summary) {
                $context[] = $summary;
            }
        }

        return implode("\n---\n", $context);
    }

    private function extractKeywords(string $text): string
    {
        // Simple keyword extraction - in production, use NLP
        $words = str_word_count(strtolower($text), 1);
        $stopwords = ['the', 'is', 'at', 'which', 'on', 'and', 'a', 'an'];
        $keywords = array_diff($words, $stopwords);

        return implode(' ', array_slice($keywords, 0, 3));
    }

    private function summarizeConversation(Conversation $conversation): string
    {
        $messages = $conversation->getMessages();
        if (count($messages) < 2) {
            return '';
        }

        // Get first user message and assistant response
        $userMessage = $messages[0]->getContent();
        $assistantResponse = $messages[1]->getContent();

        return "User asked: " . substr($userMessage, 0, 100) . "...\n" .
               "Assistant responded: " . substr($assistantResponse, 0, 100) . "...";
    }

    private function extractAndStorePreference(string $message): void
    {
        // Use the agent to extract preferences
        $extraction = $this->agent
            ->withSystemPrompt('Extract user preferences from the message. ' .
                             'Format: preference:key=value')
            ->ask("Extract preferences from: " . $message);

        if (str_contains($extraction, 'preference:')) {
            // Store as a special conversation
            $prefConversation = new Conversation(
                'pref_' . uniqid(),
                'assistant_' . $this->userId,
                ['type' => 'preference']
            );
            $prefConversation->addMessage(new Message('system', $extraction));
            $this->memory->store($prefConversation);
        }
    }
}

// Usage
$assistant = new PersonalAssistant('user123');

// First interaction
echo $assistant->chat("I prefer morning meetings and casual communication style");
// "I've noted your preference for morning meetings and casual communication.
//  I'll keep this in mind for future scheduling and conversations!"

// Later interaction
echo $assistant->chat("When should we schedule our review?");
// "Based on your preference for morning meetings, how about 9 AM or 10 AM?
//  I've got a few slots available this week. What day works best for you?"

// Even later
echo $assistant->chat("Write an email to the team about the project update");
// "Here's a casual draft for your team update (keeping with your preferred style):
//
//  Hey team! 👋
//
//  Quick update on where we're at with the project..."
```

---

## Part 4: Creating a Learning System

Let's build an agent that improves its responses based on feedback:

````php
<?php

declare(strict_types=1);

namespace App\Agents;

use Pagent\Agent;
use Pagent\Memory\SQLiteAdapter;

final class LearningAgent
{
    private Agent $agent;
    private SQLiteAdapter $memory;
    private array $feedbackScores = [];

    public function __construct()
    {
        $this->memory = new SQLiteAdapter('learning_agent.db');
        $this->agent = agent()
            ->withSystemPrompt($this->buildAdaptivePrompt())
            ->withMemory($this->memory);
    }

    private function buildAdaptivePrompt(): string
    {
        $insights = $this->analyzePerformance();

        return "You are a learning assistant that improves over time. " .
               "Based on past feedback, focus on: " .
               implode(', ', $insights['improvements']) .
               "\n\nAvoid these patterns: " .
               implode(', ', $insights['avoid']);
    }

    private function analyzePerformance(): array
    {
        // Analyze successful interactions
        $successful = $this->memory->search([
            'agent_id' => 'learning_agent',
            'metadata' => json_encode(['feedback' => 'positive']),
            'limit' => 20,
        ]);

        // Analyze unsuccessful interactions
        $unsuccessful = $this->memory->search([
            'agent_id' => 'learning_agent',
            'metadata' => json_encode(['feedback' => 'negative']),
            'limit' => 20,
        ]);

        return [
            'improvements' => $this->extractPatterns($successful, true),
            'avoid' => $this->extractPatterns($unsuccessful, false),
        ];
    }

    private function extractPatterns(array $conversations, bool $positive): array
    {
        $patterns = [];

        foreach ($conversations as $conversation) {
            $messages = $conversation->getMessages();
            foreach ($messages as $message) {
                if ($message->getRole() === 'assistant') {
                    // Extract response characteristics
                    if (strlen($message->getContent()) > 500) {
                        $patterns[] = $positive ? 'detailed responses' : 'overly long responses';
                    }
                    if (str_contains($message->getContent(), '```')) {
                        $patterns[] = $positive ? 'code examples' : 'unnecessary code';
                    }
                    if (substr_count($message->getContent(), '?') > 2) {
                        $patterns[] = $positive ? 'clarifying questions' : 'too many questions';
                    }
                }
            }
        }

        return array_unique($patterns);
    }

    public function respond(string $query): array
    {
        // Find similar past queries with good feedback
        $bestPractices = $this->findBestPractices($query);

        if ($bestPractices) {
            $this->agent->withContext(
                "Successful similar responses:\n" . $bestPractices
            );
        }

        $response = $this->agent->ask($query);
        $conversationId = uniqid('conv_');

        return [
            'response' => $response,
            'conversation_id' => $conversationId,
        ];
    }

    public function receiveFeedback(string $conversationId, bool $positive, string $details = ''): void
    {
        $conversation = $this->memory->retrieve($conversationId);

        if ($conversation) {
            // Update conversation metadata with feedback
            $metadata = $conversation->getMetadata();
            $metadata['feedback'] = $positive ? 'positive' : 'negative';
            $metadata['feedback_details'] = $details;
            $metadata['feedback_timestamp'] = time();

            // Re-store with updated metadata
            $conversation->setMetadata($metadata);
            $this->memory->store($conversation);

            // Update learning metrics
            $this->updateLearningMetrics($conversation, $positive);
        }
    }

    private function findBestPractices(string $query): ?string
    {
        $similar = $this->memory->search([
            'content' => $this->extractKeywords($query),
            'metadata' => json_encode(['feedback' => 'positive']),
            'limit' => 3,
        ]);

        if (empty($similar)) {
            return null;
        }

        $practices = [];
        foreach ($similar as $conversation) {
            $messages = $conversation->getMessages();
            foreach ($messages as $message) {
                if ($message->getRole() === 'assistant') {
                    $practices[] = "Successful approach: " .
                                  substr($message->getContent(), 0, 200);
                }
            }
        }

        return implode("\n", $practices);
    }

    private function updateLearningMetrics(Conversation $conversation, bool $positive): void
    {
        // Track response characteristics that correlate with feedback
        $messages = $conversation->getMessages();

        foreach ($messages as $message) {
            if ($message->getRole() === 'assistant') {
                $characteristics = [
                    'length' => strlen($message->getContent()),
                    'has_code' => str_contains($message->getContent(), '```'),
                    'has_list' => str_contains($message->getContent(), "\n- "),
                    'question_count' => substr_count($message->getContent(), '?'),
                ];

                // Store these metrics for future analysis
                $this->feedbackScores[] = [
                    'characteristics' => $characteristics,
                    'positive' => $positive,
                ];
            }
        }
    }
}

// Usage
$learner = new LearningAgent();

// First attempt
$result = $learner->respond("How do I optimize database queries?");
echo $result['response'];

// User provides feedback
$learner->receiveFeedback(
    $result['conversation_id'],
    false,
    "Too theoretical, needed practical examples"
);

// Second attempt (will incorporate learning)
$result = $learner->respond("How do I improve API performance?");
echo $result['response'];
// Now includes more practical examples based on feedback
````

---

## Part 5: Memory Management and Pruning

Effective memory management prevents unbounded growth while preserving valuable context:

```php
<?php

declare(strict_types=1);

namespace App\Memory;

use Pagent\Memory\SQLiteAdapter;

final class MemoryManager
{
    private SQLiteAdapter $memory;
    private array $retentionPolicies;

    public function __construct(SQLiteAdapter $memory)
    {
        $this->memory = $memory;
        $this->retentionPolicies = [
            'default' => 30 * 24 * 60 * 60, // 30 days
            'important' => 365 * 24 * 60 * 60, // 1 year
            'temporary' => 24 * 60 * 60, // 1 day
        ];
    }

    public function applyRetentionPolicies(): array
    {
        $results = [];

        foreach ($this->retentionPolicies as $type => $retention) {
            $cutoff = time() - $retention;

            $pruned = $this->memory->prune([
                'older_than' => $cutoff,
                'metadata' => json_encode(['retention_type' => $type]),
            ]);

            $results[$type] = $pruned;
        }

        return $results;
    }

    public function archiveImportant(array $conversationIds): void
    {
        foreach ($conversationIds as $id) {
            $conversation = $this->memory->retrieve($id);
            if ($conversation) {
                $metadata = $conversation->getMetadata();
                $metadata['retention_type'] = 'important';
                $metadata['archived_at'] = time();
                $conversation->setMetadata($metadata);
                $this->memory->store($conversation);
            }
        }
    }

    public function consolidateMemories(string $agentId): void
    {
        // Find similar conversations to consolidate
        $all = $this->memory->search([
            'agent_id' => $agentId,
            'since' => time() - (7 * 24 * 60 * 60), // Last week
        ]);

        $groups = $this->groupSimilarConversations($all);

        foreach ($groups as $group) {
            if (count($group) > 1) {
                $this->mergeConversations($group);
            }
        }
    }

    private function groupSimilarConversations(array $conversations): array
    {
        // Group by similarity (simplified - use embeddings in production)
        $groups = [];

        foreach ($conversations as $conversation) {
            $key = $this->generateGroupKey($conversation);
            $groups[$key][] = $conversation;
        }

        return $groups;
    }

    private function generateGroupKey(Conversation $conversation): string
    {
        $messages = $conversation->getMessages();
        if (empty($messages)) {
            return 'empty';
        }

        // Simple hashing based on first message keywords
        $firstMessage = $messages[0]->getContent();
        $keywords = $this->extractKeywords($firstMessage);

        return md5($keywords);
    }

    private function mergeConversations(array $conversations): void
    {
        // Create a consolidated conversation
        $merged = new Conversation(
            'merged_' . uniqid(),
            $conversations[0]->getAgentId(),
            ['type' => 'consolidated', 'source_count' => count($conversations)]
        );

        // Add summary of each conversation
        foreach ($conversations as $conversation) {
            $summary = $this->summarizeConversation($conversation);
            $merged->addMessage(new Message('system', "Previous conversation: " . $summary));
        }

        // Store merged and remove originals
        $this->memory->store($merged);

        foreach ($conversations as $conversation) {
            $this->memory->prune(['id' => $conversation->getId()]);
        }
    }
}

// Usage
$memory = new SQLiteAdapter('agent_memory.db');
$manager = new MemoryManager($memory);

// Apply retention policies
$pruned = $manager->applyRetentionPolicies();
echo "Pruned {$pruned['temporary']} temporary conversations\n";

// Archive important conversations
$manager->archiveImportant(['conv_123', 'conv_456']);

// Consolidate similar conversations
$manager->consolidateMemories('assistant_user123');
```

---

## Exercises

### Exercise 1: Implement File-Based Memory

Create a file-based memory adapter that stores conversations as JSON files. Include indexing for fast searches.

### Exercise 2: Context Window Management

Build a system that automatically selects the most relevant memories to fit within token limits.

### Exercise 3: Memory Embeddings

Integrate vector embeddings to find semantically similar conversations rather than keyword matches.

### Exercise 4: Distributed Memory

Implement a memory system that can sync across multiple agents or instances.

---

## Troubleshooting

### Common Issues and Solutions

**SQLite Locked Error**

```php
// Use WAL mode for concurrent access
$this->db->exec('PRAGMA journal_mode=WAL');
```

**Memory Growing Too Large**

```php
// Implement automatic pruning
if ($this->getMemorySize() > $this->maxSize) {
    $this->pruneOldest(0.2); // Remove oldest 20%
}
```

**Slow Search Performance**

```php
// Add appropriate indexes
$this->db->exec('CREATE INDEX idx_search ON messages(content)');
```

---

## Summary

You've learned how to implement robust memory systems that transform stateless agents into intelligent assistants. Key concepts covered:

- **Memory adapter interface** for storage abstraction
- **SQLite integration** for reliable persistence
- **Search and retrieval** patterns for finding relevant context
- **Memory lifecycle management** including pruning and archiving
- **Learning systems** that improve from feedback

Memory systems are the foundation for building agents that maintain context, learn from interactions, and provide increasingly personalized experiences.

## Next Steps

- Explore vector databases for semantic memory search
- Implement memory compression techniques
- Build multi-agent systems with shared memory
- Create memory visualization tools
- Study memory patterns in production systems

## Additional Resources

- [SQLite Full-Text Search](https://www.sqlite.org/fts5.html)
- [Vector Similarity Search](https://github.com/nmslib/hnswlib)
- [Memory Management Patterns](https://martinfowler.com/eaaDev/EventSourcing.html)
- [Conversation Design Guidelines](https://developers.google.com/assistant/conversation-design)

Remember: Memory transforms agents from tools into partners. Every conversation stored is an opportunity to provide better, more contextual assistance in the future.
