<?php

declare(strict_types=1);

namespace Pagent;

use Closure;
use Pagent\Contracts\Guard;
use Pagent\Contracts\Memory;
use Pagent\Contracts\Middleware;
use Pagent\Contracts\Provider;
use Pagent\Contracts\ToolInterface;
use Pagent\Events\Event;
use Pagent\Events\EventDispatcher;
use Pagent\Events\EventListener;
use Pagent\Events\Events\Agent\AfterPromptEvent;
use Pagent\Events\Events\Agent\BeforePromptEvent;
use Pagent\Events\Events\Agent\ContextPrunedEvent;
use Pagent\Events\Events\Guard\GuardCheckingEvent;
use Pagent\Events\Events\Guard\GuardFallbackEvent;
use Pagent\Events\Events\Guard\GuardPassedEvent;
use Pagent\Events\Events\Guard\GuardViolatedEvent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;
use Pagent\Events\Events\Memory\MemoryLoadedEvent;
use Pagent\Events\Events\Memory\MemoryLoadingEvent;
use Pagent\Events\Events\Memory\MemorySavedEvent;
use Pagent\Events\Events\Memory\MemorySavingEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Events\Events\Stream\StreamStartedEvent;
use Pagent\Events\Events\Tool\ToolErrorEvent;
use Pagent\Events\Events\Tool\ToolExecutedEvent;
use Pagent\Events\Events\Tool\ToolExecutingEvent;
use Pagent\Exceptions\GuardException;
use Pagent\Memory\ContextManager;
use Pagent\Observability\NullSpan;
use Pagent\Observability\Span;
use Pagent\Observability\TelemetryManager;
use Pagent\Streaming\StreamResponse;
use Pagent\Tool\Tool;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function asort;
use function class_exists;
use function count;
use function date;
use function get_class;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function levenshtein;
use function sprintf;
use function str_contains;
use function strlen;
use function ucfirst;

/**
 * Agent represents a conversational AI agent in Pagent.
 *
 * Responsibilities:
 * - Holds conversation state (messages, optional Memory, session id).
 * - Configures provider, model and generation parameters.
 * - Registers and executes tools, guards and middleware.
 * - Orchestrates prompt/stream lifecycles, automatic tool-calling and retries.
 * - Integrates context windowing and optional telemetry for observability.
 *
 * Lightweight, framework-agnostic API for building LLM-driven assistants.
 */
final class Agent
{
    private const MAX_TOOL_CALL_DEPTH = 10;

    public array $messages = [];

    private array $config = [];

    private ?Provider $provider = null;

    /** @var ToolInterface[] */
    private array $tools = [];

    /** @var Guard[] */
    private array $guards = [];

    /** @var Middleware[] */
    private array $middleware = [];

    private ?Closure $fallback = null;

    private ?Memory $memory = null;

    private ?string $sessionId = null;

    private ?ContextManager $contextManager = null;

    public bool $telemetryEnabled = false;

    private ?array $cachedToolSchemas = null;

    private EventDispatcher $eventDispatcher;

    private ?\Pagent\Usage\UsageTracker $usageTracker = null;

    public function __construct(
        private readonly string $name,
    ) {
        $this->eventDispatcher = new EventDispatcher;
    }

    public function provider(Provider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function config(array $config): self
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    public function system(string $prompt): self
    {
        $this->config['system'] = $prompt;

        return $this;
    }

    public function model(string $model): self
    {
        $this->config['model'] = $model;

        return $this;
    }

    public function temperature(float $temperature): self
    {
        if ($temperature < 0.0 || $temperature > 2.0) {
            throw new \InvalidArgumentException(
                sprintf('Temperature must be between 0.0 and 2.0, got %.2f', $temperature)
            );
        }

        $this->config['temperature'] = $temperature;

        return $this;
    }

    public function maxTokens(int $maxTokens): self
    {
        if ($maxTokens < 1) {
            throw new \InvalidArgumentException(
                sprintf('Max tokens must be at least 1, got %d', $maxTokens)
            );
        }

        $this->config['max_tokens'] = $maxTokens;

        return $this;
    }

    public function memory(string|Memory $adapter, array $config = []): self
    {
        if ($adapter instanceof Memory) {
            $this->memory = $adapter;

            return $this;
        }

        // Resolve string to adapter class
        $adapterClass = "Pagent\\Memory\\Adapters\\{$adapter}Adapter";

        if (! class_exists($adapterClass)) {
            throw new RuntimeException("Memory adapter class '{$adapterClass}' not found");
        }

        $this->memory = new $adapterClass($config);

        return $this;
    }

    public function sessionId(string $id): self
    {
        $this->sessionId = $id;

        return $this;
    }

    public function contextWindow(int $maxTokens, string $strategy = 'oldest'): self
    {
        $this->contextManager = new ContextManager($maxTokens, $strategy);

        return $this;
    }

    /**
     * Enable telemetry for this agent
     */
    public function telemetry(bool $enabled = true): self
    {
        $this->telemetryEnabled = $enabled;

        return $this;
    }

    /**
     * Enable usage tracking for this agent.
     *
     * Creates a dedicated UsageTracker for this agent that only tracks
     * operations from this specific agent.
     *
     * @param  array{enabled?: bool, track_llm?: bool, track_streaming?: bool, storage?: \Pagent\Usage\Storage\UsageStorage, pricing?: array<string, array<string, array{input: float, output: float, cached_input?: float}>>}  $config  Tracker configuration
     */
    public function trackUsage(array $config = []): self
    {
        $this->usageTracker = new \Pagent\Usage\UsageTracker($config);

        // Register with this agent's EventDispatcher AND global EventManager
        // Agent's dispatcher for events from this agent
        foreach ($this->usageTracker->listensTo() as $eventName) {
            $this->eventDispatcher->on($eventName, $this->usageTracker);
        }

        // Global EventManager for events from other agents (if using global tracker)
        \Pagent\Events\EventManager::instance()->listen($this->usageTracker);

        return $this;
    }

    /**
     * Get usage data for this agent.
     *
     * Returns usage records filtered by this agent's name from either:
     * - The agent's dedicated tracker (if trackUsage() was called)
     * - The global tracker (if usage_tracker() was called globally)
     *
     * @return array<int, \Pagent\Usage\UsageData>
     */
    public function getUsage(): array
    {
        // Check if this agent has its own tracker
        if ($this->usageTracker !== null) {
            return $this->usageTracker->byAgent($this->name);
        }

        // Fall back to global tracker if available
        try {
            $globalTracker = \Pagent\Usage\UsageTracker::global();

            return $globalTracker->byAgent($this->name);
        } catch (\Throwable) {
            // No tracker available
            return [];
        }
    }

    /**
     * Register an event listener.
     *
     * @param  string  $eventName  Event name (e.g., 'before_prompt')
     * @param  Closure|EventListener  $listener  The listener
     * @param  int  $priority  Priority (higher = executed first)
     * @return string Listener ID for removal
     */
    public function on(string $eventName, Closure|EventListener $listener, int $priority = 0): string
    {
        return $this->eventDispatcher->on($eventName, $listener, $priority);
    }

    /**
     * Register a one-time event listener.
     *
     * @param  string  $eventName  Event name
     * @param  Closure|EventListener  $listener  The listener
     * @param  int  $priority  Priority (higher = executed first)
     * @return string Listener ID
     */
    public function once(string $eventName, Closure|EventListener $listener, int $priority = 0): string
    {
        return $this->eventDispatcher->once($eventName, $listener, $priority);
    }

    /**
     * Remove a listener by ID.
     *
     * @param  string  $eventName  Event name
     * @param  string  $listenerId  Listener ID
     */
    public function off(string $eventName, string $listenerId): void
    {
        $this->eventDispatcher->off($eventName, $listenerId);
    }

    /**
     * Register a class-based listener for multiple events.
     *
     * @param  EventListener  $listener  The listener instance
     * @param  int  $priority  Priority for all events
     */
    public function listen(EventListener $listener, int $priority = 0): void
    {
        $this->eventDispatcher->listen($listener, $priority);
    }

    public function prompt(string $message, array $options = []): object
    {
        if (! $this->provider) {
            throw new RuntimeException("No provider set for agent '{$this->name}'");
        }

        // Start agent operation span
        $span = $this->startOperationSpan('prompt', [
            'agent.session_id' => $this->sessionId ?? 'none',
        ]);

        try {
            // Auto-load from memory if configured and messages are empty
            if ($this->memory && $this->sessionId && empty($this->messages)) {
                $memorySpan = $this->telemetryEnabled
                    ? TelemetryManager::instance()->startSpan('memory.load', [
                        'session_id' => $this->sessionId,
                    ])
                    : new NullSpan;

                try {
                    $this->fireEvent(new MemoryLoadingEvent($this, $this->sessionId));

                    $loaded = $this->memory->load($this->sessionId);
                    $this->messages = $loaded;

                    $this->fireEvent(new MemoryLoadedEvent($this, $this->sessionId, $loaded));

                    $memorySpan->setAttributes([
                        'message_count' => count($loaded),
                    ]);
                    $memorySpan->setStatus('ok');
                } catch (Throwable $e) {
                    $memorySpan->recordException($e);
                    $memorySpan->setStatus('error', $e->getMessage());

                    throw $e;
                } finally {
                    $memorySpan->end();
                }
            }

            // Add to message history
            $this->messages[] = ['role' => 'user', 'content' => $message];

            // Fire before prompt event
            $this->fireEvent(new BeforePromptEvent($this, $message, $options));

            // Merge agent config with prompt options
            $mergedOptions = array_merge($this->config, $options);

            // Apply context pruning if configured
            $messagesToSend = $this->messages;
            if ($this->contextManager) {
                $originalCount = count($this->messages);
                $messagesToSend = $this->contextManager->prune($this->messages);
                $prunedCount = count($messagesToSend);

                if ($originalCount !== $prunedCount) {
                    // Fire context pruned event
                    $this->fireEvent(new ContextPrunedEvent(
                        $this,
                        $originalCount,
                        $prunedCount,
                        ($originalCount - $prunedCount) * 100 // Rough estimate: 100 tokens per message
                    ));

                    if ($span instanceof Span) {
                        $span->addEvent('context.pruned', [
                            'from' => $originalCount,
                            'to' => $prunedCount,
                        ]);
                    }
                }
            }

            // If we have message history, pass it along
            if (! empty($messagesToSend)) {
                $mergedOptions['messages'] = $messagesToSend;
            }

            // Add tool schemas if we have tools
            if (! empty($this->tools)) {
                $mergedOptions['tools'] = $this->getToolSchemas();
            }

            // Run before middleware
            foreach ($this->middleware as $mw) {
                $mergedOptions = $mw->before($message, $mergedOptions);
            }

            // Call provider with LLM span
            $response = $this->callProviderWithSpan($message, $mergedOptions);

            // Run after middleware
            foreach ($this->middleware as $mw) {
                $response = $mw->after($response);
            }

            // Handle tool calls automatically
            $toolCallDepth = 0;
            while (! empty($response->tool_calls)) {
                $toolCallDepth++;

                if ($toolCallDepth > self::MAX_TOOL_CALL_DEPTH) {
                    throw new RuntimeException(
                        sprintf(
                            'Maximum tool call depth exceeded (%d calls). Possible infinite loop detected.',
                            self::MAX_TOOL_CALL_DEPTH
                        )
                    );
                }

                $response = $this->handleToolCalls($response);
            }

            // Run guards on the response
            if (! empty($this->guards)) {
                $this->runGuardsWithSpans($message, $response->content ?? '');
            }

            // Add final response to history
            if (isset($response->content) && ! empty($response->content)) {
                $this->messages[] = ['role' => 'assistant', 'content' => $response->content];
            }

            // Auto-save to memory if configured
            if ($this->memory && $this->sessionId) {
                $memorySpan = $this->telemetryEnabled
                    ? TelemetryManager::instance()->startSpan('memory.save', [
                        'session_id' => $this->sessionId,
                    ])
                    : new NullSpan;

                try {
                    $this->fireEvent(new MemorySavingEvent($this, $this->sessionId, $this->messages));

                    $this->memory->save($this->sessionId, $this->messages);

                    $this->fireEvent(new MemorySavedEvent($this, $this->sessionId, $this->messages));

                    $memorySpan->setAttributes([
                        'message_count' => count($this->messages),
                    ]);
                    $memorySpan->setStatus('ok');
                } catch (Throwable $e) {
                    $memorySpan->recordException($e);
                    $memorySpan->setStatus('error', $e->getMessage());

                    throw $e;
                } finally {
                    $memorySpan->end();
                }
            }

            // Set span status to ok
            if ($span instanceof Span) {
                $span->setStatus('ok');
            }

            // Fire after prompt event
            $this->fireEvent(new AfterPromptEvent(
                $this,
                $message,
                $response->content ?? '',
                $options
            ));

            return $response;
        } catch (GuardException $e) {
            if ($span instanceof Span) {
                $span->recordException($e);
                $span->setStatus('error', $e->getMessage());
            }

            if ($this->fallback) {
                // Fire guard fallback event
                $this->fireEvent(new GuardFallbackEvent(
                    $this,
                    $e->guardName,
                    1, // attemptNumber
                    1  // maxAttempts (no retry logic currently)
                ));

                $fallbackContent = ($this->fallback)($e);

                if ($span instanceof Span) {
                    $span->setStatus('ok', 'Fallback applied');
                }

                return (object) [
                    'content' => $fallbackContent,
                    'model' => 'fallback',
                    'tokens' => 0,
                    'provider' => 'fallback',
                    'guard_triggered' => $e->guardName,
                ];
            }

            throw $e;
        } catch (Throwable $e) {
            if ($span instanceof Span) {
                $span->recordException($e);
                $span->setStatus('error', $e->getMessage());
            }

            throw $e;
        } finally {
            if ($span instanceof Span) {
                $span->end();
            }

        }
    }

    /**
     * Stream a prompt to the LLM and get a streaming response
     */
    public function stream(string $message, array $options = []): StreamResponse
    {
        if (! $this->provider) {
            throw new RuntimeException("No provider set for agent '{$this->name}'");
        }

        // Check if provider supports streaming
        if (! method_exists($this->provider, 'streamPrompt')) {
            throw new RuntimeException(
                'Provider '.get_class($this->provider).' does not support streaming. '.
                'Use the prompt() method instead.'
            );
        }

        // Add to message history
        $this->messages[] = ['role' => 'user', 'content' => $message];

        // Merge agent config with prompt options
        $mergedOptions = array_merge($this->config, $options);

        // If we have message history, pass it along
        if (! empty($this->messages)) {
            $mergedOptions['messages'] = $this->messages;
        }

        // Add tool schemas if we have tools
        if (! empty($this->tools)) {
            $mergedOptions['tools'] = $this->getToolSchemas();
        }

        // Run before middleware
        foreach ($this->middleware as $mw) {
            $mergedOptions = $mw->before($message, $mergedOptions);
        }

        // Get provider info for events
        $providerName = $this->getProviderName();
        $model = $mergedOptions['model'] ?? $this->config['model'] ?? 'unknown';

        // Fire stream started event
        $this->fireEvent(new StreamStartedEvent($this, $providerName, $model));

        // Call provider's streaming method
        $streamResponse = $this->provider->streamPrompt($message, $mergedOptions);

        return $streamResponse;
    }

    /**
     * Stream a prompt and send each chunk to a callback
     */
    public function streamTo(string $message, callable $callback, array $options = []): string
    {
        // Start agent operation span
        $span = $this->startOperationSpan('stream', [
            'agent.session_id' => $this->sessionId ?? 'none',
        ]);

        $startTime = microtime(true);

        try {
            // Auto-load from memory if configured and messages are empty
            if ($this->memory && $this->sessionId && empty($this->messages)) {
                $memorySpan = $this->telemetryEnabled
                    ? TelemetryManager::instance()->startSpan('memory.load', [
                        'session_id' => $this->sessionId,
                    ])
                    : new NullSpan;

                try {
                    $this->fireEvent(new MemoryLoadingEvent($this, $this->sessionId));

                    $loaded = $this->memory->load($this->sessionId);
                    $this->messages = $loaded;

                    $this->fireEvent(new MemoryLoadedEvent($this, $this->sessionId, $loaded));

                    $memorySpan->setAttributes([
                        'message_count' => count($loaded),
                    ]);
                    $memorySpan->setStatus('ok');
                } catch (Throwable $e) {
                    $memorySpan->recordException($e);
                    $memorySpan->setStatus('error', $e->getMessage());

                    throw $e;
                } finally {
                    $memorySpan->end();
                }
            }

            $streamResponse = $this->stream($message, $options);

            // Stream to callback and collect full content
            $streamResponse->streamTo($callback);

            $fullContent = $streamResponse->getFullContent();

            // Add assistant response to history
            if (! empty($fullContent)) {
                $this->messages[] = ['role' => 'assistant', 'content' => $fullContent];
            }

            // Run guards on the full response
            if (! empty($this->guards)) {
                $this->runGuardsWithSpans($message, $fullContent);
            }

            // Auto-save to memory if configured
            if ($this->memory && $this->sessionId) {
                $memorySpan = $this->telemetryEnabled
                    ? TelemetryManager::instance()->startSpan('memory.save', [
                        'session_id' => $this->sessionId,
                    ])
                    : new NullSpan;

                try {
                    $this->fireEvent(new MemorySavingEvent($this, $this->sessionId, $this->messages));

                    $this->memory->save($this->sessionId, $this->messages);

                    $this->fireEvent(new MemorySavedEvent($this, $this->sessionId, $this->messages));

                    $memorySpan->setAttributes([
                        'message_count' => count($this->messages),
                    ]);
                    $memorySpan->setStatus('ok');
                } catch (Throwable $e) {
                    $memorySpan->recordException($e);
                    $memorySpan->setStatus('error', $e->getMessage());

                    throw $e;
                } finally {
                    $memorySpan->end();
                }
            }

            // Calculate duration and fire stream completed event
            $durationMs = (microtime(true) - $startTime) * 1000;

            // Get total chunks from stream response
            $totalChunks = count($streamResponse->getChunks());

            $this->fireEvent(new StreamCompletedEvent(
                $this,
                $fullContent,
                $totalChunks,
                $durationMs,
                $streamResponse->getProvider(),
                $streamResponse->getModel(),
                $streamResponse->getUsage() ?? []
            ));

            // Set span status to ok
            if ($span instanceof Span) {
                $span->setStatus('ok');
            }

            return $fullContent;
        } catch (GuardException $e) {
            if ($span instanceof Span) {
                $span->recordException($e);
                $span->setStatus('error', $e->getMessage());
            }

            if ($this->fallback) {
                // Fire guard fallback event
                $this->fireEvent(new GuardFallbackEvent(
                    $this,
                    $e->guardName,
                    1, // attemptNumber
                    1  // maxAttempts (no retry logic currently)
                ));

                $fallbackContent = ($this->fallback)($e);

                if ($span instanceof Span) {
                    $span->setStatus('ok', 'Fallback applied');
                }

                return $fallbackContent;
            }

            throw $e;
        } catch (Throwable $e) {
            if ($span instanceof Span) {
                $span->recordException($e);
                $span->setStatus('error', $e->getMessage());
            }

            throw $e;
        } finally {
            if ($span instanceof Span) {
                $span->end();
            }

        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProvider(): ?Provider
    {
        return $this->provider;
    }

    /**
     * Add multiple tools at once for convenience.
     *
     * @param  ToolInterface[]  $tools  Array of tool instances
     */
    public function tools(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->tool($tool);
        }

        return $this;
    }

    public function tool(string|ToolInterface $nameOrTool, ?string $description = null, ?Closure $callable = null): self
    {
        if ($nameOrTool instanceof ToolInterface) {
            $this->tools[] = $nameOrTool;
            $this->cachedToolSchemas = null; // Invalidate cache

            return $this;
        }

        if ($description === null || $callable === null) {
            throw new RuntimeException('Description and callable are required when providing tool name as string');
        }

        $this->tools[] = Tool::fromClosure($nameOrTool, $description, $callable);
        $this->cachedToolSchemas = null; // Invalidate cache

        return $this;
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function executeTool(string $name, array $arguments): mixed
    {
        // Fire tool executing event
        $this->fireEvent(new ToolExecutingEvent($this, $name, $arguments));

        $startTime = microtime(true);

        try {
            foreach ($this->tools as $tool) {
                if ($tool->name() === $name) {
                    $result = $tool->execute($arguments);

                    // Calculate duration
                    $durationMs = (microtime(true) - $startTime) * 1000;

                    // Fire tool executed event
                    $this->fireEvent(new ToolExecutedEvent(
                        $this,
                        $name,
                        $arguments,
                        $result,
                        $durationMs
                    ));

                    return $result;
                }
            }

            // Better error message with suggestions
            $available = array_map(fn ($t) => $t->name(), $this->tools);
            $suggestions = $this->findSimilarToolNames($name, $available);

            $message = "Tool '{$name}' not found";

            if (! empty($suggestions)) {
                $message .= '. Did you mean: '.implode(', ', $suggestions).'?';
            }

            if (! empty($available)) {
                $message .= ' Available tools: '.implode(', ', $available);
            }

            throw new RuntimeException($message);
        } catch (Throwable $e) {
            // Fire tool error event
            $this->fireEvent(new ToolErrorEvent($this, $name, $arguments, $e));

            throw $e;
        }
    }

    public function guard(string|Guard $guard, ?Closure $check = null): self
    {
        if ($guard instanceof Guard) {
            $this->guards[] = $guard;

            return $this;
        }

        if ($check instanceof Closure) {
            $name = is_string($guard) ? $guard : 'closure';

            $anonymousGuard = new class($name, $check) implements Guard
            {
                /**
                 * @param  Closure(string, string): bool  $check
                 */
                public function __construct(
                    private readonly string $name,
                    private readonly Closure $check,
                ) {}

                public function check(string $input, string $output): bool
                {
                    $fn = $this->check;

                    return (bool) $fn($input, $output);
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function getViolationMessage(): string
                {
                    return sprintf('Guard %s failed', $this->getName());
                }
            };

            $this->guards[] = $anonymousGuard;

            return $this;
        }

        $fqcn = 'Pagent\\Guards\\'.ucfirst($guard).'Guard';
        if (! class_exists($fqcn)) {
            throw new RuntimeException("Guard class '{$fqcn}' not found");
        }

        $this->guards[] = new $fqcn;

        return $this;
    }

    public function fallback(Closure $callback): self
    {
        $this->fallback = $callback;

        return $this;
    }

    /**
     * @return Guard[]
     */
    public function getGuards(): array
    {
        return $this->guards;
    }

    public function middleware(string|Middleware $middleware): self
    {
        if ($middleware instanceof Middleware) {
            $this->middleware[] = $middleware;
        } else {
            $middlewareClass = 'Pagent\\Middleware\\'.ucfirst($middleware).'Middleware';

            if (! class_exists($middlewareClass)) {
                throw new RuntimeException("Middleware class '{$middlewareClass}' not found");
            }

            $this->middleware[] = new $middlewareClass;
        }

        return $this;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function handoff(string|Agent $targetAgent, ?string $reason = null): Agent
    {
        $handoff = new Orchestration\Handoff($this);
        $handoff->to($targetAgent);

        if ($reason) {
            $handoff->because($reason);
        }

        return $handoff->transfer();
    }

    public function delegate(string $task): Orchestration\Delegation
    {
        return new Orchestration\Delegation($this, $task);
    }

    /**
     * Clear all tools from this agent.
     */
    public function clearTools(): self
    {
        $this->tools = [];
        $this->cachedToolSchemas = null; // Invalidate cache

        return $this;
    }

    /**
     * Clear all guards from this agent.
     */
    public function clearGuards(): self
    {
        $this->guards = [];
        $this->fallback = null;

        return $this;
    }

    /**
     * Clear all middleware from this agent.
     */
    public function clearMiddleware(): self
    {
        $this->middleware = [];

        return $this;
    }

    /**
     * Reset agent to initial state (clear history, tools, guards, middleware).
     */
    public function reset(): self
    {
        $this->messages = [];
        $this->tools = [];
        $this->guards = [];
        $this->middleware = [];
        $this->fallback = null;
        $this->memory = null;
        $this->sessionId = null;
        $this->contextManager = null;
        $this->cachedToolSchemas = null; // Invalidate cache

        return $this;
    }

    /**
     * Clone this agent with a new name.
     */
    public function clone(string $newName): Agent
    {
        $clone = new Agent($newName);
        $clone->provider = $this->provider;
        $clone->config = $this->config;
        $clone->tools = $this->tools;
        $clone->guards = $this->guards;
        $clone->middleware = $this->middleware;
        $clone->fallback = $this->fallback;
        $clone->memory = $this->memory;
        $clone->contextManager = $this->contextManager;
        $clone->telemetryEnabled = $this->telemetryEnabled;
        // Don't copy messages or sessionId - fresh conversation

        return $clone;
    }

    /**
     * Export conversation history as JSON string.
     */
    public function exportConversation(): string
    {
        return json_encode([
            'agent' => $this->name,
            'messages' => $this->messages,
            'exported_at' => date('c'),
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Import conversation history from JSON string.
     */
    public function importConversation(string $json): self
    {
        $data = json_decode($json, true);

        if (! isset($data['messages']) || ! is_array($data['messages'])) {
            throw new RuntimeException('Invalid conversation data format');
        }

        $this->messages = $data['messages'];

        return $this;
    }

    /**
     * Get usage statistics for this agent.
     */
    public function getStats(): array
    {
        $totalMessages = count($this->messages);
        $userMessages = count(array_filter($this->messages, fn ($m) => $m['role'] === 'user'));
        $assistantMessages = count(array_filter($this->messages, fn ($m) => $m['role'] === 'assistant'));

        return [
            'agent' => $this->name,
            'total_messages' => $totalMessages,
            'user_messages' => $userMessages,
            'assistant_messages' => $assistantMessages,
            'tools_registered' => count($this->tools),
            'guards_active' => count($this->guards),
            'middleware_active' => count($this->middleware),
        ];
    }

    /**
     * Get guard statistics (how many times guards were checked).
     */
    public function getGuardStats(): array
    {
        return array_map(fn ($guard) => [
            'name' => $guard->getName(),
            'active' => true,
        ], $this->guards);
    }

    /**
     * Check if agent has any messages in conversation history.
     */
    public function hasMessages(): bool
    {
        return ! empty($this->messages);
    }

    /**
     * Get the number of messages in conversation history.
     */
    public function messageCount(): int
    {
        return count($this->messages);
    }

    /**
     * Get the conversation messages.
     *
     * @return array<int, array{role: string, content: string|array}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get the last message in conversation history.
     *
     * @return array{role: string, content: string|array}|null
     */
    public function getLastMessage(): ?array
    {
        if (empty($this->messages)) {
            return null;
        }

        return $this->messages[array_key_last($this->messages)];
    }

    /**
     * Get the last assistant message content.
     */
    public function getLastAssistantMessage(): ?string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === 'assistant') {
                $content = $this->messages[$i]['content'];

                return is_string($content) ? $content : json_encode($content);
            }
        }

        return null;
    }

    /**
     * Get the last user message content.
     */
    public function getLastUserMessage(): ?string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === 'user') {
                $content = $this->messages[$i]['content'];

                return is_string($content) ? $content : json_encode($content);
            }
        }

        return null;
    }

    private function runGuards(string $input, string $output): void
    {
        foreach ($this->guards as $guard) {
            $guardName = get_class($guard);

            // Fire guard checking event
            $this->fireEvent(new GuardCheckingEvent($this, $guardName, $output));

            try {
                if (! $guard->check($input, $output)) {
                    // Fire guard violated event
                    $this->fireEvent(new GuardViolatedEvent(
                        $this,
                        $guardName,
                        $output,
                        $guard->getViolationMessage()
                    ));

                    throw new GuardException(
                        $guard->getViolationMessage(),
                        $guard->getName(),
                        $input,
                        $output,
                    );
                }

                // Fire guard passed event
                $this->fireEvent(new GuardPassedEvent($this, $guardName, $output));
            } catch (GuardException $e) {
                // Re-throw GuardException after firing event
                throw $e;
            }
        }
    }

    private function getToolSchemas(): array
    {
        // Return cached schemas if available
        if ($this->cachedToolSchemas !== null) {
            return $this->cachedToolSchemas;
        }

        $providerName = $this->getProviderName();

        $this->cachedToolSchemas = match ($providerName) {
            'anthropic' => array_map(fn ($tool) => $tool->toAnthropicSchema(), $this->tools),
            'openai', 'ollama' => array_map(fn ($tool) => $tool->toOpenAISchema(), $this->tools),
            default => [],
        };

        return $this->cachedToolSchemas;
    }

    private function handleToolCalls(object $response): object
    {
        // Add assistant message with tool calls to history
        $assistantMessage = ['role' => 'assistant', 'content' => $response->content ?? ''];

        // For Anthropic, we need to include the full content blocks
        if (isset($response->raw_content)) {
            $assistantMessage['content'] = $response->raw_content;
        } elseif (! empty($response->tool_calls)) {
            $isOllama = $this->getProviderName() === 'ollama';

            // For OpenAI and Ollama, add tool_calls
            $assistantMessage['tool_calls'] = array_map(fn ($call) => [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    // Ollama expects arguments as object/array, OpenAI expects JSON string
                    'arguments' => $isOllama ? $call['arguments'] : json_encode($call['arguments']),
                ],
            ], $response->tool_calls);
        }

        $this->messages[] = $assistantMessage;

        // Execute each tool call
        foreach ($response->tool_calls as $toolCall) {
            $arguments = $this->normalizeToolCallArguments($toolCall);
            $result = $this->executeToolWithSpan($toolCall['name'], $arguments);

            // Add tool result to messages
            if ($this->getProviderName() === 'anthropic') {
                // Anthropic format
                $this->messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolCall['id'],
                            'content' => is_string($result) ? $result : json_encode($result),
                        ],
                    ],
                ];
            } else {
                // OpenAI format
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => is_string($result) ? $result : json_encode($result),
                ];
            }
        }

        // Make another API call with tool results
        $options = $this->config;
        $options['messages'] = $this->messages;

        if (! empty($this->tools)) {
            $options['tools'] = $this->getToolSchemas();
        }

        return $this->provider->prompt('', $options);
    }

    /**
     * Normalize tool call arguments to ensure consistent format.
     * Handles edge cases where providers might return different formats.
     *
     * @param  array  $toolCall  The tool call array from the provider
     * @return array The normalized arguments as an associative array
     */
    private function normalizeToolCallArguments(array $toolCall): array
    {
        // Try 'arguments' key first (OpenAI and normalized Anthropic)
        $arguments = $toolCall['arguments'] ?? null;

        // Fallback to 'input' key (raw Anthropic format)
        if ($arguments === null && isset($toolCall['input'])) {
            $arguments = $toolCall['input'];
        }

        // If still null, return empty array
        if ($arguments === null) {
            return [];
        }

        // If it's a JSON string, decode it
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // If JSON decode failed, return empty array (invalid JSON)
            return [];
        }

        // If it's already an array, return it
        if (is_array($arguments)) {
            return $arguments;
        }

        // For any other type, return empty array
        return [];
    }

    /**
     * Find similar tool names using Levenshtein distance.
     *
     * @param  string  $needle  The tool name to find
     * @param  array  $haystack  Available tool names
     * @return array Similar tool names
     */
    private function findSimilarToolNames(string $needle, array $haystack): array
    {
        if (empty($haystack)) {
            return [];
        }

        $needleLen = strlen($needle);
        $distances = [];

        foreach ($haystack as $toolName) {
            // Early exit: if length difference > 3, skip levenshtein calculation
            $lengthDiff = abs($needleLen - strlen($toolName));
            if ($lengthDiff > 3) {
                continue;
            }

            $distances[$toolName] = levenshtein($needle, $toolName);
        }

        asort($distances);
        $closest = array_slice($distances, 0, 3, true);

        // Only return suggestions with distance <= 3
        return array_keys(array_filter($closest, fn ($dist) => $dist <= 3));
    }

    private function startOperationSpan(string $operation, array $attributes): Span|NullSpan|null
    {
        if (! $this->telemetryEnabled) {
            return null;
        }

        return TelemetryManager::instance()->startAgentSpan($operation, $this->name, $attributes);
    }

    private function callProviderWithSpan(string $message, array $options): object
    {
        if (! $this->telemetryEnabled) {
            if (! $this->provider) {
                throw new RuntimeException("No provider set for agent '{$this->name}'");
            }

            $providerName = $this->getProviderName();
            $model = $options['model'] ?? $this->config['model'] ?? 'unknown';
            $startTime = microtime(true);

            // Fire before LLM request event
            $this->fireEvent(new BeforeLLMRequestEvent(
                $this,
                $providerName,
                $model,
                $options
            ));

            $response = $this->provider->prompt($message, $options);

            // Fire after LLM response event
            $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            $this->fireEvent(new AfterLLMResponseEvent(
                $this,
                $providerName,
                $model,
                (array) $response,
                $duration
            ));

            return $response;
        }

        if (! $this->provider) {
            throw new RuntimeException("No provider set for agent '{$this->name}'");
        }

        $providerName = $this->getProviderName();
        $model = $options['model'] ?? $this->config['model'] ?? 'unknown';

        $llmSpan = TelemetryManager::instance()->startLLMSpan($providerName, $model, [
            'gen_ai.request.temperature' => $options['temperature'] ?? $this->config['temperature'] ?? null,
            'gen_ai.request.max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? null,
        ]);

        $startTime = microtime(true);

        try {
            // Fire before LLM request event
            $this->fireEvent(new BeforeLLMRequestEvent(
                $this,
                $providerName,
                $model,
                $options
            ));

            $response = $this->provider->prompt($message, $options);

            $this->addLLMSpanAttributes($llmSpan, $response);

            // Fire after LLM response event
            $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            $this->fireEvent(new AfterLLMResponseEvent(
                $this,
                $providerName,
                $model,
                (array) $response,
                $duration
            ));

            if ($llmSpan instanceof Span) {
                $llmSpan->setStatus('ok');
            }

            return $response;
        } catch (Throwable $e) {
            if ($llmSpan instanceof Span) {
                $llmSpan->recordException($e);
                $llmSpan->setStatus('error', $e->getMessage());
            }

            throw $e;
        } finally {
            if ($llmSpan instanceof Span) {
                $llmSpan->end();
            }
        }
    }

    private function addLLMSpanAttributes(Span|NullSpan $span, object $response): void
    {
        if (! $span instanceof Span) {
            return;
        }

        if (property_exists($response, 'model') && isset($response->model)) {
            $span->setAttribute('gen_ai.response.model', $response->model);
        }

        if (property_exists($response, 'tokens') && isset($response->tokens)) {
            $span->setAttribute('gen_ai.usage.total_tokens', $response->tokens);
            // For mock provider and simple token responses, also set completion_tokens
            $span->setAttribute('gen_ai.usage.completion_tokens', $response->tokens);
        }

        if (property_exists($response, 'usage') && isset($response->usage) && is_array($response->usage)) {
            if (isset($response->usage['input_tokens'])) {
                $span->setAttribute('gen_ai.usage.input_tokens', $response->usage['input_tokens']);
                // OpenAI uses 'prompt_tokens' attribute name
                $span->setAttribute('gen_ai.usage.prompt_tokens', $response->usage['input_tokens']);
            }

            if (isset($response->usage['output_tokens'])) {
                $span->setAttribute('gen_ai.usage.output_tokens', $response->usage['output_tokens']);
                // OpenAI/GenAI standard uses 'completion_tokens' attribute name
                $span->setAttribute('gen_ai.usage.completion_tokens', $response->usage['output_tokens']);
            }

            if (isset($response->usage['total_tokens'])) {
                $span->setAttribute('gen_ai.usage.total_tokens', $response->usage['total_tokens']);
            }
        }
    }

    private function runGuardsWithSpans(string $input, string $output): void
    {
        if (! $this->telemetryEnabled) {
            $this->runGuards($input, $output);

            return;
        }

        foreach ($this->guards as $guard) {
            $guardSpan = TelemetryManager::instance()->startSpan('guard.check', [
                'guard.name' => $guard->getName(),
            ]);

            try {
                $passed = $guard->check($input, $output);

                if ($guardSpan instanceof Span) {
                    $guardSpan->setAttribute('guard.passed', $passed);
                    $guardSpan->setStatus('ok');
                }

                if (! $passed) {
                    $exception = new GuardException(
                        $guard->getViolationMessage(),
                        $guard->getName(),
                        $input,
                        $output,
                    );

                    if ($guardSpan instanceof Span) {
                        $guardSpan->recordException($exception);
                        $guardSpan->setStatus('error', 'Guard failed');
                    }

                    throw $exception;
                }
            } catch (GuardException $e) {
                throw $e;
            } catch (Throwable $e) {
                if ($guardSpan instanceof Span) {
                    $guardSpan->recordException($e);
                    $guardSpan->setStatus('error', $e->getMessage());
                }

                throw $e;
            } finally {
                if ($guardSpan instanceof Span) {
                    $guardSpan->end();
                }
            }
        }
    }

    private function executeToolWithSpan(string $name, array $arguments): mixed
    {
        if (! $this->telemetryEnabled) {
            return $this->executeTool($name, $arguments);
        }

        $toolSpan = TelemetryManager::instance()->startToolSpan($name, $arguments);

        try {
            $result = $this->executeTool($name, $arguments);

            if ($toolSpan instanceof Span) {
                $resultType = get_debug_type($result);
                $toolSpan->setAttribute('tool.result_type', $resultType);
                $toolSpan->setStatus('ok');
            }

            return $result;
        } catch (Throwable $e) {
            if ($toolSpan instanceof Span) {
                $toolSpan->recordException($e);
                $toolSpan->setStatus('error', $e->getMessage());
            }

            throw $e;
        } finally {
            if ($toolSpan instanceof Span) {
                $toolSpan->end();
            }
        }
    }

    /**
     * Fire an event to all registered listeners.
     *
     * @param  Event  $event  The event to fire
     */
    private function fireEvent(Event $event): void
    {
        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Get the provider name string for the current provider.
     *
     * @return string One of: 'anthropic', 'openai', 'ollama', 'mock'
     */
    private function getProviderName(): string
    {
        if ($this->provider === null) {
            return 'mock';
        }

        $providerClass = get_class($this->provider);

        return match (true) {
            str_contains($providerClass, 'Anthropic') => 'anthropic',
            str_contains($providerClass, 'OpenAI') => 'openai',
            str_contains($providerClass, 'Ollama') => 'ollama',
            default => 'mock',
        };
    }
}
