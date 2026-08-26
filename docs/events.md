# Events

Pagent publishes lifecycle events to a global `EventManager` and, for Agent
events, to that agent's local dispatcher. A listener registered in both scopes is
called once for one logical event.

## Listen locally or globally

Use a local listener when behavior belongs to one configured agent:

```php
use Pagent\Events\Events\Agent\AfterPromptEvent;

$assistant = agent('assistant')->provider('anthropic');
$assistant->on('after_prompt', function (AfterPromptEvent $event): void {
    echo $event->response;
});
```

Use the singleton for application-wide metrics and audit handling:

```php
use Pagent\Events\EventManager;
use Pagent\Events\Events\Tool\ToolExecutedEvent;

EventManager::instance()->on(
    'tool_executed',
    function (ToolExecutedEvent $event): void {
        log_tool_usage($event->toolName, $event->result, $event->durationMs);
    },
);
```

## Lifecycle events

| Category | Events                                                                               | Useful fields                                            |
| -------- | ------------------------------------------------------------------------------------ | -------------------------------------------------------- |
| Agent    | `BeforePromptEvent`, `AfterPromptEvent`, `ContextPrunedEvent`                        | agent, message, response, tokens saved                   |
| Provider | `BeforeLLMRequestEvent`, `AfterLLMResponseEvent`                                     | provider, model, request payload/response data, duration |
| Tool     | `ToolExecutingEvent`, `ToolExecutedEvent`, `ToolErrorEvent`                          | tool name, parameters, result/error, duration            |
| Guard    | `GuardCheckingEvent`, `GuardPassedEvent`, `GuardViolatedEvent`, `GuardFallbackEvent` | guard name, checked content, reason                      |
| Memory   | `MemoryLoadingEvent`, `MemoryLoadedEvent`, `MemorySavingEvent`, `MemorySavedEvent`   | key, value, namespace                                    |
| Stream   | `StreamStartedEvent`, `StreamChunkEvent`, `StreamCompletedEvent`                     | provider, model, delivered text chunks, usage            |
| MCP      | connection, discovery, tool call events                                              | client/server and tool details                           |

Provider events are emitted for every provider round, including a round after a
tool call. `AfterPromptEvent` is emitted only when the complete turn has been
accepted or a fallback has been applied.

## Provider usage example

`AfterLLMResponseEvent` exposes raw normalized response data rather than a
provider-specific token object, so read the usage map defensively:

```php
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;

$assistant->on('after_llm_response', function (AfterLLMResponseEvent $event): void {
    $usage = $event->responseData['usage'] ?? [];
    $tokens = $usage['total_tokens']
        ?? (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0));

    logger()->info('Provider round completed', [
        'provider' => $event->provider,
        'model' => $event->model,
        'tokens' => $tokens,
        'duration_ms' => $event->durationMs,
    ]);
});
```

## Guard and stream events

Input guard events occur before a request reaches a provider or tool. Output guard
events occur before final content is committed or delivered. With a quarantined
stream, `StreamStartedEvent` still represents provider activity, while text is not
released until the policy accepts the complete response.

```php
use Pagent\Events\Events\Guard\GuardViolatedEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;

$assistant->on('guard_violated', function (GuardViolatedEvent $event): void {
    logger()->warning('Guard blocked content', [
        'guard' => $event->guardName,
        'reason' => $event->reason,
    ]);
});
$assistant->on('stream_completed', function (StreamCompletedEvent $event): void {
    logger()->info('Stream completed', [
        'chunks' => $event->totalChunks,
        'usage' => $event->usage,
    ]);
});
```

`StreamChunkEvent` is emitted for every delivered text chunk. Its `chunk` field
contains the text and `chunkNumber` is one-based. Quarantined streams emit these
events only after the complete response passes its policies, matching what a
`streamTo()` callback receives.

See [guards](guards.md), [streaming](streaming.md), and
[observability](observability.md) for focused examples.
