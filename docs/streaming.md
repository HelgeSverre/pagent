# Streaming Support

Pagent supports incremental LLM streaming and Server-Sent Events (SSE), enabling
responses to appear as the provider produces them. `stream()` starts a lifecycle
aware stream; consuming it finalizes history, memory, events, and telemetry.

Streams with no response-transforming middleware and only incrementally-safe output
guards are delivered immediately. Pagent intentionally quarantines a stream before
delivery when a policy needs the complete response: a non-incremental
`OutputGuard`, a legacy two-argument guard, or middleware. This prevents unsafe
content from reaching a callback at the cost of time to first token.

## Table of Contents

- [Quick Start](#quick-start)
- [Basic Usage](#basic-usage)
- [Advanced Usage](#advanced-usage)
- [Server-Sent Events (SSE) Endpoint](#server-sent-events-sse-endpoint)
- [API Reference](#api-reference)
- [Provider Support](#provider-support)

## Quick Start

### Simple Streaming with Callback

Stream responses and display them as they arrive:

```php
use Pagent\Providers\Anthropic;
use function Pagent\agent;

$agent = agent('streamer')
    ->provider(new Anthropic())
    ->model('claude-sonnet-4-6');

// Stream to console
$agent->streamTo('Tell me a joke', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});
```

### Manual Stream Control

Get fine-grained control over the streaming process:

```php
$streamResponse = $agent->stream('What is PHP?');

foreach ($streamResponse->getStream() as $chunk) {
    if ($chunk->isStart()) {
        echo "[Stream started]\n";
    }

    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }

    if ($chunk->isEnd()) {
        echo "\n[Stream complete]\n";
        $usage = $chunk->getMetadata('usage') ?? [];
        echo "Tokens used: " . ($usage['total_tokens'] ?? 0);
    }
}
```

## Basic Usage

### Agent::streamTo()

The `streamTo()` method streams responses to a callback function and returns the full content:

```php
$fullContent = $agent->streamTo('Your question', function ($chunk) {
    // Handle each chunk
    if ($chunk->isText()) {
        echo $chunk->content;
    }
});

echo "\n\nFull response: " . $fullContent;
```

**Method Signature:**

```php
public function streamTo(
    string $message,
    callable $callback,
    array $options = []
): string
```

**Parameters:**

- `$message`: The prompt to send
- `$callback`: Function called for each chunk
- `$options`: Optional configuration (model, temperature, etc.)

**Returns:** Full accumulated response text

### Agent::stream()

The `stream()` method returns a `StreamResponse` object for manual iteration:

```php
$streamResponse = $agent->stream('Your question');

// Option 1: Iterate manually
foreach ($streamResponse->getStream() as $chunk) {
    // Process chunk
}

// Option 2: Collect all at once
$fullContent = $streamResponse->collect();

// Option 3: Stream to callback
$streamResponse->streamTo(function ($chunk) {
    echo $chunk->content;
});
```

**Method Signature:**

```php
public function stream(
    string $message,
    array $options = []
): StreamResponse
```

## Advanced Usage

### Chunk Types

The `StreamChunk` object provides several helper methods:

```php
$streamResponse->streamTo(function ($chunk) {
    if ($chunk->isStart()) {
        // Stream initialization
        $model = $chunk->getMetadata('model');
    }

    if ($chunk->isText()) {
        // Text content
        echo $chunk->content;
    }

    if ($chunk->isToolCall()) {
        // Tool/function call (advanced)
        $toolName = $chunk->getMetadata('tool_name');
    }

    if ($chunk->isEnd()) {
        // Stream completion
        $usage = $chunk->getMetadata('usage');
        $stopReason = $chunk->getMetadata('stop_reason');
    }
});
```

Provider and parser failures are thrown before an error chunk can reach
application output. This keeps a failed terminal event from looking like a
successful end marker.

### Accessing Metadata

StreamResponse provides metadata after streaming completes:

```php
$streamResponse = $agent->stream('Question');
$streamResponse->collect();

// Get usage statistics
$usage = $streamResponse->getUsage();
echo "Input tokens: " . ($usage['input_tokens'] ?? 0);
echo "Output tokens: " . ($usage['output_tokens'] ?? 0);

// Get stop reason
$stopReason = $streamResponse->getStopReason();

// Get provider info
$provider = $streamResponse->getProvider(); // 'anthropic' or 'openai'
$model = $streamResponse->getModel();          // provider-reported model
$requested = $streamResponse->getRequestedModel();

// Get all chunks
$chunks = $streamResponse->getChunks();
$count = $streamResponse->getChunkCount();

// Multi-round tool streams expose both views explicitly
$allDeliveredText = $streamResponse->getFullContent();
$finalAnswer = $streamResponse->getFinalContent();
```

`getModel()` begins with the requested model and changes to the concrete model
reported by the provider as chunks arrive. `getRequestedModel()` always returns
the original request value.

### Streaming Tool Calls

When an agent has registered tools, streaming uses automatic tool execution by
default. Argument fragments are assembled and validated, the tool result is
added to provider history, middleware is applied to the follow-up round, and
the public stream emits exactly one final end chunk. Usage is accumulated across
all rounds.

```php
$agent->tool('weather', 'Look up weather', fn (string $city): array => [
    'city' => $city,
    'temperature' => 18,
]);

$answer = $agent->stream('What is the weather in Oslo?');
$answer->streamTo(function ($chunk): void {
    if ($chunk->isText()) {
        echo $chunk->content;
    }
});
```

For applications that execute calls elsewhere, select manual mode and inspect
the normalized calls after consumption:

```php
$response = $agent->stream('Check Oslo', ['tool_mode' => 'manual']);
$response->collect();

foreach ($response->getToolCalls() as $call) {
    // id, name, decoded arguments, and raw_arguments
}

$final = $agent->continueToolCalls($response, [
    $response->getToolCalls()[0]['id'] => ['temperature' => 18],
]);
$final->streamTo(function ($chunk): void {
    if ($chunk->isText()) {
        echo $chunk->content;
    }
});
```

The manual response retains the assistant tool-call message in the active agent
but defers persistent-memory writes until the tool results complete the turn.
Supply exactly one result for every call id with
`continueToolCalls()`. Until then, the agent rejects new prompts so it cannot send
an invalid dangling tool-call history; it also rejects provider, memory, and
session changes that would detach the pending turn. Use
`$agent->discardToolCalls($response)` to abandon the prompt and atomically restore
its previous conversation state.

Use `tool_mode => 'none'` to omit tool schemas. Tool chunks include a
`tool_round` metadata value when more than one provider round is involved.
For automatic multi-round tools, `getFullContent()` contains every text chunk
delivered across rounds, while `getFinalContent()` contains only the final answer.

### Error Handling

```php
try {
    $agent->streamTo('Question', function ($chunk) {
        if ($chunk->isText()) {
            echo $chunk->content;
        }
    });
} catch (RuntimeException $e) {
    echo "Streaming error: " . $e->getMessage();
}
```

## Server-Sent Events (SSE) Endpoint

Create real-time streaming endpoints for web applications:

### Backend (PHP)

```php
<?php
require 'vendor/autoload.php';

use Pagent\Providers\Anthropic;
use function Pagent\agent;

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

function sendSSE(string $event, mixed $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

$question = $_GET['q'] ?? 'Hello';

$agent = agent('sse-agent')
    ->provider(new Anthropic())
    ->model('claude-sonnet-4-6');

$streamResponse = $agent->stream($question);

foreach ($streamResponse->getStream() as $chunk) {
    if ($chunk->isText()) {
        sendSSE('token', ['text' => $chunk->content]);
    }

    if ($chunk->isEnd()) {
        sendSSE('done', [
            'usage' => $chunk->getMetadata('usage'),
            'stop_reason' => $chunk->getMetadata('stop_reason')
        ]);
    }
}

sendSSE('complete', ['status' => 'success']);
```

### Frontend (JavaScript)

```javascript
const eventSource = new EventSource("stream.php?q=" + encodeURIComponent(question));

eventSource.addEventListener("token", (e) => {
  const data = JSON.parse(e.data);
  responseElement.textContent += data.text;
});

eventSource.addEventListener("done", (e) => {
  const data = JSON.parse(e.data);
  console.log("Usage:", data.usage);
  console.log("Stop reason:", data.stop_reason);
});

eventSource.addEventListener("complete", (e) => {
  eventSource.close();
});
```

### Complete Example

See `examples/streaming-sse-endpoint.php` and `examples/streaming-sse-client.html` for a complete working example with a beautiful UI.

**To run the example:**

```bash
# Start PHP server
php -S localhost:8000 examples/streaming-sse-endpoint.php

# Open in browser
open http://localhost:8000/streaming-sse-client.html
```

## API Reference

### StreamChunk

Represents a single chunk of streaming data.

#### Methods

- `isText(): bool` - Check if chunk contains text content
- `isToolCall(): bool` - Check if chunk is a tool call
- `isStart(): bool` - Check if chunk marks stream start
- `isEnd(): bool` - Check if chunk marks stream end
- `isError(): bool` - Check if chunk is an error
- `getText(): string` - Get text content
- `getMetadata(string $key, mixed $default = null): mixed` - Get metadata value

#### Static Constructors

- `StreamChunk::text(string $content, ?array $metadata)` - Create text chunk
- `StreamChunk::start(?array $metadata)` - Create start chunk
- `StreamChunk::end(?array $metadata)` - Create end chunk
- `StreamChunk::error(string $message, ?array $metadata)` - Create error chunk

### StreamResponse

Container for streaming responses.

#### Methods

- `getStream(): Generator<StreamChunk>` - Get the underlying stream
- `collect(): string` - Collect all text content
- `streamTo(callable $callback): void` - Stream chunks to callback
- `getFullContent(): string` - Get accumulated text
- `getFinalContent(): string` - Get only the final provider round's text
- `getChunks(): StreamChunk[]` - Get all chunks
- `getChunkCount(): int` - Get the delivered chunk count, including chunks not retained
- `getToolCalls(): array` - Get normalized completed tool calls
- `getUsage(): ?array` - Get token usage statistics
- `getStopReason(): ?string` - Get stop reason
- `getProvider(): string` - Get provider name
- `getModel(): string` - Get the provider-reported model after it becomes available
- `getRequestedModel(): string` - Get the originally requested model

### Agent Streaming Methods

#### stream()

```php
public function stream(string $message, array $options = []): StreamResponse
```

Stream a prompt and return a StreamResponse for manual control.

**Throws:** `RuntimeException` if provider doesn't support streaming

#### continueToolCalls()

```php
public function continueToolCalls(
    StreamResponse $response,
    array $results,
    array $options = []
): StreamResponse
```

Continue a pending manual tool turn. Results are keyed by tool-call id.
`discardToolCalls(StreamResponse $response)` abandons it instead.

#### streamTo()

```php
public function streamTo(string $message, callable $callback, array $options = []): string
```

Stream a prompt to a callback and return the full content.

**Throws:** `RuntimeException` if provider doesn't support streaming

## Provider Support

### Anthropic (Claude)

Full support for streaming including:

- Text streaming via `content_block_delta` events
- Tool use streaming via `input_json_delta`
- Thinking blocks via `thinking_delta` (extended thinking feature)
- Usage statistics in final chunks
- Multiple content blocks

Use an available Anthropic streaming model, such as `claude-sonnet-4-6`. Model
availability is provider-controlled; configure a model explicitly for production.

### OpenAI (GPT)

Full support for streaming including:

- Text streaming via delta content
- Tool/function call streaming
- Usage statistics (when available)
- Finish reasons

**Supported Models:**

- GPT-4
- GPT-4 Turbo
- GPT-3.5 Turbo
- All chat completion models

## Performance Considerations

### Buffering

Provider transport streaming is incremental by default. For production SSE
endpoints, also disable web-server output buffering:

```php
// Disable all output buffering
if (ob_get_level()) {
    ob_end_clean();
}

// Set headers
header('X-Accel-Buffering: no'); // Disable nginx buffering
```

### Timeouts

Built-in providers do not impose a total streaming timeout by default. They use
a 10-second connection timeout and a 30-second idle timeout. An explicitly
configured provider `timeout` also becomes the stream timeout unless the more
specific `stream_timeout` is set. Configure either value on the provider or
override it for one stream:

```php
use Pagent\Providers\OpenAI;

$provider = new OpenAI([
    'api_key' => $_ENV['OPENAI_API_KEY'],
    'stream_timeout' => 120,
    'connect_timeout' => 10,
    'idle_timeout' => 30,
]);

$response = $provider->streamPrompt('Long task', [
    'stream_timeout' => 300,
]);
```

Set `stream_timeout` or `idle_timeout` to `0` to disable that limit explicitly.
Stream establishment failures can be retried safely with
`RetryingProvider::wrap()`; failures after a `StreamResponse` is returned are
never replayed.

### Memory

Chunks are processed one at a time. Built-in providers do not spool a second
copy of successful response bytes, but `StreamResponse` retains accumulated text
and chunk objects by default. Set `retain_chunks => false` in provider config or
per-stream options to keep only accumulated text, usage, tool calls, and the chunk
count. A quarantined stream additionally retains provider chunks until its policies
pass.

## Troubleshooting

### "Provider does not support streaming"

**Error:** `RuntimeException: Provider X does not support streaming`

**Solution:** Use a provider implementing `StreamingProvider`. Built-in Anthropic,
OpenAI, OpenCode, Ollama, and Mock providers support streaming.

```php
// Correct
$agent->provider(new Anthropic());

// Also valid for deterministic stream tests
$agent->provider(mock(['Question' => 'Deterministic answer']));
```

### No Output Appearing

**Problem:** Calling `stream()` but no output appears.

**Solution:** Remember to iterate the stream or call `collect()`:

```php
// Wrong - stream not consumed
$streamResponse = $agent->stream('Question');

// Correct - iterate stream
foreach ($streamResponse->getStream() as $chunk) {
    echo $chunk->content;
}

// Or collect all at once
$fullContent = $streamResponse->collect();
```

### SSE Connection Closes Immediately

**Problem:** EventSource connection closes right away.

**Solution:** Check:

1. Output buffering is disabled
2. Headers are set correctly
3. `flush()` is called after each event
4. Server timeout is sufficient

## Examples

Streaming examples are available in the `examples/` directory:

- [`10-streaming-basic.php`](../examples/10-streaming-basic.php) - Callback and iterable streaming
- [`10-streaming-sse-endpoint.php`](../examples/10-streaming-sse-endpoint.php) - SSE server endpoint
- [`10-streaming-sse-client.html`](../examples/10-streaming-sse-client.html) - Browser SSE client

## Next Steps

- Explore [Multi-Agent Workflows](orchestration-workflows.md)
- Learn about [tool calling](../README.md#tool-calling)
- Check out [Middleware](middleware.md) for streaming interceptors

---

For questions or documentation corrections, open an issue on
[GitHub](https://github.com/helgesverre/pagent/issues).
