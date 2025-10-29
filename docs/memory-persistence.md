# Memory & Persistence

Pagent provides persistent conversation storage, enabling stateful agents that remember context across sessions.

## Quick Start

### Basic Persistence

```php
use function Pagent\agent;

$agent = agent('support')
    ->memory('sqlite', ['path' => 'storage/chats.db'])
    ->sessionId('user-123')
    ->prompt('Hello, I need help');

// Later - conversation continues
$agent->prompt('What was my last question?');
// Agent remembers previous context
```

### Context Window Management

```php
$agent = agent('chat')
    ->memory('file', ['path' => 'storage/sessions'])
    ->sessionId('session-456')
    ->contextWindow(100000, 'sliding') // Keep 100k tokens, sliding window
    ->prompt('Long conversation...');
```

## Memory Adapters

### NullAdapter (Default)

No persistence - each request starts fresh.

```php
// Default behavior (no memory configured)
$agent = agent('bot')->prompt('Hello');
```

### FileAdapter

JSON file storage - good for development.

```php
$agent = agent('bot')
    ->memory('file', [
        'path' => 'storage/sessions',
        'permissions' => 0644
    ])
    ->sessionId('user-123')
    ->prompt('Hello');
```

**Storage location:** `storage/sessions/{sessionId}.json`

### SqliteAdapter

Production-ready database storage.

```php
$agent = agent('bot')
    ->memory('sqlite', ['path' => 'storage/memory.db'])
    ->sessionId('user-123')
    ->prompt('Hello');
```

**Features:**

- ACID transactions
- WAL mode for concurrency
- Automatic schema creation
- Indexed for performance

## Session Management

### Session IDs

Required for persistence:

```php
// User-based sessions
$sessionId = 'user-' . $userId;

// Ticket-based sessions
$sessionId = 'support-' . $ticketId;

// UUID sessions
$sessionId = 'session-' . bin2hex(random_bytes(16));

$agent->sessionId($sessionId);
```

### Session Isolation

Each session is completely isolated:

```php
// User A
agent('chat')->sessionId('user-alice')->prompt('My favorite color is blue');

// User B - separate session
agent('chat')->sessionId('user-bob')->prompt('What is my favorite color?');
// Doesn't know about Alice's blue
```

## Context Window Management

Control token usage and stay within provider limits.

### Token Counting

Automatic estimation (4 characters ≈ 1 token):

```php
$agent->contextWindow(100000); // 100k token limit
$tokens = $agent->getContextTokenCount();
```

### Pruning Strategies

**Oldest First:**

```php
$agent->contextWindow(100000, 'oldest'); // Remove oldest messages
```

**Sliding Window:**

```php
$agent->contextWindow(100000, 'sliding'); // Keep most recent
```

Both strategies preserve the system message.

## API Reference

### memory()

Configure memory adapter.

```php
public function memory(
    string|Memory $adapter,
    array $config = []
): self
```

**Adapters:** `'null'`, `'file'`, `'sqlite'`

### sessionId()

Set unique session identifier.

```php
public function sessionId(string $id): self
```

### contextWindow()

Configure context limits.

```php
public function contextWindow(
    int $maxTokens,
    string $strategy = 'oldest'
): self
```

**Strategies:** `'oldest'`, `'sliding'`

## Examples

### Multi-Session Application

```php
class ChatManager
{
    public function chat(string $userId, string $message): string
    {
        $agent = agent('support')
            ->memory('sqlite', ['path' => 'storage/chats.db'])
            ->sessionId('user-' . $userId)
            ->contextWindow(50000);

        return $agent->prompt($message)->content;
    }
}
```

### Session Cleanup

```php
// Clean old sessions (pseudo-code)
$db = new PDO('sqlite:storage/chats.db');
$cutoff = date('Y-m-d', strtotime('-30 days'));
$db->exec("DELETE FROM sessions WHERE updated_at < '{$cutoff}'");
```

## Configuration

### File Adapter Config

```php
[
    'path' => 'storage/sessions',  // Directory path
    'permissions' => 0644           // File permissions
]
```

### SQLite Adapter Config

```php
[
    'path' => 'storage/memory.db'  // Database file path
]
```

## Best Practices

**DO:**

- Use descriptive session IDs
- Set appropriate context limits
- Clean up old sessions periodically
- Use SQLite for production

**DON'T:**

- Use same session ID for multiple users
- Exceed provider token limits
- Store sensitive data in session IDs
- Forget to configure sessionId

## Provider Compatibility

| Provider        | Max Tokens | Recommended Limit |
| --------------- | ---------- | ----------------- |
| Claude 4 Sonnet | 200k       | 180k              |
| GPT-4 Turbo     | 128k       | 120k              |
| GPT-4           | 8k         | 7k                |

## Troubleshooting

**"Session ID required"**

```php
// Add sessionId
$agent->sessionId('user-123');
```

**"Cannot write to storage"**

```bash
# Fix permissions
chmod 755 storage/
chmod 755 storage/sessions/
```

**"Context window exceeded"**

```php
// Reduce limit or use pruning
$agent->contextWindow(100000, 'sliding');
```

## Next Steps

- [Streaming Guide](streaming.md) - Real-time responses
- [Examples](../examples/) - Working code examples
- [API Reference](api-reference.md) - Complete API docs

---

For complete examples, see:

- `examples/11-memory-sqlite.php`
- `examples/11-memory-file.php`
- `examples/11-memory-multi-session.php`
