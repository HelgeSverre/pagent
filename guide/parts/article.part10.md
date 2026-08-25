# Chapter 10: Streaming Fundamentals

In previous chapters, we've worked with the standard `prompt()` method - send a message, wait for the complete response, then display it. This works perfectly for many use cases, but what about scenarios where you want to show progress as the response generates? What about building a chatbot with that characteristic "typing" effect, or displaying long-form content word-by-word as it's created?

This is where streaming comes in. Pagent provides first-class support for streaming responses through its `stream()` and `streamTo()` methods, letting you process LLM output in real-time as it arrives. In this chapter, we'll explore how to enable streaming, handle chunks of data, and build responsive real-time interfaces.

## Why Streaming Matters

Before diving into the API, let's understand why streaming is valuable:

**User Experience**: When generating a lengthy response, streaming provides immediate feedback. Instead of staring at a blank screen for 10 seconds, users see text appearing progressively, creating a more engaging experience.

**Perceived Performance**: Even though the total response time might be the same, streaming makes your application feel faster. Users start reading the first sentences while the rest is still generating.

**Progressive Processing**: You can start processing response data before the full content arrives. For example, if generating code, you could begin syntax highlighting the first lines while later lines are still streaming.

**Interruption Control**: With streaming, you can potentially interrupt generation early if you've received enough information, saving tokens and time.

However, there's an important limitation to be aware of:

**Tool Calling Not Supported**: Unlike the standard `prompt()` method, streaming does not currently support automatic tool calling. If your agent has tools registered and the model wants to call them, streaming responses won't handle this automatically. For tool-enabled agents, use the standard `prompt()` method instead.

## The Basic Streaming API

Pagent provides two methods for streaming: `stream()` returns a `StreamResponse` object you can iterate over, while `streamTo()` accepts a callback function that processes each chunk. Let's start with the simpler approach:

```php
$agent = agent('storyteller')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->build();

// Stream with callback
$fullContent = $agent->streamTo('Tell me a short story', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush(); // Force output to browser immediately
    }
});

// After streaming completes, $fullContent contains the full response
echo "\n\nTotal length: " . strlen($fullContent) . " characters";
```

This simple example demonstrates the core pattern: provide a callback that receives each chunk, check if it's text content with `isText()`, then process it however you need. The `flush()` call ensures output reaches the browser immediately rather than buffering.

The `streamTo()` method handles several things automatically:

- Adds your message to conversation history
- Streams the response through your callback
- Collects the full content
- Adds the assistant's response to conversation history
- Saves to memory if configured
- Runs guards on the complete response
- Returns the full content as a string

This makes `streamTo()` the simplest way to add streaming to existing code - you get all the same conversation management features as `prompt()`, just with real-time output.

## Working with StreamResponse

For more control, use the `stream()` method which returns a `StreamResponse` object:

```php
$agent = agent('code-generator')
    ->provider(anthropic())
    ->build();

$streamResponse = $agent->stream('Write a PHP function to validate email addresses');

// Get the underlying generator
$stream = $streamResponse->getStream();

// Iterate through chunks manually
foreach ($stream as $chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
}

// Access metadata after streaming
$usage = $streamResponse->getUsage();
echo "Input tokens: " . ($usage['input_tokens'] ?? 0);
echo "Output tokens: " . ($usage['output_tokens'] ?? 0);

$stopReason = $streamResponse->getStopReason();
echo "Stopped because: " . $stopReason;
```

The `StreamResponse` object provides:

- `getStream()`: Returns the underlying PHP Generator for manual iteration
- `collect()`: Iterates through all chunks and returns the full content
- `streamTo(callable $callback)`: Streams to a callback function
- `getFullContent()`: Returns collected content (after streaming)
- `getChunks()`: Returns array of all received StreamChunk objects
- `getUsage()`: Returns token usage statistics
- `getStopReason()`: Returns why generation stopped ("end_turn", "max_tokens", etc.)
- `getProvider()`: Returns provider name ("anthropic", "openai", etc.)
- `getModel()`: Returns model identifier

This API gives you fine-grained control over the streaming process and access to important metadata.

## Understanding StreamChunk

Every piece of streamed data arrives as a `StreamChunk` object. Understanding its structure is key to processing streams effectively:

```php
$agent = agent('assistant')
    ->provider(anthropic())
    ->build();

$agent->streamTo('Count to five', function ($chunk) {
    // Check chunk type
    if ($chunk->isStart()) {
        echo "[Stream started]\n";
    }

    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }

    if ($chunk->isEnd()) {
        echo "\n[Stream ended]\n";

        // Access metadata from final chunk
        $usage = $chunk->getMetadata('usage');
        if ($usage) {
            echo "Tokens used: " . ($usage['total_tokens'] ?? 'unknown');
        }
    }

    if ($chunk->isError()) {
        error_log("Stream error: " . $chunk->content);
    }
});
```

The `StreamChunk` provides these methods:

- `isText()`: True for text content chunks
- `isStart()`: True for stream start marker
- `isEnd()`: True for stream completion marker
- `isError()`: True for error chunks
- `isToolCall()`: True for tool calling chunks (not processed during streaming)
- `getText()`: Returns content (same as accessing `$chunk->content`)
- `getMetadata(string $key, mixed $default = null)`: Retrieves metadata like usage stats

And these public properties:

- `type`: Chunk type string ("text", "start", "done", "error", etc.)
- `content`: The actual text content
- `delta`: Additional delta information (optional)
- `metadata`: Array of metadata (optional)
- `isComplete`: Boolean indicating stream completion

Most of the time, you'll only need to check `isText()` and access `content`, but the full API gives you granular control when needed.

## The collect() Pattern

Sometimes you want streaming's architecture but need the full content before proceeding. The `collect()` method handles this elegantly:

```php
$agent = agent('analyzer')
    ->provider(anthropic())
    ->build();

$streamResponse = $agent->stream('Analyze this data: ' . $largeDataset);

// collect() iterates through the entire stream and returns full content
$analysis = $streamResponse->collect();

// Now we have the complete response
echo "Analysis complete. Length: " . strlen($analysis) . " characters\n";

// Access streaming metadata
$chunks = $streamResponse->getChunks();
echo "Received " . count($chunks) . " chunks\n";

$usage = $streamResponse->getUsage();
echo "Tokens: " . ($usage['total_tokens'] ?? 'unknown');
```

The `collect()` method:

- Iterates through the entire stream automatically
- Accumulates all text content
- Collects metadata from the final chunk (usage, stop reason)
- Stores all chunks for inspection
- Returns the complete content as a string

This is useful when you need streaming's underlying architecture (perhaps for future enhancement) but currently need the full response before processing.

## Real-Time Display Patterns

Let's build some practical streaming interfaces. Here's a console progress indicator:

```php
$agent = agent('writer')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->maxTokens(2000)
    ->build();

$charCount = 0;
$wordCount = 0;

$content = $agent->streamTo(
    'Write a 500-word essay about PHP',
    function ($chunk) use (&$charCount, &$wordCount) {
        if ($chunk->isText()) {
            echo $chunk->content;
            flush();

            // Track metrics in real-time
            $charCount += strlen($chunk->content);
            $words = str_word_count($chunk->content);
            $wordCount += $words;

            // Show progress every 50 words
            if ($wordCount % 50 === 0 && $words > 0) {
                echo "\n[{$wordCount} words so far...]\n";
            }
        }

        if ($chunk->isEnd()) {
            echo "\n\n";
            echo "Final count: {$wordCount} words, {$charCount} characters\n";
        }
    }
);
```

This pattern shows how you can track metrics and display progress as content streams. The callback closure can capture variables by reference (`use (&$varName)`) to maintain state across chunks.

## Building a Chatbot Interface

Here's a more complete example showing how to build a streaming chatbot:

```php
$agent = agent('chatbot')
    ->provider(anthropic())
    ->system('You are a helpful, friendly chatbot. Keep responses concise.')
    ->build();

function displayResponse(string $prompt): void
{
    global $agent;

    echo "You: {$prompt}\n";
    echo "Bot: ";

    $startTime = microtime(true);

    $content = $agent->streamTo($prompt, function ($chunk) {
        if ($chunk->isText()) {
            echo $chunk->content;
            flush();
        }
    });

    $duration = round((microtime(true) - $startTime) * 1000);

    echo "\n";
    echo "(Generated in {$duration}ms)\n\n";
}

// Have a conversation
displayResponse('Hello! What can you help me with?');
displayResponse('Tell me about PHP generators');
displayResponse('How do they relate to streaming?');

// Conversation history is maintained
echo "Total exchanges: " . (count($agent->messages) / 2) . "\n";
```

This demonstrates several important patterns:

- Streaming works seamlessly with conversation history
- Each `streamTo()` call automatically adds messages to history
- You can measure generation time around streaming calls
- The agent maintains context across multiple streaming exchanges

## Error Handling and Edge Cases

Streaming introduces some unique error scenarios. Here's how to handle them robustly:

```php
$agent = agent('safe-streamer')
    ->provider(anthropic())
    ->build();

try {
    $content = $agent->streamTo('Generate content', function ($chunk) {
        if ($chunk->isError()) {
            // Handle errors reported in stream
            throw new RuntimeException("Stream error: " . $chunk->content);
        }

        if ($chunk->isText()) {
            echo $chunk->content;
            flush();
        }
    });

    // Verify we got content
    if (empty($content)) {
        throw new RuntimeException("Stream completed but no content received");
    }

} catch (RuntimeException $e) {
    // Handle provider errors (network issues, auth failures, etc.)
    echo "Streaming failed: " . $e->getMessage() . "\n";

    // Fall back to non-streaming
    $response = $agent->prompt('Generate content');
    echo $response->content;
}
```

Common error scenarios:

- Invalid API keys throw `RuntimeException` before streaming starts
- Network interruptions throw exceptions during streaming
- Provider errors appear as error chunks in the stream
- Empty streams (rare but possible) should be validated

## Provider-Specific Streaming Formats

Pagent abstracts away the differences, but it's helpful to understand what's happening under the hood:

**Anthropic** uses Server-Sent Events (SSE). The underlying format looks like:

```
event: message_start
data: {"type":"message_start","message":{"id":"msg_123"...}}

event: content_block_delta
data: {"type":"content_block_delta","delta":{"text":"Hello"}}

event: message_stop
data: {"type":"message_stop"}
```

Pagent's `AnthropicStreamParser` parses these SSE events and converts them into normalized `StreamChunk` objects.

**OpenAI** uses newline-delimited JSON (NDJSON). Each line is a JSON object:

```json
{"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"delta":{"content":"Hello"}}]}
{"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"delta":{"content":" world"}}]}
{"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"finish_reason":"stop"}]}
```

Pagent's `OpenAIStreamParser` handles this format and produces the same `StreamChunk` interface.

**Ollama** also uses NDJSON, similar to OpenAI.

This abstraction means your code works identically across all providers - you always receive `StreamChunk` objects regardless of the underlying protocol.

## Streaming and Memory Integration

When using memory adapters, `streamTo()` automatically saves the complete conversation:

```php
$agent = agent('persistent-chat')
    ->provider(anthropic())
    ->memory('file', ['path' => '/tmp/conversations'])
    ->sessionId('user-123')
    ->build();

// First session
$agent->streamTo('Remember: my name is Alice', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});

// Later session with same sessionId
$agent = agent('persistent-chat')
    ->provider(anthropic())
    ->memory('file', ['path' => '/tmp/conversations'])
    ->sessionId('user-123')
    ->build();

$agent->streamTo("What's my name?", function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;  // "Your name is Alice"
        flush();
    }
});
```

The memory integration works identically to `prompt()`:

- Conversation loads automatically on first `streamTo()` call
- Each streaming response is saved to memory
- Session persistence works across streaming and non-streaming calls

## Streaming and Guards

Streaming preserves the same guard phases, with one important visibility tradeoff:

```php
$agent = agent('guarded-stream')
    ->provider(anthropic())
    ->guard('pii')
    ->fallback(function ($exception) {
        return "I apologize, I cannot provide that content.";
    })
    ->build();

try {
    $content = $agent->streamTo('Tell me about space', function ($chunk) {
        if ($chunk->isText()) {
            echo $chunk->content;
            flush();
        }
    });

} catch (GuardException $e) {
    // Guard violations still throw exceptions
    // Fallback is returned in this case
}
```

Input guards always run before streaming starts. Incremental output guards may
inspect the accumulated text while chunks are forwarded. Output guards that
return `false` from `supportsIncrementalInspection()`—including the built-in PII
guard—require a buffered stream: Pagent consumes the provider response, runs the
policy and middleware, then releases chunks only if they pass. Legacy two-argument
guards are also buffered because their phase cannot be inferred safely.

This means a buffered guard prevents unsafe partial output from reaching the
callback, at the cost of real-time delivery. An incremental guard preserves
low-latency streaming but cannot retract chunks already yielded if it later
detects a violation.

## Performance Considerations

Streaming trades bandwidth efficiency for responsiveness. Each chunk creates overhead, so streaming a 50-word response might actually use slightly more bandwidth than getting it all at once. However, the user experience improvement typically outweighs this cost.

Consider streaming when:

- Responses are likely to be lengthy (>100 words)
- User experience and perceived speed matter
- You want to show progress for long-running operations
- You're building interactive chat interfaces

Stick with `prompt()` when:

- Responses are short and fast
- You need tool calling functionality
- Bandwidth efficiency is critical
- You need the complete response before proceeding

## When to Use stream() vs streamTo()

Choose `streamTo()` when:

- You want simplest integration (like upgrading from `prompt()`)
- You need automatic conversation management
- You want memory integration
- You want guard execution

Choose `stream()` when:

- You need access to `StreamResponse` metadata before consuming the stream
- You want to pass the `StreamResponse` to another function
- You need fine control over iteration
- You want to inspect chunks before processing

Both methods provide the same underlying streaming functionality - the difference is in control and convenience.

## Checking Provider Support

Not all providers or configurations support streaming. Check before attempting:

```php
$provider = anthropic();

if (method_exists($provider, 'streamPrompt')) {
    echo "Provider supports streaming\n";

    $agent = agent('streamer')
        ->provider($provider)
        ->build();

    // Safe to use streaming
    $agent->streamTo('Hello', fn($c) => $c->isText() && print($c->content));
} else {
    echo "Provider does not support streaming\n";
}
```

Currently, Anthropic, OpenAI, and Ollama providers all support streaming. The Mock provider does not implement streaming (it returns complete responses immediately).

If you call `stream()` or `streamTo()` on an agent with a non-streaming provider, you'll get a clear `RuntimeException`:

```
Provider Pagent\Providers\Mock does not support streaming. Use the prompt() method instead.
```

## Summary

Streaming brings real-time responsiveness to your LLM applications. The key concepts:

- Use `streamTo(message, callback)` for simple streaming with automatic conversation management
- Use `stream(message)` for more control via `StreamResponse`
- Process chunks by checking `isText()`, `isStart()`, `isEnd()`
- Access metadata like token usage and stop reason after streaming
- Streaming works with memory, guards, and conversation history
- Tool calling is not supported during streaming
- All major providers support streaming with transparent format handling

In the next chapter, we'll explore advanced streaming patterns including cancellation, progress tracking, and building complex streaming interfaces for production applications.
