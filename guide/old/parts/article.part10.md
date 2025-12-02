# Chapter 10: Streaming Fundamentals

## What You'll Learn

In this chapter, you'll master streaming responses in Pagent, enabling real-time communication with language models. By the end, you'll be able to:

- Enable and configure streaming responses from LLMs
- Handle Server-Sent Events (SSE) and NDJSON stream formats
- Process partial responses as they arrive
- Implement stream interruption and error handling
- Build real-time user interfaces with streaming updates

## Prerequisites

Before starting this chapter, you should have completed:

- Chapter 1: Getting Started
- Chapter 2: Basic Agent Creation
- Chapter 3: Provider Configuration
- Chapter 4: Message Management
- Chapter 5: Tool Functions

**Time Estimate:** 45-60 minutes

**Final Result:** A streaming-enabled chatbot with real-time response display and progress indicators

## Understanding Streaming

Traditional LLM interactions follow a request-response pattern where you wait for the complete response before processing it. Streaming changes this by delivering the response incrementally as it's generated, providing immediate feedback to users.

Think of it like watching a live video stream versus downloading the entire video first. Streaming gives you content as it becomes available, improving perceived performance and user experience.

## Enabling Streaming Responses

Let's start with the simplest streaming implementation:

```php
use Pagent\Agent;
use function Pagent\anthropic;

$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()  // Enable streaming
    ->agent('assistant');

$response = $agent->reply('Write a haiku about coding');

// The response is now a stream
foreach ($response as $chunk) {
    echo $chunk;  // Display each piece as it arrives
}
```

Run this code and watch the haiku appear word by word, just as if someone were typing it in real-time.

### Stream Configuration Options

Pagent provides several streaming configuration methods:

```php
$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()           // Enable streaming (default)
    ->streamRaw()        // Get raw stream chunks
    ->streamChunks()     // Get structured chunk objects
    ->agent('assistant');
```

Each mode serves different purposes:

- `stream()`: Returns content strings, ideal for simple display
- `streamRaw()`: Returns complete API response chunks for advanced processing
- `streamChunks()`: Returns parsed chunk objects with metadata

## Processing Stream Chunks

Let's explore different ways to handle streaming data:

### Basic Content Streaming

```php
$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()
    ->agent('streaming assistant');

$stream = $agent->reply('Explain quantum computing in simple terms');

// Method 1: Direct iteration
foreach ($stream as $content) {
    echo $content;
    flush();  // Ensure immediate browser output
}
```

### Structured Chunk Processing

For more control, use structured chunks:

```php
$agent = anthropic('claude-3-5-sonnet-latest')
    ->streamChunks()
    ->agent('detailed assistant');

$stream = $agent->reply('Generate a JSON configuration file');

$fullContent = '';
$tokenCount = 0;

foreach ($stream as $chunk) {
    // Chunk object provides structured data
    if ($chunk->delta) {
        $fullContent .= $chunk->delta;
        $tokenCount++;

        echo $chunk->delta;

        // Display progress
        if ($tokenCount % 10 === 0) {
            error_log("Tokens processed: {$tokenCount}");
        }
    }

    // Check for completion
    if ($chunk->isFinished()) {
        echo "\n\nGeneration complete. Total tokens: {$tokenCount}\n";
    }
}
```

### Raw Stream Access

For complete control over the streaming process:

```php
$agent = anthropic('claude-3-5-sonnet-latest')
    ->streamRaw()
    ->agent('raw stream handler');

$stream = $agent->reply('List 5 programming languages');

foreach ($stream as $rawChunk) {
    // Access raw API response structure
    $event = $rawChunk['type'] ?? null;

    switch ($event) {
        case 'content_block_start':
            echo "\n[Starting new content block]\n";
            break;

        case 'content_block_delta':
            $text = $rawChunk['delta']['text'] ?? '';
            echo $text;
            break;

        case 'content_block_stop':
            echo "\n[Content block complete]\n";
            break;

        case 'message_stop':
            echo "\n[Message complete]\n";
            break;
    }
}
```

## Building a Real-Time Chatbot Interface

Let's create a practical streaming chatbot with a web interface:

```php
// streaming-chat.php
<?php
require 'vendor/autoload.php';

use Pagent\Agent;
use function Pagent\anthropic;

// Enable output buffering control
ob_implicit_flush(true);
ob_end_flush();

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable Nginx buffering

// Get user message from query parameter
$userMessage = $_GET['message'] ?? 'Hello';

// Create streaming agent
$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()
    ->agent('helpful assistant');

try {
    $stream = $agent->reply($userMessage);

    foreach ($stream as $chunk) {
        // Format as SSE
        echo "data: " . json_encode(['content' => $chunk]) . "\n\n";

        // Force output to browser
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // Small delay to prevent overwhelming the client
        usleep(10000); // 10ms
    }

    // Send completion event
    echo "data: " . json_encode(['done' => true]) . "\n\n";

} catch (Exception $e) {
    // Send error event
    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
}
```

And the accompanying HTML client:

```html
<!-- chat-client.html -->
<!DOCTYPE html>
<html>
  <head>
    <title>Streaming Chat</title>
    <style>
      #chat-output {
        border: 1px solid #ccc;
        padding: 10px;
        height: 400px;
        overflow-y: auto;
        white-space: pre-wrap;
        font-family: monospace;
      }

      .typing-indicator {
        display: none;
        color: #666;
      }

      .typing-indicator.active {
        display: inline;
      }

      .typing-indicator::after {
        content: "...";
        animation: dots 1.5s infinite;
      }

      @keyframes dots {
        0%,
        20% {
          content: ".";
        }
        40% {
          content: "..";
        }
        60%,
        100% {
          content: "...";
        }
      }
    </style>
  </head>
  <body>
    <h1>Streaming Chat Interface</h1>

    <input type="text" id="message-input" placeholder="Type your message..." />
    <button onclick="sendMessage()">Send</button>

    <div id="chat-output"></div>
    <span class="typing-indicator" id="typing">Assistant is typing</span>

    <script>
      let eventSource = null;

      function sendMessage() {
        const input = document.getElementById("message-input");
        const output = document.getElementById("chat-output");
        const typing = document.getElementById("typing");
        const message = input.value;

        if (!message) return;

        // Display user message
        output.innerHTML += `\nYou: ${message}\n`;
        output.innerHTML += "Assistant: ";
        input.value = "";

        // Show typing indicator
        typing.classList.add("active");

        // Close previous connection if exists
        if (eventSource) {
          eventSource.close();
        }

        // Create new SSE connection
        eventSource = new EventSource(`streaming-chat.php?message=${encodeURIComponent(message)}`);

        eventSource.onmessage = function (event) {
          const data = JSON.parse(event.data);

          if (data.content) {
            // Append streaming content
            output.innerHTML += data.content;
            output.scrollTop = output.scrollHeight;
          } else if (data.done) {
            // Stream complete
            typing.classList.remove("active");
            output.innerHTML += "\n";
            eventSource.close();
          } else if (data.error) {
            // Handle error
            output.innerHTML += `\n[Error: ${data.error}]\n`;
            typing.classList.remove("active");
            eventSource.close();
          }
        };

        eventSource.onerror = function () {
          typing.classList.remove("active");
          output.innerHTML += "\n[Connection error]\n";
          eventSource.close();
        };
      }
    </script>
  </body>
</html>
```

## Implementing Progress Indicators

For long-running generations, provide visual feedback:

```php
// progress-generator.php
<?php
use Pagent\Agent;
use function Pagent\anthropic;

class ProgressTracker {
    private int $totalChunks = 0;
    private float $startTime;
    private string $content = '';

    public function __construct() {
        $this->startTime = microtime(true);
    }

    public function processChunk(string $chunk): array {
        $this->totalChunks++;
        $this->content .= $chunk;

        $elapsed = microtime(true) - $this->startTime;
        $charsPerSecond = strlen($this->content) / max($elapsed, 0.001);

        return [
            'chunk' => $chunk,
            'progress' => [
                'chunks' => $this->totalChunks,
                'characters' => strlen($this->content),
                'elapsed' => round($elapsed, 2),
                'speed' => round($charsPerSecond),
                'estimated_remaining' => $this->estimateRemaining(),
            ]
        ];
    }

    private function estimateRemaining(): ?float {
        // Rough estimation based on typical response length
        $avgResponseLength = 500; // characters
        $currentLength = strlen($this->content);

        if ($currentLength < 50) {
            return null; // Too early to estimate
        }

        $elapsed = microtime(true) - $this->startTime;
        $rate = $currentLength / $elapsed;
        $remaining = ($avgResponseLength - $currentLength) / $rate;

        return max(0, round($remaining, 1));
    }
}

// Usage
$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()
    ->agent('verbose assistant');

$tracker = new ProgressTracker();
$stream = $agent->reply('Write a detailed explanation of machine learning');

foreach ($stream as $chunk) {
    $progress = $tracker->processChunk($chunk);

    // Send progress update to client
    echo json_encode($progress) . "\n";
    flush();
}
```

## Stream Interruption and Error Handling

Implement graceful stream interruption:

```php
class InterruptibleStream {
    private bool $interrupted = false;
    private $stream;

    public function __construct($stream) {
        $this->stream = $stream;

        // Register signal handler for interruption
        pcntl_signal(SIGINT, [$this, 'handleInterrupt']);
    }

    public function handleInterrupt(int $signal): void {
        $this->interrupted = true;
        echo "\n[Stream interrupted by user]\n";
    }

    public function process(callable $callback): string {
        $fullContent = '';

        try {
            foreach ($this->stream as $chunk) {
                // Check for interruption
                pcntl_signal_dispatch();

                if ($this->interrupted) {
                    break;
                }

                $fullContent .= $chunk;
                $callback($chunk);

                // Simulate processing time
                usleep(50000); // 50ms
            }
        } catch (Exception $e) {
            echo "\n[Stream error: {$e->getMessage()}]\n";
        } finally {
            // Cleanup
            if ($this->interrupted) {
                echo "\nPartial response received: " . strlen($fullContent) . " characters\n";
            }
        }

        return $fullContent;
    }
}

// Usage
$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()
    ->agent('interruptible assistant');

$stream = $agent->reply('Count from 1 to 100 slowly');
$handler = new InterruptibleStream($stream);

$result = $handler->process(function($chunk) {
    echo $chunk;
    flush();
});
```

## Live Code Generation

Stream code generation with syntax highlighting:

````php
// code-streamer.php
<?php
use Pagent\Agent;
use function Pagent\anthropic;

class CodeStreamer {
    private string $buffer = '';
    private bool $inCodeBlock = false;
    private string $language = '';

    public function processChunk(string $chunk): array {
        $this->buffer .= $chunk;
        $output = [];

        // Detect code block markers
        if (strpos($chunk, '```') !== false) {
            $this->detectCodeBlock();
        }

        $output['content'] = $chunk;
        $output['isCode'] = $this->inCodeBlock;
        $output['language'] = $this->language;

        return $output;
    }

    private function detectCodeBlock(): void {
        $pattern = '/```(\w+)?/';
        $matches = [];

        if (preg_match($pattern, $this->buffer, $matches)) {
            if ($this->inCodeBlock) {
                // Ending code block
                $this->inCodeBlock = false;
                $this->language = '';
            } else {
                // Starting code block
                $this->inCodeBlock = true;
                $this->language = $matches[1] ?? 'plaintext';
            }
        }
    }
}

// Generate code with streaming
$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()
    ->agent('code generator');

$streamer = new CodeStreamer();
$stream = $agent->reply('Write a Python function to calculate fibonacci numbers');

echo "<pre id='code-output'>";

foreach ($stream as $chunk) {
    $processed = $streamer->processChunk($chunk);

    if ($processed['isCode']) {
        // Apply syntax highlighting class
        echo "<code class='language-{$processed['language']}'>";
        echo htmlspecialchars($chunk);
        echo "</code>";
    } else {
        echo htmlspecialchars($chunk);
    }

    flush();
}

echo "</pre>";
````

## Performance Considerations

When working with streams, keep these performance tips in mind:

1. **Buffer Management**: Clear output buffers to ensure immediate delivery
2. **Connection Keep-Alive**: Set appropriate headers for long-running streams
3. **Error Recovery**: Implement retry logic for network interruptions
4. **Memory Usage**: Process chunks immediately instead of accumulating

```php
// Optimized streaming configuration
ini_set('output_buffering', 'off');
ini_set('implicit_flush', 1);
set_time_limit(0); // No timeout for long streams

$agent = anthropic('claude-3-5-sonnet-latest')
    ->stream()
    ->withMaxTokens(4000)  // Limit response size
    ->agent('efficient assistant');

// Process with minimal memory footprint
$wordCount = 0;
$stream = $agent->reply('Write a story');

foreach ($stream as $chunk) {
    // Process and discard
    $wordCount += str_word_count($chunk);
    echo $chunk;

    // Periodic cleanup
    if ($wordCount % 100 === 0) {
        gc_collect_cycles();
    }
}
```

## Summary

You've mastered streaming fundamentals in Pagent:

- **Enabled streaming** with `stream()`, `streamRaw()`, and `streamChunks()` methods
- **Processed partial responses** using different chunk formats
- **Built real-time interfaces** with SSE and progress indicators
- **Implemented interruption** and error handling for robust streaming
- **Optimized performance** for long-running streams

Streaming transforms the user experience by providing immediate feedback and enabling real-time interactions. You can now build responsive applications that feel instant and alive.

## Next Steps

With streaming mastered, you're ready to explore:

- Chapter 11: Advanced Tool Integration
- Chapter 12: Multi-Agent Orchestration
- Chapter 13: Production Deployment

## Additional Resources

- [Server-Sent Events Specification](https://html.spec.whatwg.org/multipage/server-sent-events.html)
- [PHP Streaming Best Practices](https://www.php.net/manual/en/features.connection-handling.php)
- [Pagent Streaming Examples](https://github.com/hguenot/pagent/tree/main/examples/streaming)
