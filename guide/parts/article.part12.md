# Chapter 12: Memory Systems

## Why Memory Matters

By default, agents are stateless. Each prompt starts fresh with no knowledge of previous interactions. While this works for one-off tasks, most real-world applications need conversation history - chatbots remembering context, support agents tracking issues across sessions, or workflows that build on previous exchanges.

Pagent's memory system solves this through a clean abstraction: the `Memory` interface. It provides persistent storage for conversation history, allowing agents to remember past interactions across script executions, web requests, or long-running processes.

**What makes Pagent's memory different:**

- **Zero configuration** - Works out of the box with sensible defaults
- **Adapter pattern** - Swap storage backends without changing agent code
- **Session isolation** - Multiple conversations stay separate
- **Automatic lifecycle** - Load on first prompt, save after each interaction
- **Production-ready** - Transactions, error handling, and concurrent access built-in

## The Memory Interface

The `Memory` interface defines five methods that any storage backend must implement:

```php
<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface Memory
{
    // Load messages for a session (returns empty array if not found)
    public function load(string $sessionId): array;

    // Save messages for a session
    public function save(string $sessionId, array $messages): void;

    // Delete a session permanently
    public function delete(string $sessionId): void;

    // Check if a session exists
    public function exists(string $sessionId): bool;

    // Prune old messages, keeping most recent N messages
    public function prune(string $sessionId, int $maxMessages): array;
}
```

Messages are stored as arrays with `role` and `content` keys:

```php
[
    ['role' => 'user', 'content' => 'Hello'],
    ['role' => 'assistant', 'content' => 'Hi there!'],
    ['role' => 'user', 'content' => 'How are you?'],
]
```

This simple structure works across all providers while supporting complex content like tool calls and multi-modal messages.

## Enabling Memory

Add memory to any agent using the `memory()` and `sessionId()` methods:

```php
use function Pagent\agent;

$agent = agent('support-bot')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->memory('Sqlite', ['path' => 'storage/sessions.db'])
    ->sessionId('user-12345')
    ->system('You are a helpful support agent.');

// First conversation
$agent->prompt('I need help with my order');
// "Of course! What can I help you with?"

$agent->prompt('Order number is #4829');
// "Let me look up order #4829 for you..."

// Later - same user, new script execution
$agent = agent('support-bot')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->memory('Sqlite', ['path' => 'storage/sessions.db'])
    ->sessionId('user-12345')
    ->system('You are a helpful support agent.');

$agent->prompt('What was my order number again?');
// "Your order number is #4829. Is there anything else I can help with?"
```

The agent automatically:

1. Loads conversation history on first prompt
2. Saves messages after each interaction
3. Maintains context across script executions

**Session IDs are how you organize conversations.** Use user IDs, ticket numbers, or any unique identifier that makes sense for your application:

```php
// Per-user conversations
->sessionId("user-{$userId}")

// Support tickets
->sessionId("ticket-{$ticketId}")

// Temporary sessions
->sessionId("temp-".uniqid())
```

## Built-in Adapters

Pagent ships with three memory adapters, each optimized for different use cases.

### SqliteAdapter - Production Default

SQLite provides robust persistence with zero configuration. Perfect for most applications:

```php
$agent->memory('Sqlite', [
    'path' => 'storage/sessions.db',  // Default location
]);
```

**Features:**

- Automatic schema creation and migrations
- WAL mode for concurrent reads
- Transaction safety for writes
- Indexed queries for fast lookups
- Created/updated timestamps

**Database schema:**

```sql
CREATE TABLE sessions (
    session_id TEXT PRIMARY KEY,
    messages TEXT NOT NULL,          -- JSON-encoded messages
    created_at TEXT NOT NULL,        -- ISO 8601 timestamp
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_updated_at ON sessions(updated_at);
```

**When to use:**

- Production applications
- Multiple concurrent users
- Need transaction safety
- Want query capabilities

### FileAdapter - Simple Persistence

JSON files, one per session. Great for development or low-volume applications:

```php
$agent->memory('File', [
    'directory' => 'storage/sessions',  // Default location
    'permissions' => 0755,              // Directory permissions
]);
```

**Features:**

- Human-readable JSON format
- No dependencies beyond filesystem
- LOCK_EX for atomic writes
- Pretty-printed for debugging

**File format:**

```json
{
  "session_id": "user-12345",
  "messages": [
    { "role": "user", "content": "Hello" },
    { "role": "assistant", "content": "Hi there!" }
  ],
  "updated_at": "2025-11-17T10:30:00+00:00"
}
```

**When to use:**

- Development and testing
- Low-volume applications
- Need human-readable storage
- Debugging conversation history

### NullAdapter - No Persistence

The default when no memory is configured. All operations are no-ops:

```php
$adapter = new NullAdapter();

$adapter->load('any-session');    // Returns []
$adapter->exists('any-session');  // Returns false
$adapter->save('any-session', $messages);  // Does nothing
```

**When to use:**

- Testing and mocking
- Truly stateless operations
- Default behavior when memory not needed

## Memory Lifecycle

Understanding when Pagent loads and saves memory is crucial for performance and correctness.

### Lazy Loading

Memory loads automatically on the **first prompt** for a session:

```php
$agent = agent('bot')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->memory('Sqlite')
    ->sessionId('session-123');

// Messages array is empty until first prompt
expect($agent->messages)->toBeEmpty();

$agent->prompt('Hello');  // Triggers load from storage

// Now includes loaded history plus new exchange
expect($agent->messages)->toHaveCount(4);  // 2 loaded + 2 new
```

This lazy approach means:

- No unnecessary database hits
- Configuration happens independently of loading
- Memory only loaded when needed

### Auto-save

After every prompt, messages are automatically saved:

```php
$agent->prompt('What is 2+2?');
// Messages saved to storage immediately after response

$agent->prompt('And 3+3?');
// Messages saved again with full history
```

This ensures:

- No manual save calls needed
- Conversation never lost mid-session
- Each interaction persisted atomically

### Manual Memory Operations

Sometimes you need direct control:

```php
use Pagent\Memory\Adapters\SqliteAdapter;

$memory = new SqliteAdapter(['path' => 'storage/sessions.db']);

// Check if session exists
if ($memory->exists('user-12345')) {
    // Load messages
    $messages = $memory->load('user-12345');

    // Inspect or modify
    $lastMessage = end($messages);

    // Save back
    $memory->save('user-12345', $messages);
}

// Delete a session
$memory->delete('user-12345');

// Prune old messages (keep last 10)
$pruned = $memory->prune('user-12345', 10);
```

Pass adapter instances to agents for shared storage:

```php
$memory = new SqliteAdapter(['path' => 'storage/sessions.db']);

$agent1 = agent('bot-1')
    ->memory($memory)
    ->sessionId('session-001');

$agent2 = agent('bot-2')
    ->memory($memory)  // Same adapter
    ->sessionId('session-002');  // Different session
```

## Memory Pruning

Long conversations eventually exceed context windows. The `prune()` method keeps recent messages while discarding old ones:

```php
// Keep only the 50 most recent messages
$pruned = $memory->prune('long-session', 50);

expect($pruned)->toHaveCount(50);
// Oldest messages removed, newest preserved
```

**Pruning strategy:**

- Takes most recent N messages
- Preserves system messages
- Updates storage atomically
- Returns pruned message array

**Use cases:**

- Periodic cleanup of long sessions
- Pre-pruning before expensive operations
- Managing storage costs

**Important:** Pruning happens at the memory layer, not the context window layer. For automatic token-based pruning during prompt execution, use `contextWindow()` (covered in Chapter 9).

## Session Management Patterns

### Per-User Sessions

Track conversations by user ID:

```php
class ChatController
{
    public function handle(Request $request): Response
    {
        $userId = $request->user()->id;

        $agent = agent('chatbot')
            ->provider('anthropic')
            ->model('claude-sonnet-4-20250514')
            ->memory('Sqlite')
            ->sessionId("user-{$userId}")
            ->system('You are a helpful assistant.');

        $response = $agent->prompt($request->input('message'));

        return response()->json([
            'reply' => $response->content,
        ]);
    }
}
```

Each user gets isolated conversation history that persists across requests.

### Temporary Sessions

Create ephemeral sessions that can be cleaned up:

```php
// Generate temporary session ID
$tempId = 'temp-'.uniqid();

$agent = agent('wizard')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->memory('File', ['directory' => 'storage/temp'])
    ->sessionId($tempId);

// Use for multi-step workflow
$agent->prompt('Start analysis...');
$agent->prompt('Continue with step 2...');

// Clean up when done
$memory = new FileAdapter(['directory' => 'storage/temp']);
$memory->delete($tempId);
```

### Multi-Agent Coordination

Multiple agents can share or keep separate sessions:

```php
$memory = new SqliteAdapter(['path' => 'storage/workflows.db']);

// Analyst agent - own session
$analyst = agent('analyst')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->memory($memory)
    ->sessionId('workflow-123-analyst')
    ->system('You analyze data and provide insights.');

// Writer agent - own session
$writer = agent('writer')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->memory($memory)
    ->sessionId('workflow-123-writer')
    ->system('You write reports based on analysis.');

// Agents maintain separate conversation histories
$analysis = $analyst->prompt('Analyze sales data...');
$report = $writer->prompt("Write a report about: {$analysis->content}");

// Both sessions persist independently
```

Session naming convention helps organize related conversations:

- `workflow-{id}-{role}` - Multi-agent workflows
- `ticket-{id}-{stage}` - Multi-stage support
- `project-{id}-{timestamp}` - Timestamped snapshots

## Error Handling

All adapters throw `RuntimeException` for storage failures:

```php
use RuntimeException;

try {
    $agent->prompt('Hello');
} catch (RuntimeException $e) {
    // Database locked, disk full, permissions issue, etc.
    logger()->error('Memory error: '.$e->getMessage());

    // Fallback: continue without persistence
    $agent->memory(new NullAdapter());
    $agent->prompt('Hello');  // Works without storage
}
```

**Common errors:**

- **SQLite**: Database locked (concurrent writes), disk full
- **File**: Directory not writable, disk full, filesystem errors
- **Both**: JSON encoding failures (invalid UTF-8)

**Production tip:** Always catch memory errors and have a fallback strategy. Most applications can degrade gracefully to stateless operation rather than failing completely.

## Testing with Memory

Memory makes testing conversation flows straightforward:

```php
use Pagent\Memory\Adapters\FileAdapter;
use Pagent\Providers\Mock;

it('maintains conversation context', function (): void {
    $tempDir = sys_get_temp_dir().'/test-'.uniqid();

    $agent = agent('test-bot')
        ->provider(new Mock([
            'responses' => [
                'My name is Alice' => 'Nice to meet you, Alice!',
                'What is my name?' => 'Your name is Alice.',
            ],
        ]))
        ->memory('File', ['directory' => $tempDir])
        ->sessionId('test-session');

    // First exchange
    $r1 = $agent->prompt('My name is Alice');
    expect($r1->content)->toBe('Nice to meet you, Alice!');

    // Second exchange - should remember name
    $r2 = $agent->prompt('What is my name?');
    expect($r2->content)->toBe('Your name is Alice.');

    // Verify persistence
    $memory = new FileAdapter(['directory' => $tempDir]);
    $messages = $memory->load('test-session');
    expect($messages)->toHaveCount(4);
});
```

For integration tests, use temporary storage and clean up after:

```php
beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/pagent-test-'.uniqid();
});

afterEach(function (): void {
    // Clean up test sessions
    if (is_dir($this->tempDir)) {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
        rmdir($this->tempDir);
    }
});
```

## Custom Memory Adapters

Need Redis? Memcached? Cloud storage? Implement the `Memory` interface:

```php
<?php

declare(strict_types=1);

namespace App\Memory;

use Pagent\Contracts\Memory;
use Redis;

final class RedisAdapter implements Memory
{
    private Redis $redis;
    private string $prefix;

    public function __construct(array $config = [])
    {
        $this->redis = new Redis();
        $this->redis->connect(
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 6379
        );
        $this->prefix = $config['prefix'] ?? 'pagent:session:';
    }

    public function load(string $sessionId): array
    {
        $key = $this->prefix.$sessionId;
        $json = $this->redis->get($key);

        if ($json === false) {
            return [];
        }

        return json_decode($json, true) ?? [];
    }

    public function save(string $sessionId, array $messages): void
    {
        $key = $this->prefix.$sessionId;
        $json = json_encode($messages);

        $this->redis->set($key, $json);
        $this->redis->expire($key, 86400);  // 24-hour TTL
    }

    public function delete(string $sessionId): void
    {
        $this->redis->del($this->prefix.$sessionId);
    }

    public function exists(string $sessionId): bool
    {
        return $this->redis->exists($this->prefix.$sessionId) > 0;
    }

    public function prune(string $sessionId, int $maxMessages): array
    {
        $messages = $this->load($sessionId);

        if (count($messages) <= $maxMessages) {
            return $messages;
        }

        $pruned = array_slice($messages, -$maxMessages);
        $this->save($sessionId, $pruned);

        return $pruned;
    }
}
```

Use it like any built-in adapter:

```php
use App\Memory\RedisAdapter;

$agent = agent('bot')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->memory(new RedisAdapter([
        'host' => 'redis.example.com',
        'prefix' => 'chatbot:',
    ]))
    ->sessionId('user-123');
```

## What's Next

You now understand how Pagent manages conversation memory across sessions. You can persist conversations to SQLite or files, manage session lifecycles, prune old messages, and even build custom adapters.

In the next chapter, we'll explore **Events and Hooks** - how to tap into the agent lifecycle for logging, metrics, debugging, and custom behaviors at every stage of execution.

**Key Takeaways:**

- Memory is optional but critical for stateful conversations
- Three built-in adapters: SQLite (production), File (development), Null (testing)
- Automatic lazy loading and auto-save eliminate boilerplate
- Session IDs organize conversations by user, workflow, or context
- The `Memory` interface makes custom adapters straightforward
