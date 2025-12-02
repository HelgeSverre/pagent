# Chapter 11: Advanced Streaming Patterns

In Chapter 10, we explored the basics of streaming responses - receiving LLM output token by token as it's generated. But production applications require more sophisticated streaming patterns. You need to handle errors gracefully, integrate streaming with conversation memory, track token usage and completion metadata, and build robust user interfaces that handle network interruptions.

This chapter dives into advanced streaming techniques that make your agents production-ready. We'll explore error handling during streams, memory integration, metadata extraction, and real-world patterns for building responsive applications with streaming agents.

## Understanding StreamResponse

Every streaming operation in Pagent returns a `StreamResponse` object that provides structured access to the stream lifecycle:

```php
use function Pagent\agent;

$agent = agent('writer')
    ->provider('anthropic')
    ->build();

// stream() returns StreamResponse
$streamResponse = $agent->stream('Write a haiku about coding');

// StreamResponse provides metadata
echo $streamResponse->getProvider(); // 'anthropic'
echo $streamResponse->getModel();    // 'claude-sonnet-4-20250514'
```

The `StreamResponse` class wraps a PHP Generator that yields `StreamChunk` objects. It provides two primary methods for consuming the stream:

### collect() - Blocking Collection

The `collect()` method iterates through all chunks, accumulates text content, and extracts metadata:

```php
$streamResponse = $agent->stream('Explain PHP generators');

// Blocks until complete
$fullContent = $streamResponse->collect();

// Metadata is extracted from final chunks
$usage = $streamResponse->getUsage();
// ['input_tokens' => 23, 'output_tokens' => 156]

$stopReason = $streamResponse->getStopReason();
// 'end_turn', 'max_tokens', 'stop_sequence', etc.

echo $fullContent;
```

This is useful when you need the complete response before proceeding, but still want to leverage provider-level streaming optimizations.

### streamTo() - Callback Processing

The `streamTo()` method invokes your callback for each chunk as it arrives:

```php
$streamResponse = $agent->stream('Count to five');

$streamResponse->streamTo(function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});

// Access accumulated content after streaming completes
$fullContent = $streamResponse->getFullContent();
$usage = $streamResponse->getUsage();
```

The callback receives `StreamChunk` objects with methods for identifying chunk types:

- `isText()` - Text content chunks
- `isStart()` - Stream initiation
- `isEnd()` - Stream completion
- `isError()` - Error chunks
- `isToolCall()` - Tool call chunks (provider-specific)

## Agent-Level Streaming with streamTo()

While `stream()` returns a `StreamResponse`, the `Agent::streamTo()` method provides a higher-level interface that integrates streaming with conversation history, guards, and memory:

```php
$agent = agent('assistant')
    ->provider('anthropic')
    ->system('You are a helpful coding assistant.')
    ->build();

// streamTo() handles everything automatically
$fullContent = $agent->streamTo(
    'Explain dependency injection',
    function ($chunk) {
        if ($chunk->isText()) {
            echo $chunk->content;
            flush();
        }
    }
);

// User message and assistant response are both added to history
echo count($agent->messages); // 2
```

This method signature says it all:

```php
public function streamTo(string $message, callable $callback, array $options = []): string
```

It takes your message, streams the response to your callback, and returns the full content when complete. Behind the scenes, it:

1. Loads conversation history from memory (if configured)
2. Streams the response chunk by chunk to your callback
3. Adds the complete response to message history
4. Runs guards on the full content
5. Saves updated history to memory (if configured)

## Error Handling in Streams

Streaming introduces unique error scenarios. The connection might drop mid-stream, the provider might return an error chunk, or a guard might fail after the stream completes. Pagent provides structured error handling at multiple levels.

### Provider-Level Errors

Provider errors (authentication failures, rate limits, network issues) throw exceptions before streaming begins:

```php
use function Pagent\agent;

$agent = agent('test')
    ->provider('anthropic')
    ->build();

try {
    $agent->streamTo('Hello', function ($chunk) {
        echo $chunk->content;
    });
} catch (RuntimeException $e) {
    // API authentication failed
    // Rate limit exceeded
    // Network connectivity issues
    error_log("Streaming failed: " . $e->getMessage());
}
```

Always wrap streaming operations in try-catch blocks for production code.

### Stream Interruption

If the stream is interrupted (network dropout, server timeout), the provider's stream parser will detect the incomplete stream when it reaches EOF unexpectedly:

```php
// The stream parser detects incomplete streams
while (!feof($stream)) {
    $line = fgets($stream);
    if ($line === false) {
        break; // Stream ended unexpectedly
    }
    // Process line...
}

// No end chunk received = incomplete stream
```

This manifests as a missing end chunk. You can detect this in your callback:

```php
$receivedEndChunk = false;

$agent->streamTo('Generate code', function ($chunk) use (&$receivedEndChunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
    }

    if ($chunk->isEnd()) {
        $receivedEndChunk = true;
        echo "\n[Stream completed successfully]\n";
    }
});

if (!$receivedEndChunk) {
    error_log("Stream was interrupted before completion");
}
```

### Guard Exceptions After Streaming

Guards run on the complete content after streaming finishes. A guard violation throws a `GuardException`:

```php
use Pagent\Exceptions\GuardException;

$agent = agent('safe-bot')
    ->provider('anthropic')
    ->guard(fn($msg, $resp) => !str_contains($resp, 'banned-word'))
    ->build();

try {
    $agent->streamTo('Generate some text', function ($chunk) {
        echo $chunk->content; // Stream completes successfully
    });
} catch (GuardException $e) {
    // Guard failed AFTER streaming completed
    echo "Content violated guard: " . $e->getMessage();
}
```

The stream callback receives all chunks normally. The exception is thrown after `streamTo()` collects the full content and runs guards on it.

### Fallbacks for Failed Guards

Pagent supports fallback handlers for guard failures during streaming:

```php
$agent = agent('resilient-bot')
    ->provider('anthropic')
    ->guard(fn($msg, $resp) => !str_contains($resp, 'inappropriate'))
    ->fallback(function (GuardException $e) {
        return "I apologize, but I cannot provide that response.";
    })
    ->build();

// If the guard fails, fallback is called instead of throwing
$result = $agent->streamTo('Some prompt', function ($chunk) {
    echo $chunk->content;
});

// $result might be the streamed content OR the fallback message
```

When a guard fails during `streamTo()`, the fallback is invoked and its return value becomes the function's return value. No exception is thrown to the caller.

## Memory Integration

When you configure memory and a session ID, `streamTo()` automatically loads and saves conversation history:

```php
$agent = agent('persistent-assistant')
    ->provider('anthropic')
    ->memory('sqlite', ['path' => 'conversations.db'])
    ->sessionId('user-123')
    ->build();

// First call: loads existing history from memory
$agent->streamTo('What did we discuss last time?', function ($chunk) {
    echo $chunk->content;
});

// History is saved after streaming completes
// Next call: history is auto-loaded again
```

This happens transparently through telemetry-tracked spans:

```php
// From src/Agent.php:423-446
if ($this->memory && $this->sessionId && empty($this->messages)) {
    $memorySpan = TelemetryManager::instance()
        ->startSpan('memory.load', ['session_id' => $this->sessionId]);

    try {
        $loaded = $this->memory->load($this->sessionId);
        $this->messages = $loaded;

        $memorySpan->setStatus('ok');
    } catch (Throwable $e) {
        $memorySpan->recordException($e);
        throw $e;
    } finally {
        $memorySpan->end();
    }
}
```

And after streaming:

```php
// From src/Agent.php:466-488
if ($this->memory && $this->sessionId) {
    $memorySpan = TelemetryManager::instance()
        ->startSpan('memory.save', ['session_id' => $this->sessionId]);

    try {
        $this->memory->save($this->sessionId, $this->messages);
        $memorySpan->setStatus('ok');
    } catch (Throwable $e) {
        $memorySpan->recordException($e);
        throw $e;
    } finally {
        $memorySpan->end();
    }
}
```

Memory errors are propagated as exceptions, allowing you to handle persistence failures explicitly.

## Extracting Token Usage and Metadata

Streaming responses include usage statistics and completion metadata in the final chunks:

```php
$agent = agent('analyzer')
    ->provider('anthropic')
    ->build();

$streamResponse = $agent->stream('Analyze this code...');

$streamResponse->streamTo(function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
    }

    if ($chunk->isEnd()) {
        // Final chunk may include metadata
        $usage = $chunk->getMetadata('usage');
        $stopReason = $chunk->getMetadata('stop_reason');

        error_log("Used {$usage['output_tokens']} output tokens");
        error_log("Stopped because: {$stopReason}");
    }
});

// Or access via StreamResponse after completion
$usage = $streamResponse->getUsage();
// ['input_tokens' => 45, 'output_tokens' => 230]

$stopReason = $streamResponse->getStopReason();
// 'end_turn' (completed naturally)
// 'max_tokens' (hit token limit)
// 'stop_sequence' (hit stop sequence)
```

This metadata is critical for:

- **Cost tracking** - Log token usage per request
- **Quality monitoring** - Detect truncated responses (`max_tokens`)
- **Debugging** - Understand why streams ended
- **Rate limiting** - Track usage across sessions

## Building Real-Time UIs

Streaming shines in interactive applications where responsiveness matters. Here are production-tested patterns.

### Progressive Code Analyzer

Stream code analysis results as they're generated:

````php
$agent = agent('code-reviewer')
    ->provider('anthropic')
    ->system('You are a code reviewer. Analyze code and suggest improvements.')
    ->build();

$code = file_get_contents('app/Models/User.php');

echo "<div class='analysis-output'>";

$agent->streamTo(
    "Review this code:\n\n```php\n{$code}\n```",
    function ($chunk) {
        if ($chunk->isText()) {
            // Convert markdown to HTML on the fly
            $html = markdownToHtml($chunk->content);
            echo $html;

            // Push to browser immediately
            echo str_repeat(' ', 1024); // Flush buffer
            flush();
        }
    }
);

echo "</div>";
````

Users see analysis appearing in real-time instead of waiting for the complete response.

### Live Dashboard Updates

Stream dashboard metrics as they're calculated:

```php
$agent = agent('metrics-analyzer')
    ->provider('anthropic')
    ->tools([new CalculateTool(), new QueryDatabaseTool()])
    ->system('Analyze metrics and provide insights.')
    ->build();

$sections = [];
$currentSection = '';

$agent->streamTo(
    'Analyze our Q4 performance',
    function ($chunk) use (&$sections, &$currentSection) {
        if ($chunk->isText()) {
            $text = $chunk->content;
            $currentSection .= $text;

            // Detect section boundaries
            if (str_contains($text, "\n##")) {
                // Section completed, send to dashboard
                updateDashboard($currentSection);
                $sections[] = $currentSection;
                $currentSection = '';
            }

            echo $text;
            flush();
        }
    }
);

// Send final section
if ($currentSection) {
    updateDashboard($currentSection);
}
```

### Streaming with Progress Indicators

Show progress during long-running streams:

```php
$agent = agent('report-writer')
    ->provider('anthropic')
    ->maxTokens(4000)
    ->build();

$tokenCount = 0;
$startTime = microtime(true);

echo "Generating report...\n";

$result = $agent->streamTo(
    'Write a comprehensive security audit report',
    function ($chunk) use (&$tokenCount, $startTime) {
        if ($chunk->isText()) {
            $tokenCount += str_word_count($chunk->content);
            $elapsed = round(microtime(true) - $startTime, 1);

            // Update progress indicator
            echo "\r[{$tokenCount} tokens | {$elapsed}s]";

            // Log to file without progress indicator
            file_put_contents('report.txt', $chunk->content, FILE_APPEND);
        }
    }
);

echo "\n\nReport complete! Total: {$tokenCount} tokens\n";
```

## Understanding Chunk Types

Different providers emit different chunk types during streaming. Pagent's `StreamChunk` normalizes these differences:

```php
$agent->streamTo('Hello', function ($chunk) {
    // Check chunk type
    if ($chunk->isStart()) {
        echo "[Stream started]\n";
        $messageId = $chunk->getMetadata('message_id');
    }

    if ($chunk->isText()) {
        echo $chunk->content;
    }

    if ($chunk->isToolCall()) {
        // Provider-specific: Anthropic sends tool call deltas
        $partialJson = $chunk->content;
        $toolName = $chunk->getMetadata('tool_name');
        echo "[Calling tool: {$toolName}]\n";
    }

    if ($chunk->isEnd()) {
        echo "\n[Stream ended]\n";
        $usage = $chunk->getMetadata('usage');
        $stopReason = $chunk->getMetadata('stop_reason');
    }

    if ($chunk->isError()) {
        echo "ERROR: " . $chunk->content . "\n";
    }
});
```

The chunk type methods (`isText()`, `isStart()`, etc.) abstract provider differences. Anthropic emits `content_block_delta` for text, OpenAI emits `delta` objects, but both return `true` for `isText()`.

## Streaming vs. Non-Streaming Trade-offs

When should you use streaming, and when should you stick with `prompt()`?

**Use streaming when:**

- Building interactive UIs where perceived latency matters
- Generating long-form content (articles, reports, code)
- Users need feedback that something is happening
- You want to parse output progressively (extracting sections)
- Token usage tracking is critical (stream metadata is more detailed)

**Use non-streaming when:**

- Tool calling is required (tools execute after complete response)
- Guards must see complete content before proceeding
- Output is short (< 100 tokens)
- You need the complete response before taking action
- Simplicity matters more than responsiveness

Note that `streamTo()` still runs guards on the full content and integrates with memory, so streaming doesn't sacrifice safety features. It just delays guard evaluation until after streaming completes.

## Performance Considerations

Streaming introduces overhead. Every chunk requires parsing, callback invocation, and potential I/O operations. Here are optimization strategies:

### Batching Chunk Output

Don't flush to the browser on every chunk:

```php
$buffer = '';
$flushInterval = 50; // Flush every 50 chunks
$chunkCount = 0;

$agent->streamTo('Generate content', function ($chunk) use (&$buffer, &$chunkCount, $flushInterval) {
    if ($chunk->isText()) {
        $buffer .= $chunk->content;
        $chunkCount++;

        if ($chunkCount % $flushInterval === 0) {
            echo $buffer;
            flush();
            $buffer = '';
        }
    }
});

// Flush remaining buffer
if ($buffer) {
    echo $buffer;
}
```

This reduces syscall overhead while maintaining responsiveness.

### Avoid Expensive Operations Per Chunk

Process chunks efficiently:

```php
// ❌ Bad: Expensive operation per chunk
$agent->streamTo('Generate JSON', function ($chunk) {
    if ($chunk->isText()) {
        // Don't parse incomplete JSON on every chunk
        $data = json_decode($chunk->content, true);
        echo $chunk->content;
    }
});

// ✅ Good: Accumulate and process once
$jsonBuffer = '';

$agent->streamTo('Generate JSON', function ($chunk) use (&$jsonBuffer) {
    if ($chunk->isText()) {
        $jsonBuffer .= $chunk->content;
        echo $chunk->content;
    }

    if ($chunk->isEnd()) {
        // Parse complete JSON once
        $data = json_decode($jsonBuffer, true);
        processData($data);
    }
});
```

### Async Streaming (Future)

Pagent currently uses synchronous streaming via cURL. Future versions might support async streaming with ReactPHP or Swoole for true concurrent streams. The current API is designed to support this evolution:

```php
// Current: synchronous
$content = $agent->streamTo('prompt', $callback);

// Future possibility: async
$promise = $agent->streamToAsync('prompt', $callback);
$promise->then(fn($content) => handleComplete($content));
```

The `streamTo()` signature returning `string` makes this transition smooth.

## What's Next?

You now understand advanced streaming patterns:

- `StreamResponse` vs. `Agent::streamTo()` interfaces
- Error handling at multiple levels (provider, interruption, guards)
- Memory integration with automatic load/save
- Extracting usage metadata and completion reasons
- Building real-time UIs with streaming
- Performance optimization strategies

In **Chapter 12: Memory Systems**, we'll explore:

- Implementing conversation memory with SQLite and file adapters
- Managing memory lifecycle with session IDs
- Querying historical conversations
- Memory pruning strategies for long-running agents
- Building context-aware agents with persistent state

**Key Takeaways:**

✅ `StreamResponse` provides `collect()` and `streamTo()` for consuming streams
✅ `Agent::streamTo()` integrates streaming with memory, guards, and history
✅ Guard exceptions are thrown after streaming completes
✅ Fallbacks can replace guard exception throwing
✅ Usage metadata is available in end chunks and via `StreamResponse`
✅ Memory operations (load/save) happen automatically around streaming
✅ Chunk types are normalized across providers via `StreamChunk` methods
✅ Batch chunk processing for better performance in production

Continue to [Chapter 12: Memory Systems](./article.part12.md) →
