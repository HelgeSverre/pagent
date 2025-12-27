# Events

The event system allows you to hook into agent lifecycle events for logging, monitoring, analytics, or custom behavior. Events are fired at key points during agent execution.

## Event Categories

| Category | Events | Purpose |
|----------|--------|---------|
| **Agent** | BeforePrompt, AfterPrompt, ContextPruned | Agent lifecycle |
| **LLM** | BeforeLLMRequest, AfterLLMResponse | Provider calls |
| **Tool** | ToolExecuting, ToolExecuted, ToolError | Tool execution |
| **Guard** | GuardChecking, GuardPassed, GuardViolated, GuardFallback | Safety checks |
| **Memory** | MemoryLoading, MemoryLoaded, MemorySaving, MemorySaved | Persistence |
| **Stream** | StreamStarted, StreamChunk, StreamCompleted | Streaming |
| **MCP** | Connection, Disconnect, ToolDiscovery, ToolCalls | MCP protocol |

## Listening to Events

### On Specific Agent

```php
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;

agent('assistant')
    ->provider('anthropic')
    ->on(AfterLLMResponseEvent::class, function($event) {
        echo "Tokens used: " . $event->tokens;
    })
    ->prompt('Hello');
```

### Global Listener

```php
use Pagent\Events\EventManager;
use Pagent\Events\Events\Tool\ToolExecutedEvent;

EventManager::on(ToolExecutedEvent::class, function($event) {
    log_tool_usage($event->toolName, $event->result);
});
```

## Agent Events

### BeforePromptEvent

Fired before the agent processes a prompt.

```php
use Pagent\Events\Events\Agent\BeforePromptEvent;

agent('assistant')
    ->on(BeforePromptEvent::class, function($event) {
        echo "Agent: " . $event->agent->getName();
        echo "Message: " . $event->message;
    });
```

### AfterPromptEvent

Fired after the agent returns a response.

```php
use Pagent\Events\Events\Agent\AfterPromptEvent;

agent('assistant')
    ->on(AfterPromptEvent::class, function($event) {
        echo "Response: " . $event->response->content;
        echo "Tokens: " . $event->response->tokens;
    });
```

### ContextPrunedEvent

Fired when the context window is pruned to fit token limits.

```php
use Pagent\Events\Events\Agent\ContextPrunedEvent;

agent('assistant')
    ->on(ContextPrunedEvent::class, function($event) {
        echo "Messages before: " . $event->messagesBefore;
        echo "Messages after: " . $event->messagesAfter;
        echo "Strategy: " . $event->strategy;
    });
```

## LLM Events

### BeforeLLMRequestEvent

Fired before calling the LLM provider.

```php
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;

agent('assistant')
    ->on(BeforeLLMRequestEvent::class, function($event) {
        echo "Provider: " . $event->provider;
        echo "Model: " . $event->model;
        // $event->options contains full request options
    });
```

### AfterLLMResponseEvent

Fired after receiving an LLM response.

```php
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;

agent('assistant')
    ->on(AfterLLMResponseEvent::class, function($event) {
        echo "Provider: " . $event->provider;
        echo "Model: " . $event->model;
        echo "Duration: " . $event->durationMs . "ms";
        echo "Input tokens: " . $event->inputTokens;
        echo "Output tokens: " . $event->outputTokens;
    });
```

## Tool Events

### ToolExecutingEvent

Fired before a tool executes.

```php
use Pagent\Events\Events\Tool\ToolExecutingEvent;

agent('assistant')
    ->on(ToolExecutingEvent::class, function($event) {
        echo "Executing: " . $event->toolName;
        print_r($event->arguments);
    });
```

### ToolExecutedEvent

Fired after a tool completes successfully.

```php
use Pagent\Events\Events\Tool\ToolExecutedEvent;

agent('assistant')
    ->on(ToolExecutedEvent::class, function($event) {
        echo "Tool: " . $event->toolName;
        echo "Result: " . print_r($event->result, true);
        echo "Duration: " . $event->durationMs . "ms";
    });
```

### ToolErrorEvent

Fired when a tool throws an exception.

```php
use Pagent\Events\Events\Tool\ToolErrorEvent;

agent('assistant')
    ->on(ToolErrorEvent::class, function($event) {
        echo "Tool failed: " . $event->toolName;
        echo "Error: " . $event->exception->getMessage();
    });
```

## Guard Events

### GuardCheckingEvent

Fired before a guard runs.

```php
use Pagent\Events\Events\Guard\GuardCheckingEvent;

agent('assistant')
    ->on(GuardCheckingEvent::class, function($event) {
        echo "Checking guard: " . $event->guardName;
    });
```

### GuardPassedEvent

Fired when a guard check passes.

```php
use Pagent\Events\Events\Guard\GuardPassedEvent;

agent('assistant')
    ->on(GuardPassedEvent::class, function($event) {
        echo "Guard passed: " . $event->guardName;
    });
```

### GuardViolatedEvent

Fired when a guard blocks a response.

```php
use Pagent\Events\Events\Guard\GuardViolatedEvent;

agent('assistant')
    ->on(GuardViolatedEvent::class, function($event) {
        log_security_violation($event->guardName, $event->input, $event->output);
    });
```

### GuardFallbackEvent

Fired when a fallback is used after guard violation.

```php
use Pagent\Events\Events\Guard\GuardFallbackEvent;

agent('assistant')
    ->on(GuardFallbackEvent::class, function($event) {
        echo "Used fallback for: " . $event->guardName;
        echo "Fallback response: " . $event->fallbackResponse;
    });
```

## Memory Events

```php
use Pagent\Events\Events\Memory\MemoryLoadedEvent;
use Pagent\Events\Events\Memory\MemorySavedEvent;

agent('assistant')
    ->on(MemoryLoadedEvent::class, function($event) {
        echo "Loaded " . count($event->messages) . " messages";
        echo "Session: " . $event->sessionId;
    })
    ->on(MemorySavedEvent::class, function($event) {
        echo "Saved " . count($event->messages) . " messages";
    });
```

## Stream Events

```php
use Pagent\Events\Events\Stream\StreamStartedEvent;
use Pagent\Events\Events\Stream\StreamChunkEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;

agent('assistant')
    ->on(StreamStartedEvent::class, fn($e) => echo "Stream started")
    ->on(StreamChunkEvent::class, fn($e) => echo $e->chunk)
    ->on(StreamCompletedEvent::class, fn($e) => echo "\nDone: {$e->totalTokens} tokens");
```

## MCP Events

For Model Context Protocol integrations:

```php
use Pagent\Events\Events\Mcp\McpConnectionEstablishedEvent;
use Pagent\Events\Events\Mcp\McpToolsDiscoveredEvent;
use Pagent\Events\Events\Mcp\McpToolCalledEvent;

// Track MCP server connections
EventManager::on(McpConnectionEstablishedEvent::class, function($event) {
    echo "Connected to MCP server";
});

// Track tool discovery
EventManager::on(McpToolsDiscoveredEvent::class, function($event) {
    echo "Discovered " . count($event->tools) . " tools";
});

// Track tool calls
EventManager::on(McpToolCalledEvent::class, function($event) {
    echo "Called MCP tool: " . $event->toolName;
});
```

## Event Listener Priority

Listeners with higher priority execute first:

```php
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;

agent('assistant')
    ->on(AfterLLMResponseEvent::class, $highPriorityHandler, priority: 100)
    ->on(AfterLLMResponseEvent::class, $normalHandler, priority: 0)
    ->on(AfterLLMResponseEvent::class, $lowPriorityHandler, priority: -100);
```

## Practical Examples

### Request Logging

```php
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;

$requestId = null;

agent('assistant')
    ->on(BeforeLLMRequestEvent::class, function($event) use (&$requestId) {
        $requestId = uniqid('req_');
        $this->logger->info("[$requestId] Request to {$event->provider}/{$event->model}");
    })
    ->on(AfterLLMResponseEvent::class, function($event) use (&$requestId) {
        $this->logger->info("[$requestId] Response: {$event->durationMs}ms, {$event->outputTokens} tokens");
    });
```

### Cost Tracking

```php
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;

$totalCost = 0;

agent('assistant')
    ->on(AfterLLMResponseEvent::class, function($event) use (&$totalCost) {
        $cost = calculate_cost(
            $event->provider,
            $event->model,
            $event->inputTokens,
            $event->outputTokens
        );
        $totalCost += $cost;
    });
```

### Security Monitoring

```php
use Pagent\Events\Events\Guard\GuardViolatedEvent;
use Pagent\Events\EventManager;

EventManager::on(GuardViolatedEvent::class, function($event) {
    // Log to security monitoring system
    SecurityMonitor::alert([
        'type' => 'guard_violation',
        'guard' => $event->guardName,
        'agent' => $event->agent->getName(),
        'timestamp' => time(),
    ]);
});
```

## Event Manager

The `EventManager` provides global event handling:

```php
use Pagent\Events\EventManager;

// Register global listener
EventManager::on(SomeEvent::class, $handler);

// Get the dispatcher for advanced usage
$dispatcher = EventManager::getDispatcher();
```

## See Also

- [Guards](guards.md) - Guard events in detail
- [Middleware](middleware.md) - Alternative hook system
- [Observability](observability.md) - Telemetry bridge for events
- [Streaming](streaming.md) - Stream events
