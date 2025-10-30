# Events/Hooks System Implementation Plan

**Created:** 2025-10-29
**Status:** Planning
**Target Version:** v0.8.0
**Effort Estimate:** 6-8 hours
**Breaking Change:** Yes - Refactors observability from manual span creation to event-driven architecture

---

## Executive Summary

This plan details the implementation of a comprehensive events/hooks system for Pagent that serves as the foundation for **event-driven observability**. The primary goal is to replace manual `TelemetryManager::instance()->startSpan()` calls scattered throughout `src/Agent.php` with a clean, event-driven architecture.

### Key Benefits

- **Decoupled Observability**: Separate telemetry concerns from core agent logic
- **Extensible**: Users can hook into agent lifecycle without modifying core
- **Type-Safe**: Fully typed event objects with PHPStan level 9 compliance
- **Flexible**: Hybrid closure + interface pattern (like existing Guards)
- **Powerful**: Priority system, propagation control, global/local scopes

### Breaking Change Notice

This implementation **replaces** the current manual span creation approach with event-driven span creation. Existing code using `TelemetryManager` directly will need migration. A feature flag will be provided for backward compatibility during the transition period.

---

## Architecture Overview

### Design Philosophy

Following Pagent's existing patterns:

- **Hybrid Pattern**: Support both closures (simple) and classes (reusable), like Guards
- **Fluent API**: Chainable methods following Pest-inspired design
- **Type Safety**: PHPStan level 9, strict types throughout
- **Singleton Access**: `EventManager::instance()` for global events
- **Per-Agent Scope**: Instance-level listeners via `Agent::on()`

### Core Components

```
src/Events/
├── Event.php                      # Base event class
├── EventListener.php              # Interface for class-based listeners
├── EventDispatcher.php            # Core dispatcher with priority
├── EventManager.php               # Singleton for global events
├── Events/                        # Specific event classes
│   ├── Agent/
│   │   ├── BeforePromptEvent.php
│   │   ├── AfterPromptEvent.php
│   │   └── ContextPrunedEvent.php
│   ├── LLM/
│   │   ├── BeforeLLMRequestEvent.php
│   │   ├── AfterLLMResponseEvent.php
│   │   └── TokensUsedEvent.php
│   ├── Tool/
│   │   ├── ToolExecutingEvent.php
│   │   ├── ToolExecutedEvent.php
│   │   └── ToolErrorEvent.php
│   ├── Guard/
│   │   ├── GuardCheckingEvent.php
│   │   ├── GuardPassedEvent.php
│   │   ├── GuardViolatedEvent.php
│   │   └── GuardFallbackEvent.php
│   ├── Memory/
│   │   ├── MemoryLoadingEvent.php
│   │   ├── MemoryLoadedEvent.php
│   │   ├── MemorySavingEvent.php
│   │   └── MemorySavedEvent.php
│   └── Stream/
│       ├── StreamStartedEvent.php
│       ├── StreamChunkEvent.php
│       └── StreamCompletedEvent.php
└── Bridges/
    └── TelemetryEventBridge.php   # Maps events → OpenTelemetry spans
```

---

## Event System Design

### 1. Base Event Class

**File:** `src/Events/Event.php`

```php
<?php

declare(strict_types=1);

namespace Pagent\Events;

abstract class Event
{
    private bool $propagationStopped = false;

    public readonly float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Get event name for dispatcher registration.
     * Defaults to class basename in snake_case.
     */
    public function getEventName(): string
    {
        $class = basename(str_replace('\\', '/', static::class));
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('Event', '', $class)));
    }
}
```

### 2. EventListener Interface

**File:** `src/Events/EventListener.php`

```php
<?php

declare(strict_types=1);

namespace Pagent\Events;

interface EventListener
{
    /**
     * Handle the event.
     */
    public function handle(Event $event): void;

    /**
     * Events this listener should respond to.
     *
     * @return array<string> Event names (e.g., ['before_prompt', 'after_prompt'])
     */
    public function listensTo(): array;
}
```

### 3. EventDispatcher

**File:** `src/Events/EventDispatcher.php`

```php
<?php

declare(strict_types=1);

namespace Pagent\Events;

use Closure;

final class EventDispatcher
{
    /**
     * @var array<string, array<int, array{listener: EventListener, priority: int, id: string}>>
     */
    private array $listeners = [];

    /**
     * @var array<string, bool> Sorted flags
     */
    private array $sorted = [];

    /**
     * Register event listener (closure or class-based).
     */
    public function on(string $eventName, Closure|EventListener $listener, int $priority = 0): string
    {
        if ($listener instanceof Closure) {
            $listener = $this->wrapClosure($eventName, $listener);
        }

        $id = spl_object_hash($listener);

        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }

        $this->listeners[$eventName][] = [
            'listener' => $listener,
            'priority' => $priority,
            'id' => $id,
        ];

        $this->sorted[$eventName] = false;

        return $id;
    }

    /**
     * Register one-time listener.
     */
    public function once(string $eventName, Closure|EventListener $listener, int $priority = 0): string
    {
        $wrappedListener = null;

        $oneTimeListener = function (Event $event) use ($listener, $eventName, &$wrappedListener): void {
            if ($listener instanceof Closure) {
                $listener($event);
            } else {
                $listener->handle($event);
            }

            // Remove self after execution
            if ($wrappedListener !== null) {
                $this->off($eventName, spl_object_hash($wrappedListener));
            }
        };

        $wrappedListener = $this->wrapClosure($eventName, $oneTimeListener);

        return $this->on($eventName, $wrappedListener, $priority);
    }

    /**
     * Remove listener by ID.
     */
    public function off(string $eventName, string $listenerId): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        $this->listeners[$eventName] = array_filter(
            $this->listeners[$eventName],
            fn($item) => $item['id'] !== $listenerId
        );

        $this->sorted[$eventName] = false;
    }

    /**
     * Dispatch event to all listeners.
     */
    public function dispatch(Event $event): void
    {
        $eventName = $event->getEventName();

        if (!isset($this->listeners[$eventName])) {
            return;
        }

        // Sort by priority (descending)
        if (!($this->sorted[$eventName] ?? false)) {
            usort(
                $this->listeners[$eventName],
                fn($a, $b) => $b['priority'] <=> $a['priority']
            );
            $this->sorted[$eventName] = true;
        }

        foreach ($this->listeners[$eventName] as $item) {
            if ($event->isPropagationStopped()) {
                break;
            }

            $item['listener']->handle($event);
        }
    }

    /**
     * Register class-based listener for multiple events.
     */
    public function listen(EventListener $listener, int $priority = 0): void
    {
        foreach ($listener->listensTo() as $eventName) {
            $this->on($eventName, $listener, $priority);
        }
    }

    /**
     * Wrap closure in anonymous EventListener.
     */
    private function wrapClosure(string $eventName, Closure $closure): EventListener
    {
        return new class($eventName, $closure) implements EventListener
        {
            public function __construct(
                private readonly string $eventName,
                private readonly Closure $closure,
            ) {}

            public function handle(Event $event): void
            {
                ($this->closure)($event);
            }

            public function listensTo(): array
            {
                return [$this->eventName];
            }
        };
    }
}
```

### 4. EventManager (Singleton)

**File:** `src/Events/EventManager.php`

```php
<?php

declare(strict_types=1);

namespace Pagent\Events;

final class EventManager
{
    private static ?self $instance = null;

    private EventDispatcher $dispatcher;

    private function __construct()
    {
        $this->dispatcher = new EventDispatcher();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function on(string $eventName, \Closure|EventListener $listener, int $priority = 0): string
    {
        return $this->dispatcher->on($eventName, $listener, $priority);
    }

    public function once(string $eventName, \Closure|EventListener $listener, int $priority = 0): string
    {
        return $this->dispatcher->once($eventName, $listener, $priority);
    }

    public function off(string $eventName, string $listenerId): void
    {
        $this->dispatcher->off($eventName, $listenerId);
    }

    public function dispatch(Event $event): void
    {
        $this->dispatcher->dispatch($event);
    }

    public function listen(EventListener $listener, int $priority = 0): void
    {
        $this->dispatcher->listen($listener, $priority);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
```

---

## Event Catalog

### Agent Lifecycle Events

#### BeforePromptEvent

**File:** `src/Events/Events/Agent/BeforePromptEvent.php`

```php
<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Agent;

use Pagent\Agent;
use Pagent\Events\Event;

final class BeforePromptEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $message,
        public readonly array $options,
    ) {
        parent::__construct();
    }
}
```

#### AfterPromptEvent

```php
final class AfterPromptEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $message,
        public readonly object $response,
        public readonly float $duration,
    ) {
        parent::__construct();
    }
}
```

#### ContextPrunedEvent

```php
final class ContextPrunedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $strategy,
        public readonly int $messagesRemoved,
        public readonly int $tokensRemoved,
    ) {
        parent::__construct();
    }
}
```

### LLM Events

#### BeforeLLMRequestEvent

**File:** `src/Events/Events/LLM/BeforeLLMRequestEvent.php`

```php
final class BeforeLLMRequestEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $options,
    ) {
        parent::__construct();
    }
}
```

#### AfterLLMResponseEvent

```php
final class AfterLLMResponseEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $provider,
        public readonly string $model,
        public readonly object $response,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $duration,
    ) {
        parent::__construct();
    }
}
```

### Tool Events

#### ToolExecutingEvent

**File:** `src/Events/Events/Tool/ToolExecutingEvent.php`

```php
final class ToolExecutingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $toolName,
        public readonly array $arguments,
    ) {
        parent::__construct();
    }
}
```

#### ToolExecutedEvent

```php
final class ToolExecutedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $toolName,
        public readonly array $arguments,
        public readonly mixed $result,
        public readonly float $duration,
    ) {
        parent::__construct();
    }
}
```

#### ToolErrorEvent

```php
final class ToolErrorEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $toolName,
        public readonly array $arguments,
        public readonly \Throwable $error,
    ) {
        parent::__construct();
    }
}
```

### Guard Events

#### GuardCheckingEvent

**File:** `src/Events/Events/Guard/GuardCheckingEvent.php`

```php
final class GuardCheckingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly string $input,
        public readonly string $output,
    ) {
        parent::__construct();
    }
}
```

#### GuardPassedEvent

```php
final class GuardPassedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly string $input,
        public readonly string $output,
    ) {
        parent::__construct();
    }
}
```

#### GuardViolatedEvent

```php
final class GuardViolatedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly string $input,
        public readonly string $output,
        public readonly string $reason,
    ) {
        parent::__construct();
    }
}
```

#### GuardFallbackEvent

```php
final class GuardFallbackEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly string $fallbackResponse,
    ) {
        parent::__construct();
    }
}
```

### Memory Events

#### MemoryLoadingEvent

**File:** `src/Events/Events/Memory/MemoryLoadingEvent.php`

```php
final class MemoryLoadingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $sessionId,
    ) {
        parent::__construct();
    }
}
```

#### MemoryLoadedEvent

```php
final class MemoryLoadedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $sessionId,
        public readonly int $messageCount,
        public readonly float $duration,
    ) {
        parent::__construct();
    }
}
```

#### MemorySavingEvent

```php
final class MemorySavingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $sessionId,
        public readonly int $messageCount,
    ) {
        parent::__construct();
    }
}
```

#### MemorySavedEvent

```php
final class MemorySavedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $sessionId,
        public readonly int $messageCount,
        public readonly float $duration,
    ) {
        parent::__construct();
    }
}
```

### Stream Events

#### StreamStartedEvent

**File:** `src/Events/Events/Stream/StreamStartedEvent.php`

```php
final class StreamStartedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $message,
    ) {
        parent::__construct();
    }
}
```

#### StreamChunkEvent

```php
final class StreamChunkEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $content,
        public readonly string $type,
    ) {
        parent::__construct();
    }
}
```

#### StreamCompletedEvent

```php
final class StreamCompletedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $fullContent,
        public readonly int $totalTokens,
        public readonly float $duration,
    ) {
        parent::__construct();
    }
}
```

---

## Agent Integration

### New Methods on Agent Class

**File:** `src/Agent.php` (modifications)

```php
final class Agent
{
    private EventDispatcher $eventDispatcher;

    public function __construct(string $name)
    {
        // ... existing code ...
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * Register event listener (closure-based).
     */
    public function on(string $eventName, Closure $listener, int $priority = 0): self
    {
        $this->eventDispatcher->on($eventName, $listener, $priority);
        return $this;
    }

    /**
     * Register one-time event listener.
     */
    public function once(string $eventName, Closure $listener, int $priority = 0): self
    {
        $this->eventDispatcher->once($eventName, $listener, $priority);
        return $this;
    }

    /**
     * Remove event listener by ID.
     */
    public function off(string $eventName, string $listenerId): self
    {
        $this->eventDispatcher->off($eventName, $listenerId);
        return $this;
    }

    /**
     * Register class-based event listener.
     */
    public function listen(EventListener $listener, int $priority = 0): self
    {
        $this->eventDispatcher->listen($listener, $priority);
        return $this;
    }

    /**
     * Dispatch event to both instance and global listeners.
     */
    private function fireEvent(Event $event): void
    {
        // Fire on instance-level listeners
        $this->eventDispatcher->dispatch($event);

        // Fire on global listeners
        EventManager::instance()->dispatch($event);
    }
}
```

### Modify Agent::prompt() to Fire Events

**In `src/Agent.php` around line 150:**

```php
public function prompt(string $message, array $options = []): object
{
    $startTime = microtime(true);

    // Fire before_prompt event
    $this->fireEvent(new BeforePromptEvent($this, $message, $options));

    // ... existing load memory logic ...

    try {
        $response = $this->callProviderWithSpan($message, $mergedOptions);

        $duration = microtime(true) - $startTime;

        // Fire after_prompt event
        $this->fireEvent(new AfterPromptEvent($this, $message, $response, $duration));

        return $response;
    } catch (\Throwable $e) {
        // Fire error event (new event type)
        $this->fireEvent(new AgentErrorEvent($this, $message, $e));
        throw $e;
    }
}
```

Similar modifications for:

- `callProviderWithSpan()` → Fire `BeforeLLMRequestEvent`, `AfterLLMResponseEvent`
- `executeToolWithSpan()` → Fire `ToolExecutingEvent`, `ToolExecutedEvent`, `ToolErrorEvent`
- Guard validation → Fire `GuardCheckingEvent`, `GuardPassedEvent`, `GuardViolatedEvent`
- Memory operations → Fire `MemoryLoadingEvent`, `MemoryLoadedEvent`, etc.

---

## TelemetryEventBridge

**File:** `src/Events/Bridges/TelemetryEventBridge.php`

This listener automatically creates OpenTelemetry spans from events, replacing manual span creation.

```php
<?php

declare(strict_types=1);

namespace Pagent\Events\Bridges;

use Pagent\Events\Event;
use Pagent\Events\EventListener;
use Pagent\Events\Events\Agent\BeforePromptEvent;
use Pagent\Events\Events\Agent\AfterPromptEvent;
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Events\Events\Tool\ToolExecutingEvent;
use Pagent\Events\Events\Tool\ToolExecutedEvent;
use Pagent\Events\Events\Guard\GuardCheckingEvent;
use Pagent\Events\Events\Guard\GuardPassedEvent;
use Pagent\Events\Events\Memory\MemoryLoadingEvent;
use Pagent\Events\Events\Memory\MemoryLoadedEvent;
use Pagent\Observability\TelemetryManager;
use Pagent\Observability\Span;

final class TelemetryEventBridge implements EventListener
{
    /**
     * @var array<string, Span> Active spans keyed by operation ID
     */
    private array $activeSpans = [];

    public function listensTo(): array
    {
        return [
            'before_prompt',
            'after_prompt',
            'before_llm_request',
            'after_llm_response',
            'tool_executing',
            'tool_executed',
            'guard_checking',
            'guard_passed',
            'memory_loading',
            'memory_loaded',
        ];
    }

    public function handle(Event $event): void
    {
        match (true) {
            $event instanceof BeforePromptEvent => $this->handleBeforePrompt($event),
            $event instanceof AfterPromptEvent => $this->handleAfterPrompt($event),
            $event instanceof BeforeLLMRequestEvent => $this->handleBeforeLLMRequest($event),
            $event instanceof AfterLLMResponseEvent => $this->handleAfterLLMResponse($event),
            $event instanceof ToolExecutingEvent => $this->handleToolExecuting($event),
            $event instanceof ToolExecutedEvent => $this->handleToolExecuted($event),
            $event instanceof GuardCheckingEvent => $this->handleGuardChecking($event),
            $event instanceof GuardPassedEvent => $this->handleGuardPassed($event),
            $event instanceof MemoryLoadingEvent => $this->handleMemoryLoading($event),
            $event instanceof MemoryLoadedEvent => $this->handleMemoryLoaded($event),
            default => null,
        };
    }

    private function handleBeforePrompt(BeforePromptEvent $event): void
    {
        $span = TelemetryManager::instance()->startAgentSpan(
            'prompt',
            $event->agent->getName(),
            []
        );

        $this->activeSpans['agent.prompt.' . spl_object_id($event->agent)] = $span;
    }

    private function handleAfterPrompt(AfterPromptEvent $event): void
    {
        $spanKey = 'agent.prompt.' . spl_object_id($event->agent);

        if (isset($this->activeSpans[$spanKey])) {
            $this->activeSpans[$spanKey]->end();
            unset($this->activeSpans[$spanKey]);
        }
    }

    private function handleBeforeLLMRequest(BeforeLLMRequestEvent $event): void
    {
        $span = TelemetryManager::instance()->startLLMSpan(
            $event->provider,
            $event->model,
            [
                'gen_ai.request.temperature' => $event->options['temperature'] ?? null,
                'gen_ai.request.max_tokens' => $event->options['max_tokens'] ?? null,
            ]
        );

        $this->activeSpans['llm.request.' . spl_object_id($event->agent)] = $span;
    }

    private function handleAfterLLMResponse(AfterLLMResponseEvent $event): void
    {
        $spanKey = 'llm.request.' . spl_object_id($event->agent);

        if (isset($this->activeSpans[$spanKey])) {
            $this->activeSpans[$spanKey]->setAttribute('gen_ai.usage.completion_tokens', $event->outputTokens);
            $this->activeSpans[$spanKey]->setAttribute('gen_ai.usage.prompt_tokens', $event->inputTokens);
            $this->activeSpans[$spanKey]->end();
            unset($this->activeSpans[$spanKey]);
        }
    }

    private function handleToolExecuting(ToolExecutingEvent $event): void
    {
        $span = TelemetryManager::instance()->startToolSpan(
            $event->toolName,
            $event->arguments
        );

        $this->activeSpans['tool.execute.' . $event->toolName . '.' . spl_object_id($event->agent)] = $span;
    }

    private function handleToolExecuted(ToolExecutedEvent $event): void
    {
        $spanKey = 'tool.execute.' . $event->toolName . '.' . spl_object_id($event->agent);

        if (isset($this->activeSpans[$spanKey])) {
            $this->activeSpans[$spanKey]->end();
            unset($this->activeSpans[$spanKey]);
        }
    }

    private function handleGuardChecking(GuardCheckingEvent $event): void
    {
        $span = TelemetryManager::instance()->startSpan('guard.check', [
            'guard.name' => $event->guardName,
        ]);

        $this->activeSpans['guard.check.' . $event->guardName . '.' . spl_object_id($event->agent)] = $span;
    }

    private function handleGuardPassed(GuardPassedEvent $event): void
    {
        $spanKey = 'guard.check.' . $event->guardName . '.' . spl_object_id($event->agent);

        if (isset($this->activeSpans[$spanKey])) {
            $this->activeSpans[$spanKey]->setAttribute('guard.passed', true);
            $this->activeSpans[$spanKey]->end();
            unset($this->activeSpans[$spanKey]);
        }
    }

    private function handleMemoryLoading(MemoryLoadingEvent $event): void
    {
        $span = TelemetryManager::instance()->startSpan('memory.load', [
            'session.id' => $event->sessionId,
        ]);

        $this->activeSpans['memory.load.' . $event->sessionId] = $span;
    }

    private function handleMemoryLoaded(MemoryLoadedEvent $event): void
    {
        $spanKey = 'memory.load.' . $event->sessionId;

        if (isset($this->activeSpans[$spanKey])) {
            $this->activeSpans[$spanKey]->setAttribute('message.count', $event->messageCount);
            $this->activeSpans[$spanKey]->end();
            unset($this->activeSpans[$spanKey]);
        }
    }
}
```

---

## Migration Strategy

### Phase 1: Parallel Operation (v0.8.0 alpha)

Both systems run simultaneously:

- Manual `TelemetryManager` calls remain in Agent
- Event system is added
- `TelemetryEventBridge` is opt-in via config

```php
// Config flag
$agent->config(['events.telemetry_bridge' => true]);

// Or manual registration
EventManager::instance()->listen(new TelemetryEventBridge());
```

### Phase 2: Deprecation (v0.8.0 beta)

- Manual span creation is marked deprecated
- Warning logs added when using manual approach
- Documentation updated to recommend events

### Phase 3: Removal (v0.9.0 or v1.0.0)

- Manual `TelemetryManager` calls removed from Agent
- Only event-driven telemetry remains
- Migration guide provided

### Migration Guide

**Old Approach (v0.7.0):**

```php
// Manual span creation in Agent.php (line 897)
$span = TelemetryManager::instance()->startAgentSpan('prompt', $this->name);
// ... operation ...
$span->end();
```

**New Approach (v0.8.0):**

```php
// Event-driven (automatic with TelemetryEventBridge)
$this->fireEvent(new BeforePromptEvent($this, $message, $options));
// ... operation ...
$this->fireEvent(new AfterPromptEvent($this, $message, $response, $duration));

// Bridge listens to events and creates spans automatically
```

**User Code Impact:**

Most users won't notice the change. Users who:

- **Only use `Agent::telemetry(true)`**: No changes needed
- **Manually call `TelemetryManager`**: Need to migrate to events or listeners
- **Custom exporters**: No changes needed
- **Custom middleware**: No changes needed

---

## Implementation Phases

### Phase 1: Core Event Infrastructure (2-3 hours)

**Tasks:**

1. Create `Event` base class with propagation control
2. Create `EventListener` interface
3. Create `EventDispatcher` with priority system
4. Create `EventManager` singleton
5. Write 10-12 unit tests for dispatcher

**Files Created:**

- `src/Events/Event.php`
- `src/Events/EventListener.php`
- `src/Events/EventDispatcher.php`
- `src/Events/EventManager.php`
- `tests/Unit/Events/EventDispatcherTest.php`

**Tests:**

- ✅ `it('dispatches events to listeners')`
- ✅ `it('respects priority order')`
- ✅ `it('stops propagation when requested')`
- ✅ `it('supports closure-based listeners')`
- ✅ `it('supports class-based listeners')`
- ✅ `it('allows removing listeners')`
- ✅ `it('handles one-time listeners')`
- ✅ `it('returns listener ID for later removal')`
- ✅ `it('wraps closures in EventListener')`
- ✅ `it('sorts listeners by priority only once')`
- ✅ `it('handles empty listener lists')`
- ✅ `it('supports multiple listeners for same event')`

### Phase 2: Event Classes (2-3 hours)

**Tasks:**

1. Create all 18 event classes with proper types
2. Group by namespace (Agent, LLM, Tool, Guard, Memory, Stream)
3. Add `getEventName()` implementations
4. Write 8-10 unit tests for event objects

**Files Created:**

- `src/Events/Events/Agent/*.php` (3 files)
- `src/Events/Events/LLM/*.php` (2 files)
- `src/Events/Events/Tool/*.php` (3 files)
- `src/Events/Events/Guard/*.php` (4 files)
- `src/Events/Events/Memory/*.php` (4 files)
- `src/Events/Events/Stream/*.php` (3 files)
- `tests/Unit/Events/EventsTest.php`

**Tests:**

- ✅ `it('creates BeforePromptEvent with correct data')`
- ✅ `it('creates AfterPromptEvent with duration')`
- ✅ `it('generates correct event names')`
- ✅ `it('includes timestamp on all events')`
- ✅ `it('propagation control works on events')`
- ✅ `it('event properties are readonly')`
- ✅ `it('LLM events include token counts')`
- ✅ `it('Tool events include arguments and results')`
- ✅ `it('Guard events include violation reasons')`
- ✅ `it('Memory events include session IDs')`

### Phase 3: Agent Integration (1-2 hours)

**Tasks:**

1. Add `EventDispatcher` property to Agent
2. Add `on()`, `once()`, `off()`, `listen()` methods
3. Add `fireEvent()` private method
4. Fire events in `prompt()`, tool execution, guard checks, memory ops
5. Write 8-10 integration tests

**Files Modified:**

- `src/Agent.php`

**Files Created:**

- `tests/Integration/Events/AgentEventsTest.php`

**Tests:**

- ✅ `it('fires before_prompt event')`
- ✅ `it('fires after_prompt event with response')`
- ✅ `it('fires LLM events during provider call')`
- ✅ `it('fires tool events during execution')`
- ✅ `it('fires guard events during validation')`
- ✅ `it('allows registering closure listeners')`
- ✅ `it('allows registering class listeners')`
- ✅ `it('respects listener priority')`
- ✅ `it('supports removing listeners')`
- ✅ `it('supports one-time listeners')`

### Phase 4: TelemetryEventBridge (1 hour)

**Tasks:**

1. Create `TelemetryEventBridge` class
2. Map events to span creation
3. Track active spans
4. Add configuration flag
5. Write 6-8 integration tests

**Files Created:**

- `src/Events/Bridges/TelemetryEventBridge.php`
- `tests/Integration/Events/TelemetryEventBridgeTest.php`

**Tests:**

- ✅ `it('creates agent span from before_prompt event')`
- ✅ `it('ends span on after_prompt event')`
- ✅ `it('creates LLM spans with semantic attributes')`
- ✅ `it('creates tool spans with arguments')`
- ✅ `it('creates guard spans with result')`
- ✅ `it('creates memory spans with session info')`
- ✅ `it('handles concurrent operations')`
- ✅ `it('cleans up spans on completion')`

### Phase 5: Testing & Documentation (1-2 hours)

**Tasks:**

1. Write comprehensive documentation
2. Create migration guide
3. Add code examples
4. Update CHANGELOG.md
5. Run full test suite
6. Update telemetry examples

**Files Created:**

- `docs/events-hooks.md`
- `docs/migration-v0.8.0.md`
- `examples/20-events-custom-listener.php`
- `examples/21-events-telemetry-bridge.php`

**Files Modified:**

- `CHANGELOG.md`
- `README.md` (add events section)

---

## Test Specifications

### Unit Tests (20-25 tests)

**EventDispatcher Tests** (`tests/Unit/Events/EventDispatcherTest.php`):

1. `it('dispatches events to registered listeners')`
2. `it('executes listeners in priority order (high to low)')`
3. `it('stops propagation when event.stopPropagation() called')`
4. `it('supports closure-based listeners')`
5. `it('supports class-based EventListener')`
6. `it('wraps closures in anonymous EventListener')`
7. `it('returns unique listener ID for later removal')`
8. `it('removes listeners by ID via off()')`
9. `it('supports once() for one-time listeners')`
10. `it('removes one-time listener after execution')`
11. `it('handles multiple listeners for same event')`
12. `it('sorts listeners only once until new listener added')`
13. `it('does nothing when dispatching event with no listeners')`
14. `it('listen() registers class listener for all its events')`

**Event Classes Tests** (`tests/Unit/Events/EventsTest.php`): 15. `it('BeforePromptEvent has correct properties')` 16. `it('AfterPromptEvent includes duration')` 17. `it('ToolExecutedEvent includes result and duration')` 18. `it('GuardViolatedEvent includes reason')` 19. `it('all events include timestamp')` 20. `it('getEventName() returns snake_case name')` 21. `it('event properties are readonly')` 22. `it('isPropagationStopped() returns false by default')` 23. `it('stopPropagation() sets flag to true')`

**EventManager Tests** (`tests/Unit/Events/EventManagerTest.php`): 24. `it('returns singleton instance')` 25. `it('delegates to EventDispatcher')` 26. `it('reset() clears singleton')`

### Integration Tests (10-15 tests)

**Agent Events Tests** (`tests/Integration/Events/AgentEventsTest.php`):

1. `it('fires before_prompt and after_prompt events')`
2. `it('includes agent reference in events')`
3. `it('before_prompt fires before LLM call')`
4. `it('after_prompt fires after response received')`
5. `it('fires LLM events during provider call')`
6. `it('fires tool events when tools executed')`
7. `it('fires guard events during validation')`
8. `it('fires memory events on load/save')`
9. `it('allows registering per-agent listeners via on()')`
10. `it('allows registering global listeners via EventManager')`

**TelemetryEventBridge Tests** (`tests/Integration/Events/TelemetryEventBridgeTest.php`): 11. `it('creates spans from events automatically')` 12. `it('agent.prompt span created from BeforePromptEvent')` 13. `it('llm.request span created from BeforeLLMRequestEvent')` 14. `it('tool.execute span created from ToolExecutingEvent')` 15. `it('spans include correct attributes from events')` 16. `it('handles concurrent operations with multiple spans')` 17. `it('cleans up spans after operations complete')` 18. `it('works alongside existing manual TelemetryManager usage')`

---

## Code Examples

### Example 1: Simple Event Listener

```php
use Pagent\Events\Events\Agent\AfterPromptEvent;
use Pagent\Events\EventManager;

// Global event listener (all agents)
EventManager::instance()->on('after_prompt', function (AfterPromptEvent $event) {
    Log::info('Agent prompt completed', [
        'agent' => $event->agent->getName(),
        'duration' => $event->duration,
        'response_length' => strlen($event->response->content),
    ]);
});

$agent = agent('assistant')->prompt('Hello');
// Logs: "Agent prompt completed" with metrics
```

### Example 2: Per-Agent Listener

```php
$agent = agent('assistant')
    ->on('after_prompt', fn(AfterPromptEvent $e) =>
        echo "Response: {$e->response->content}\n"
    )
    ->prompt('Tell me a joke');
// Output: "Response: Why did the chicken cross the road? ..."
```

### Example 3: Priority Listeners

```php
$agent = agent('bot')
    ->on('before_prompt', fn($e) => echo "3rd (default priority 0)\n")
    ->on('before_prompt', fn($e) => echo "1st (priority 100)\n", priority: 100)
    ->on('before_prompt', fn($e) => echo "2nd (priority 50)\n", priority: 50)
    ->prompt('Test');
// Output:
// 1st (priority 100)
// 2nd (priority 50)
// 3rd (default priority 0)
```

### Example 4: Class-Based Listener

```php
use Pagent\Events\Event;
use Pagent\Events\EventListener;
use Pagent\Events\Events\Tool\ToolExecutedEvent;

class ToolMetricsCollector implements EventListener
{
    public function listensTo(): array
    {
        return ['tool_executed'];
    }

    public function handle(Event $event): void
    {
        if ($event instanceof ToolExecutedEvent) {
            $this->recordMetric($event->toolName, $event->duration);
        }
    }

    private function recordMetric(string $tool, float $duration): void
    {
        echo "Tool {$tool} took {$duration}s\n";
    }
}

$agent = agent('bot')
    ->listen(new ToolMetricsCollector())
    ->tool('calculate', 'Add numbers', fn(int $a, int $b) => $a + $b)
    ->prompt('What is 5 + 3?');
// Output: "Tool calculate took 0.001s"
```

### Example 5: Propagation Control

```php
$agent = agent('bot')
    ->on('before_prompt', function ($e) {
        if (str_contains($e->message, 'secret')) {
            echo "Blocked secret prompt\n";
            $e->stopPropagation();
        }
    }, priority: 100)
    ->on('before_prompt', fn($e) => echo "This won't execute\n")
    ->prompt('Tell me the secret');
// Output: "Blocked secret prompt"
// Second listener never executes
```

### Example 6: One-Time Listener

```php
$agent = agent('bot')
    ->once('after_prompt', fn($e) => echo "First call\n")
    ->prompt('Hello');
// Output: "First call"

$agent->prompt('Hello again');
// No output (listener removed after first call)
```

### Example 7: Removing Listeners

```php
$listenerId = $agent->on('after_prompt', fn($e) => echo "Listening\n");

$agent->prompt('Test 1');
// Output: "Listening"

$agent->off('after_prompt', $listenerId);

$agent->prompt('Test 2');
// No output (listener removed)
```

### Example 8: TelemetryEventBridge Integration

```php
use Pagent\Events\EventManager;
use Pagent\Events\Bridges\TelemetryEventBridge;
use Pagent\Observability\TelemetryManager;

// Initialize telemetry
TelemetryManager::instance()->initialize([
    'enabled' => true,
    'exporter' => 'jaeger',
    'jaeger' => ['endpoint' => 'http://localhost:4318/v1/traces'],
]);

// Register bridge to auto-create spans from events
EventManager::instance()->listen(new TelemetryEventBridge());

// Now all agent operations automatically create spans via events
$agent = agent('assistant')
    ->prompt('Hello');
// Spans created: agent.prompt, llm.request
// All via events - no manual TelemetryManager calls
```

### Example 9: Custom Observability Listener

```php
use Pagent\Events\Event;
use Pagent\Events\EventListener;
use Pagent\Events\Events\Guard\GuardViolatedEvent;

class SecurityAlertListener implements EventListener
{
    public function listensTo(): array
    {
        return ['guard_violated'];
    }

    public function handle(Event $event): void
    {
        if ($event instanceof GuardViolatedEvent) {
            $this->sendSecurityAlert([
                'agent' => $event->agent->getName(),
                'guard' => $event->guardName,
                'reason' => $event->reason,
                'timestamp' => $event->timestamp,
            ]);
        }
    }

    private function sendSecurityAlert(array $data): void
    {
        // Send to security monitoring system
        file_put_contents('security.log', json_encode($data) . PHP_EOL, FILE_APPEND);
    }
}

EventManager::instance()->listen(new SecurityAlertListener());
```

### Example 10: Multi-Event Listener

```php
use Pagent\Events\Event;
use Pagent\Events\EventListener;

class ComprehensiveMonitor implements EventListener
{
    public function listensTo(): array
    {
        return [
            'before_prompt',
            'after_prompt',
            'tool_executed',
            'guard_violated',
        ];
    }

    public function handle(Event $event): void
    {
        $eventName = $event->getEventName();
        $timestamp = date('Y-m-d H:i:s', (int) $event->timestamp);

        echo "[{$timestamp}] Event: {$eventName}\n";
    }
}

$agent = agent('bot')
    ->listen(new ComprehensiveMonitor())
    ->prompt('Test');
// Output:
// [2025-10-29 10:30:00] Event: before_prompt
// [2025-10-29 10:30:01] Event: after_prompt
```

---

## Backward Compatibility

### Transition Period (v0.8.0)

**Default Behavior:**

- Events system is ENABLED by default
- Manual `TelemetryManager` calls remain in Agent (both approaches work)
- `TelemetryEventBridge` is opt-in via config

**Config Options:**

```php
// agent.php or config file
return [
    'events' => [
        'enabled' => true,  // Enable event system (default: true)
        'telemetry_bridge' => false,  // Auto-register TelemetryEventBridge (default: false)
    ],
];
```

**Deprecation Warnings:**

Manual `TelemetryManager` calls in custom code will trigger E_USER_DEPRECATED:

```php
// Will show deprecation warning in v0.8.0+
$span = TelemetryManager::instance()->startAgentSpan('custom', 'my-agent');
// Warning: Manual span creation is deprecated. Use events instead.
```

### Migration Path

**For Library Users:**

Most users won't need changes:

- ✅ `Agent::telemetry(true)` continues to work
- ✅ Existing observability exporters work
- ✅ All agent methods continue to work

**For Advanced Users (Custom Telemetry):**

```php
// Old way (v0.7.0) - DEPRECATED
$agent->prompt('Hello');
$span = TelemetryManager::instance()->startSpan('custom.operation');
// ... custom logic ...
$span->end();

// New way (v0.8.0+)
$agent
    ->on('after_prompt', function($e) {
        // Custom observability logic
    })
    ->prompt('Hello');
```

### Breaking Changes in v0.9.0+

1. Manual `TelemetryManager` calls removed from `src/Agent.php`
2. Only event-driven telemetry remains
3. `TelemetryEventBridge` registered by default
4. Config flag `events.telemetry_bridge` removed (always enabled)

**Migration Checklist:**

- [ ] Replace manual `startSpan()` calls with event listeners
- [ ] Register `TelemetryEventBridge` if using custom telemetry
- [ ] Update custom middleware/guards to use events if needed
- [ ] Test observability still works with new architecture
- [ ] Remove deprecated config flags

---

## File Checklist

### New Files to Create (26 files)

**Core Event System (4 files):**

- [ ] `src/Events/Event.php`
- [ ] `src/Events/EventListener.php`
- [ ] `src/Events/EventDispatcher.php`
- [ ] `src/Events/EventManager.php`

**Event Classes (18 files):**

- [ ] `src/Events/Events/Agent/BeforePromptEvent.php`
- [ ] `src/Events/Events/Agent/AfterPromptEvent.php`
- [ ] `src/Events/Events/Agent/ContextPrunedEvent.php`
- [ ] `src/Events/Events/LLM/BeforeLLMRequestEvent.php`
- [ ] `src/Events/Events/LLM/AfterLLMResponseEvent.php`
- [ ] `src/Events/Events/Tool/ToolExecutingEvent.php`
- [ ] `src/Events/Events/Tool/ToolExecutedEvent.php`
- [ ] `src/Events/Events/Tool/ToolErrorEvent.php`
- [ ] `src/Events/Events/Guard/GuardCheckingEvent.php`
- [ ] `src/Events/Events/Guard/GuardPassedEvent.php`
- [ ] `src/Events/Events/Guard/GuardViolatedEvent.php`
- [ ] `src/Events/Events/Guard/GuardFallbackEvent.php`
- [ ] `src/Events/Events/Memory/MemoryLoadingEvent.php`
- [ ] `src/Events/Events/Memory/MemoryLoadedEvent.php`
- [ ] `src/Events/Events/Memory/MemorySavingEvent.php`
- [ ] `src/Events/Events/Memory/MemorySavedEvent.php`
- [ ] `src/Events/Events/Stream/StreamStartedEvent.php`
- [ ] `src/Events/Events/Stream/StreamChunkEvent.php`
- [ ] `src/Events/Events/Stream/StreamCompletedEvent.php`

**Bridges (1 file):**

- [ ] `src/Events/Bridges/TelemetryEventBridge.php`

**Tests (3 files):**

- [ ] `tests/Unit/Events/EventDispatcherTest.php`
- [ ] `tests/Unit/Events/EventsTest.php`
- [ ] `tests/Integration/Events/AgentEventsTest.php`
- [ ] `tests/Integration/Events/TelemetryEventBridgeTest.php`

### Files to Modify (2 files)

- [ ] `src/Agent.php` - Add event methods and fireEvent() calls
- [ ] `CHANGELOG.md` - Document v0.8.0 changes

### Documentation (3 files)

- [ ] `docs/events-hooks.md` - Comprehensive guide
- [ ] `docs/migration-v0.8.0.md` - Migration guide
- [ ] `examples/20-events-custom-listener.php`

---

## Success Criteria

### Functional Requirements

- [ ] All 18 event classes created with correct types
- [ ] EventDispatcher supports priority, propagation control
- [ ] Agent methods: `on()`, `once()`, `off()`, `listen()` working
- [ ] Global EventManager singleton working
- [ ] Events fire at correct lifecycle points
- [ ] TelemetryEventBridge creates spans from events
- [ ] 30-40 tests passing (unit + integration)

### Non-Functional Requirements

- [ ] PHPStan level 9 compliance maintained
- [ ] All event properties are readonly
- [ ] Performance overhead < 5ms per event dispatch
- [ ] Backward compatible with v0.7.0 telemetry
- [ ] Documentation complete and clear
- [ ] Migration guide provided

### Quality Metrics

- [ ] 100% test coverage on EventDispatcher
- [ ] 95%+ coverage on event classes
- [ ] All integration tests green
- [ ] No PHPStan errors
- [ ] Code passes Laravel Pint formatting

---

## Timeline & Dependencies

### Dependencies

**Internal:**

- ✅ v0.7.0 TelemetryManager (already implemented)
- ✅ Agent class (existing)
- ✅ Observability infrastructure (existing)

**External:**

- None (pure PHP 8.3+ implementation)

### Estimated Timeline

**Full-Time (dedicated):**

- Phase 1: 2-3 hours
- Phase 2: 2-3 hours
- Phase 3: 1-2 hours
- Phase 4: 1 hour
- Phase 5: 1-2 hours
- **Total: 6-8 hours (1-2 days)**

**Part-Time (alongside other work):**

- **Total: 2-3 weeks**

### Critical Path

1. ✅ v0.7.0 observability must be released first
2. Event infrastructure must be complete before Agent integration
3. Agent integration must be done before TelemetryEventBridge
4. Tests can be written in parallel with implementation

---

## Related Documents

- **ROADMAP.md** - v0.8.0 Events/Hooks System feature
- **FEATURES.md** - Section 12: Observability & Monitoring
- **opentelemetry-observability-plan.md** - v0.7.0 telemetry implementation
- **workflow-orchestration-plan.md** - v0.8.0 workflow features

---

**Last Updated:** 2025-10-29
**Author:** Pagent Core Team
**Status:** Approved, ready for implementation
