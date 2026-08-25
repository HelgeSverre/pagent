<?php

declare(strict_types=1);

namespace Pagent;

use Closure;
use Generator;
use Pagent\Contracts\Guard;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\InputGuard;
use Pagent\Contracts\Memory;
use Pagent\Contracts\Middleware;
use Pagent\Contracts\OutputGuard;
use Pagent\Contracts\Provider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Contracts\Tool as ToolContract;
use Pagent\Events\Event;
use Pagent\Events\EventDispatcher;
use Pagent\Events\EventListener;
use Pagent\Events\EventManager;
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
use Pagent\Events\Events\Stream\StreamChunkEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Events\Events\Stream\StreamStartedEvent;
use Pagent\Events\Events\Tool\ToolErrorEvent;
use Pagent\Events\Events\Tool\ToolExecutedEvent;
use Pagent\Events\Events\Tool\ToolExecutingEvent;
use Pagent\Exceptions\GuardException;
use Pagent\Guards\LegacyGuardAdapter;
use Pagent\Memory\Adapters\FileAdapter;
use Pagent\Memory\Adapters\NullAdapter;
use Pagent\Memory\Adapters\SqliteAdapter;
use Pagent\Memory\ContextManager;
use Pagent\Observability\NullSpan;
use Pagent\Observability\Span;
use Pagent\Observability\TelemetryManager;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;
use Pagent\Tool\Tool;
use Pagent\Tool\ToolCallArgumentNormalizer;
use Pagent\Tool\ToolSchemaSerializer;
use Pagent\Usage\Storage\UsageStorage;
use Pagent\Usage\UsageData;
use Pagent\Usage\UsageTracker;
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
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function levenshtein;
use function sprintf;
use function strlen;
use function strtolower;
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

    /** @var ToolContract[] */
    private array $tools = [];

    /** @var Guard[] */
    private array $guards = [];

    /** @var Middleware[] */
    private array $middleware = [];

    private ?Closure $fallback = null;

    private ?Memory $memory = null;

    private ?string $sessionId = null;

    private bool $sessionLoaded = false;

    private bool $turnInProgress = false;

    private ?ContextManager $contextManager = null;

    public bool $telemetryEnabled = false;

    private ?array $cachedToolSchemas = null;

    private EventDispatcher $eventDispatcher;

    private ?UsageTracker $usageTracker = null;

    public function __construct(
        private readonly string $name,
    ) {
        $this->eventDispatcher = new EventDispatcher;
    }

    public function provider(string|Provider $provider, array $config = []): self
    {
        $this->provider = ProviderFactory::resolve($provider, $config);
        $this->cachedToolSchemas = null;

        return $this;
    }

    /**
     * Compatibility terminal for code that previously received AgentBuilder.
     */
    public function build(): self
    {
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
            $this->sessionLoaded = false;

            return $this;
        }

        $this->memory = match (strtolower($adapter)) {
            'file' => new FileAdapter($config),
            'sqlite' => new SqliteAdapter($config),
            'null' => new NullAdapter($config),
            default => throw new RuntimeException("Unknown memory adapter '{$adapter}'"),
        };
        $this->sessionLoaded = false;

        return $this;
    }

    public function sessionId(string $id): self
    {
        if ($this->turnInProgress) {
            throw new RuntimeException('Cannot switch sessions while a turn is in progress.');
        }

        if ($this->sessionId === $id) {
            return $this;
        }

        $this->sessionId = $id;
        $this->messages = [];
        $this->sessionLoaded = false;

        return $this;
    }

    /**
     * Create an isolated conversation instance from this agent definition.
     */
    public function forSession(string $id): self
    {
        return $this->clone($this->name)->sessionId($id);
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
     * @param  array{enabled?: bool, track_llm?: bool, track_streaming?: bool, storage?: UsageStorage, pricing?: array<string, array<string, array{input: float, output: float, cached_input?: float}>>}  $config  Tracker configuration
     */
    public function trackUsage(array $config = []): self
    {
        $this->usageTracker = new UsageTracker($config);

        // Dedicated trackers stay scoped to this agent definition. Global
        // tracking is configured separately through usage_tracker().
        foreach ($this->usageTracker->listensTo() as $eventName) {
            $this->eventDispatcher->on($eventName, $this->usageTracker);
        }

        return $this;
    }

    /**
     * Get usage data for this agent.
     *
     * Returns usage records filtered by this agent's name from either:
     * - The agent's dedicated tracker (if trackUsage() was called)
     * - The global tracker (if usage_tracker() was called globally)
     *
     * @return array<int, UsageData>
     */
    public function getUsage(): array
    {
        // Check if this agent has its own tracker
        if ($this->usageTracker !== null) {
            return $this->usageTracker->byAgent($this->name);
        }

        // Fall back to global tracker if available
        try {
            $globalTracker = UsageTracker::global();

            return $globalTracker->byAgent($this->name);
        } catch (Throwable) {
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

        $this->beginTurn();

        // Start agent operation span
        $span = $this->startOperationSpan('prompt', [
            'agent.session_id' => $this->sessionId ?? 'none',
        ]);
        $conversationSnapshot = null;
        $turnCommitted = false;

        try {
            $this->loadMemory();

            $conversationSnapshot = $this->messages;

            // Input guards must run before the prompt reaches a provider or tool.
            $this->runInputGuardsWithSpans($message);

            // Add to message history
            $this->messages[] = ['role' => 'user', 'content' => $message];

            // Fire before prompt event
            $this->fireEvent(new BeforePromptEvent($this, $message, $options));

            $mergedOptions = $this->prepareProviderOptions($this->messages, $options, $span);

            $response = $this->callProviderRound($message, $mergedOptions);

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

                $response = $this->handleToolCalls($response, $mergedOptions);
            }

            $content = $this->responseContent($response);
            $this->runOutputGuardsWithSpans($message, $content);

            // Add final response to history
            if ($content !== '') {
                $this->messages[] = ['role' => 'assistant', 'content' => $content];
            }

            $this->saveMemory($turnCommitted);

            // Set span status to ok
            if ($span instanceof Span) {
                $span->setStatus('ok');
            }

            // Fire after prompt event
            $this->fireEvent(new AfterPromptEvent(
                $this,
                $message,
                $content,
                $options
            ));

            return $response;
        } catch (GuardException $e) {
            if ($this->shouldBypassGuardFallback($e, $turnCommitted)) {
                if ($span instanceof Span) {
                    $span->recordException($e);
                    $span->setStatus('error', $e->getMessage());
                }

                throw $e;
            }

            $this->messages = $conversationSnapshot;

            if ($span instanceof Span) {
                $span->recordException($e);
                $span->setStatus('error', $e->getMessage());
            }

            if ($this->fallback) {
                try {
                    $fallbackContent = $this->commitGuardFallback(
                        $e,
                        $message,
                        $options,
                        $turnCommitted,
                    );
                } catch (Throwable $fallbackException) {
                    if (! $turnCommitted) {
                        $this->messages = $conversationSnapshot;
                    }

                    throw $fallbackException;
                }

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
            if ($conversationSnapshot !== null && ! $turnCommitted) {
                $this->messages = $conversationSnapshot;
            }

            if ($span instanceof Span) {
                $span->recordException($e);
                $span->setStatus('error', $e->getMessage());
            }

            throw $e;
        } finally {
            if ($span instanceof Span) {
                $span->end();
            }
            $this->endTurn();
        }
    }

    /**
     * Stream a prompt to the LLM and get a streaming response
     */
    public function stream(string $message, array $options = []): StreamResponse
    {
        if (! $this->provider instanceof StreamingProvider) {
            throw new RuntimeException("No provider set for agent '{$this->name}'");
        }

        $this->beginTurn();
        $span = $this->startOperationSpan('stream', [
            'agent.session_id' => $this->sessionId ?? 'none',
        ]);
        $startedAt = microtime(true);
        $committed = false;

        try {
            $this->loadMemory();
            $snapshot = $this->messages;
            $this->runInputGuardsWithSpans($message);
            $this->messages[] = ['role' => 'user', 'content' => $message];
            $this->fireEvent(new BeforePromptEvent($this, $message, $options));
            $mergedOptions = $this->prepareProviderOptions($this->messages, $options, $span);

            foreach ($this->middleware as $middleware) {
                $mergedOptions = $middleware->before($message, $mergedOptions);
            }

            $providerName = $this->getProviderName();
            $model = $mergedOptions['model'] ?? $this->config['model'] ?? 'unknown';
            $this->fireEvent(new StreamStartedEvent($this, $providerName, $model));

            $providerResponse = $this->provider->streamPrompt($message, $mergedOptions);
            $buffered = $this->requiresBufferedStreaming();
            $stream = new StreamResponse(
                stream: $this->streamWithPolicies($providerResponse, $message, $buffered),
                provider: $providerResponse->getProvider(),
                model: $providerResponse->getModel(),
                canceller: static function () use ($providerResponse): void {
                    $providerResponse->cancel();
                },
            );

            $stream->onComplete(function (StreamResponse $response) use ($message, $options, $snapshot, $span, $startedAt, &$committed): void {
                try {
                    $content = $response->getFullContent();
                    if ($content !== '') {
                        $this->messages[] = ['role' => 'assistant', 'content' => $content];
                    }
                    $this->saveMemory($committed);
                    $this->fireEvent(new AfterPromptEvent($this, $message, $content, $options));
                    $this->fireEvent(new StreamCompletedEvent(
                        $this,
                        $content,
                        count($response->getChunks()),
                        (microtime(true) - $startedAt) * 1000,
                        $response->getProvider(),
                        $response->getModel(),
                        $response->getUsage() ?? []
                    ));
                    $span?->setStatus('ok');
                } catch (Throwable $exception) {
                    if (! $committed) {
                        $this->messages = $snapshot;
                    }
                    $span?->recordException($exception);
                    $span?->setStatus('error', $exception->getMessage());

                    throw $exception;
                } finally {
                    $span?->end();
                    $this->endTurn();
                }
            });

            $stream->onError(function (Throwable $exception) use ($snapshot, $span, &$committed): void {
                if (! $committed) {
                    $this->messages = $snapshot;
                }
                $span?->recordException($exception);
                $span?->setStatus('error', $exception->getMessage());
                $span?->end();
                $this->endTurn();
            });
            $stream->onCancel(function () use ($snapshot, $span): void {
                $this->messages = $snapshot;
                $span?->end();
                $this->endTurn();
            });

            return $stream;
        } catch (Throwable $exception) {
            if (isset($snapshot)) {
                $this->messages = $snapshot;
            }
            $span?->recordException($exception);
            $span?->setStatus('error', $exception->getMessage());
            $span?->end();
            $this->endTurn();

            throw $exception;
        }
    }

    /**
     * Stream a prompt and send each chunk to a callback
     */
    public function streamTo(string $message, callable $callback, array $options = []): string
    {
        $callbackFailure = new class
        {
            public ?Throwable $exception = null;
        };

        try {
            $response = $this->stream($message, $options);
            $response->streamTo(function (StreamChunk $chunk) use ($callback, $callbackFailure): void {
                try {
                    $callback($chunk);
                } catch (Throwable $exception) {
                    $callbackFailure->exception = $exception;

                    throw $exception;
                }
            });

            return $response->getFullContent();
        } catch (GuardException $exception) {
            if ($callbackFailure->exception === $exception) {
                throw $exception;
            }

            if (! $exception->policyViolation || $this->fallback === null) {
                throw $exception;
            }

            // stream() has already restored the conversation and released the
            // failed turn. Commit the fallback as its own atomic terminal path.
            $this->beginTurn();
            $snapshot = $this->messages;
            $committed = false;

            try {
                return $this->commitGuardFallback(
                    $exception,
                    $message,
                    $options,
                    $committed,
                );
            } catch (Throwable $fallbackException) {
                if (! $committed) {
                    $this->messages = $snapshot;
                }

                throw $fallbackException;
            } finally {
                $this->endTurn();
            }
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** Return the active persistence/session boundary, if configured. */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getProvider(): ?Provider
    {
        return $this->provider;
    }

    /**
     * Add multiple tools at once for convenience.
     *
     * @param  ToolContract[]  $tools  Array of tool instances
     */
    public function tools(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->tool($tool);
        }

        return $this;
    }

    public function tool(string|ToolContract $nameOrTool, ?string $description = null, ?Closure $callable = null): self
    {
        if ($nameOrTool instanceof ToolContract) {
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
     * @return ToolContract[]
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
                if ($tool->getName() === $name) {
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
            $available = array_map(fn ($t) => $t->getName(), $this->tools);
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
            $this->guards[] = new LegacyGuardAdapter($name, $check);

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
        if ($this->turnInProgress) {
            throw new RuntimeException('Cannot reset an agent while a turn is in progress.');
        }

        $this->messages = [];
        $this->tools = [];
        $this->guards = [];
        $this->middleware = [];
        $this->fallback = null;
        $this->memory = null;
        $this->sessionId = null;
        $this->sessionLoaded = false;
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
        // Event and usage configuration belong to the reusable definition;
        // conversation state remains isolated on the cloned Agent.
        $clone->eventDispatcher = $this->eventDispatcher;
        $clone->usageTracker = $this->usageTracker;
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

                return is_string($content) ? $content : json_encode($content, JSON_THROW_ON_ERROR);
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

                return is_string($content) ? $content : json_encode($content, JSON_THROW_ON_ERROR);
            }
        }

        return null;
    }

    private function runInputGuardsWithSpans(string $input): void
    {
        foreach ($this->guards as $guard) {
            if ($guard instanceof InputGuard) {
                $this->checkGuardWithSpan($guard, $input, '', 'input');
            }
        }
    }

    private function runOutputGuardsWithSpans(string $input, string $output): void
    {
        foreach ($this->guards as $guard) {
            if ($guard instanceof OutputGuard) {
                $this->checkGuardWithSpan($guard, $input, $output, 'output');

                continue;
            }

            // A legacy guard has no declared phase, so it remains an output
            // policy for backwards compatibility. InputGuard is never rerun.
            if (! $guard instanceof InputGuard) {
                $this->checkGuardWithSpan($guard, $input, $output, 'legacy');
            }
        }
    }

    private function getToolSchemas(): array
    {
        // Return cached schemas if available
        if ($this->cachedToolSchemas !== null) {
            return $this->cachedToolSchemas;
        }

        $capabilities = $this->provider instanceof IdentifiedProvider
            ? $this->provider->capabilities()
            : null;
        $protocol = $capabilities?->supportsTools === true
            ? $capabilities->toolProtocol
            : 'none';

        $this->cachedToolSchemas = match ($protocol) {
            'anthropic' => array_map(ToolSchemaSerializer::anthropic(...), $this->tools),
            'openai' => array_map(ToolSchemaSerializer::openAI(...), $this->tools),
            default => [],
        };

        return $this->cachedToolSchemas;
    }

    private function handleToolCalls(object $response, array $turnOptions): object
    {
        // Add assistant message with tool calls to history
        $assistantMessage = ['role' => 'assistant', 'content' => $response->content ?? ''];

        // For Anthropic, we need to include the full content blocks
        if (isset($response->raw_content)) {
            $assistantMessage['content'] = $response->raw_content;
        } elseif (! empty($response->tool_calls)) {
            $isOllama = $this->getProviderProtocol() === 'ollama-chat';

            // For OpenAI and Ollama, add tool_calls
            $assistantMessage['tool_calls'] = array_map(fn ($call) => [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    // Ollama expects arguments as object/array, OpenAI expects JSON string
                    'arguments' => $isOllama ? $call['arguments'] : json_encode($call['arguments'], JSON_THROW_ON_ERROR),
                ],
            ], $response->tool_calls);
        }

        $this->messages[] = $assistantMessage;

        // Execute each tool call
        foreach ($response->tool_calls as $toolCall) {
            $arguments = $this->normalizeToolCallArguments($toolCall);
            $this->runInputGuardsWithSpans(json_encode($arguments, JSON_THROW_ON_ERROR));
            $result = $this->executeToolWithSpan($toolCall['name'], $arguments);

            // Add tool result to messages
            if ($this->getProviderProtocol() === 'anthropic-messages') {
                // Anthropic format
                $this->messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolCall['id'],
                            'content' => $this->serializeToolResult($toolCall['name'], $result),
                        ],
                    ],
                ];
            } else {
                // OpenAI format
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => $this->serializeToolResult($toolCall['name'], $result),
                ];
            }
        }

        // Preserve every per-turn option and reapply the context window after
        // appending tool messages. Otherwise a pruned initial request can grow
        // back to the full conversation on its first follow-up round.
        $options = $this->prepareProviderOptions($this->messages, $turnOptions);

        return $this->callProviderRound('', $options);
    }

    private function serializeToolResult(string $toolName, mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        try {
            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Tool '{$toolName}' returned a result that cannot be encoded as JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }
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

        $toolName = is_string($toolCall['name'] ?? null) ? $toolCall['name'] : 'unknown';

        return ToolCallArgumentNormalizer::normalize($arguments, "Tool call '{$toolName}'");
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

    private function beginTurn(): void
    {
        if ($this->turnInProgress) {
            throw new RuntimeException(
                "Agent '{$this->name}' already has a turn in progress; use a separate session agent for concurrent work."
            );
        }

        $this->turnInProgress = true;
    }

    private function endTurn(): void
    {
        $this->turnInProgress = false;
    }

    /**
     * Execute one provider round through the complete middleware/event/telemetry
     * lifecycle. Initial prompts and every tool follow-up use this same path.
     */
    private function callProviderRound(string $message, array $options): object
    {
        foreach ($this->middleware as $middleware) {
            $options = $middleware->before($message, $options);
        }

        $response = $this->callProviderWithSpan($message, $options);

        foreach ($this->middleware as $middleware) {
            $response = $middleware->after($response);
        }

        return $response;
    }

    /**
     * Commit a guard fallback with the same history, persistence, and event
     * semantics regardless of whether the failed turn was prompted or streamed.
     *
     * @param  array<string, mixed>  $options
     */
    private function commitGuardFallback(
        GuardException $exception,
        string $message,
        array $options,
        bool &$committed,
    ): string {
        $fallback = $this->fallback;
        if ($fallback === null) {
            throw $exception;
        }

        $this->fireEvent(new GuardFallbackEvent(
            $this,
            $exception->guardName,
            1,
            1,
        ));

        $fallbackContent = $fallback($exception);
        if (! is_string($fallbackContent)) {
            throw new RuntimeException(sprintf(
                'Guard fallback must return a string, got %s',
                get_debug_type($fallbackContent),
            ));
        }

        // A blocked input must not be retained and replayed to the model on the
        // next turn. Output/tool failures keep the accepted user input.
        $this->messages[] = [
            'role' => 'user',
            'content' => $exception->phase === 'input'
                ? sprintf('[Message blocked by guard: %s]', $exception->guardName)
                : $message,
        ];
        if ($fallbackContent !== '') {
            $this->messages[] = ['role' => 'assistant', 'content' => $fallbackContent];
        }

        $this->saveMemory($committed);
        $this->fireEvent(new AfterPromptEvent(
            $this,
            $message,
            $fallbackContent,
            $options,
        ));

        return $fallbackContent;
    }

    private function shouldBypassGuardFallback(GuardException $exception, bool $committed): bool
    {
        return ! $exception->policyViolation || $committed;
    }

    private function saveMemory(bool &$committed): void
    {
        if (! $this->memory || ! $this->sessionId) {
            $committed = true;

            return;
        }

        $memorySpan = $this->telemetryEnabled
            ? TelemetryManager::instance()->startSpan('memory.save', [
                'session_id' => $this->sessionId,
            ])
            : new NullSpan;

        try {
            $this->fireEvent(new MemorySavingEvent($this, $this->sessionId, $this->messages));
            $this->memory->save($this->sessionId, $this->messages);
            $committed = true;
            $this->fireEvent(new MemorySavedEvent($this, $this->sessionId, $this->messages));
            $memorySpan->setAttributes(['message_count' => count($this->messages)]);
            $memorySpan->setStatus('ok');
        } catch (Throwable $exception) {
            $memorySpan->recordException($exception);
            $memorySpan->setStatus('error', $exception->getMessage());

            throw $exception;
        } finally {
            $memorySpan->end();
        }
    }

    private function loadMemory(): void
    {
        if (! $this->memory || ! $this->sessionId || $this->sessionLoaded) {
            return;
        }

        $memorySpan = $this->telemetryEnabled
            ? TelemetryManager::instance()->startSpan('memory.load', [
                'session_id' => $this->sessionId,
            ])
            : new NullSpan;

        try {
            $this->fireEvent(new MemoryLoadingEvent($this, $this->sessionId));
            $loaded = $this->memory->load($this->sessionId);
            $this->messages = $loaded;
            $this->sessionLoaded = true;
            $this->fireEvent(new MemoryLoadedEvent($this, $this->sessionId, $loaded));
            $memorySpan->setAttributes(['message_count' => count($loaded)]);
            $memorySpan->setStatus('ok');
        } catch (Throwable $exception) {
            $memorySpan->recordException($exception);
            $memorySpan->setStatus('error', $exception->getMessage());

            throw $exception;
        } finally {
            $memorySpan->end();
        }
    }

    private function prepareProviderOptions(array $messages, array $options, Span|NullSpan|null $span = null): array
    {
        $mergedOptions = array_merge($this->config, $options);
        $messagesToSend = $messages;

        if ($this->contextManager) {
            $originalCount = count($messages);
            $messagesToSend = $this->contextManager->prune($messages);
            $prunedCount = count($messagesToSend);

            if ($originalCount !== $prunedCount) {
                $this->fireEvent(new ContextPrunedEvent(
                    $this,
                    $originalCount,
                    $prunedCount,
                    ($originalCount - $prunedCount) * 100
                ));
                $span?->addEvent('context.pruned', [
                    'from' => $originalCount,
                    'to' => $prunedCount,
                ]);
            }
        }

        if ($messagesToSend !== []) {
            $mergedOptions['messages'] = $messagesToSend;
        }

        if ($this->tools !== []) {
            $schemas = $this->getToolSchemas();
            if ($schemas !== []) {
                $mergedOptions['tools'] = $schemas;
            }
        }

        return $mergedOptions;
    }

    private function requiresBufferedStreaming(): bool
    {
        if ($this->middleware !== []) {
            return true;
        }

        foreach ($this->guards as $guard) {
            if ($guard instanceof OutputGuard) {
                if (! $guard->supportsIncrementalInspection()) {
                    return true;
                }

                continue;
            }

            if (! $guard instanceof InputGuard) {
                return true;
            }
        }

        return false;
    }

    /** @return Generator<StreamChunk> */
    private function streamWithPolicies(StreamResponse $providerResponse, string $input, bool $buffered): Generator
    {
        $chunkNumber = 0;

        if ($buffered) {
            $chunks = [];
            foreach ($providerResponse->getStream() as $chunk) {
                $chunks[] = $chunk;
            }

            $rawContent = $providerResponse->getFullContent();
            $processed = (object) [
                'content' => $rawContent,
                'provider' => $providerResponse->getProvider(),
                'model' => $providerResponse->getModel(),
                'usage' => $providerResponse->getUsage(),
            ];

            foreach ($this->middleware as $middleware) {
                $processed = $middleware->after($processed);
            }

            $processedData = (array) $processed;
            $processedContent = is_string($processedData['content'] ?? null)
                ? $processedData['content']
                : $rawContent;
            $this->runOutputGuardsWithSpans($input, $processedContent);

            if ($processedContent === $rawContent) {
                foreach ($chunks as $chunk) {
                    if ($chunk->isText()) {
                        $this->fireEvent(new StreamChunkEvent($this, $chunk->content, ++$chunkNumber));
                    }

                    yield $chunk;
                }

                return;
            }

            yield StreamChunk::start([
                'provider' => $providerResponse->getProvider(),
                'model' => $providerResponse->getModel(),
            ]);
            if ($processedContent !== '') {
                $this->fireEvent(new StreamChunkEvent($this, $processedContent, ++$chunkNumber));
                yield StreamChunk::text($processedContent);
            }
            yield StreamChunk::end([
                'usage' => $providerResponse->getUsage(),
                'stop_reason' => $providerResponse->getStopReason(),
            ]);

            return;
        }

        $accumulated = '';
        foreach ($providerResponse->getStream() as $chunk) {
            if ($chunk->isText()) {
                $accumulated .= $chunk->content;
                foreach ($this->guards as $guard) {
                    if ($guard instanceof OutputGuard && $guard->supportsIncrementalInspection()) {
                        $this->checkGuardWithSpan($guard, $input, $accumulated, 'output');
                    }
                }

                $this->fireEvent(new StreamChunkEvent($this, $chunk->content, ++$chunkNumber));
            }

            yield $chunk;
        }

        if ($accumulated === '') {
            foreach ($this->guards as $guard) {
                if ($guard instanceof OutputGuard && $guard->supportsIncrementalInspection()) {
                    $this->checkGuardWithSpan($guard, $input, '', 'output');
                }
            }
        }
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

    private function checkGuardWithSpan(Guard $guard, string $input, string $output, string $phase): void
    {
        $subject = $phase === 'input' ? $input : $output;
        $guardName = $guard->getName();
        $guardSpan = $this->telemetryEnabled
            ? TelemetryManager::instance()->startSpan('guard.check', [
                'guard.name' => $guardName,
                'guard.phase' => $phase,
            ])
            : new NullSpan;

        $this->fireEvent(new GuardCheckingEvent($this, $guardName, $subject));

        try {
            $passed = match ($phase) {
                'input' => $guard instanceof InputGuard && $guard->checkInput($input),
                'output' => $guard instanceof OutputGuard && $guard->checkOutput($output),
                default => $guard->check($input, $output),
            };

            if ($guardSpan instanceof Span) {
                $guardSpan->setAttribute('guard.passed', $passed);
            }

            if (! $passed) {
                $this->fireEvent(new GuardViolatedEvent(
                    $this,
                    $guardName,
                    $subject,
                    $guard->getViolationMessage()
                ));

                $exception = new GuardException(
                    $guard->getViolationMessage(),
                    $guardName,
                    $input,
                    $output,
                    $phase,
                    true,
                );

                if ($guardSpan instanceof Span) {
                    $guardSpan->recordException($exception);
                    $guardSpan->setStatus('error', 'Guard failed');
                }

                throw $exception;
            }

            $this->fireEvent(new GuardPassedEvent($this, $guardName, $subject));
            $guardSpan->setStatus('ok');
        } catch (Throwable $exception) {
            if ($guardSpan instanceof Span && ! $exception instanceof GuardException) {
                $guardSpan->recordException($exception);
                $guardSpan->setStatus('error', $exception->getMessage());
            }

            throw $exception;
        } finally {
            $guardSpan->end();
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
        EventManager::publish($event, $this->eventDispatcher);
    }

    /**
     * Get the provider name string for the current provider.
     *
     * @return string One of: 'anthropic', 'openai', 'ollama', 'mock'
     */
    private function getProviderName(): string
    {
        if ($this->provider === null) {
            return 'unknown';
        }

        return $this->provider instanceof IdentifiedProvider
            ? $this->provider->providerId()
            : 'custom';
    }

    private function responseContent(object $response): string
    {
        $data = (array) $response;

        return is_string($data['content'] ?? null) ? $data['content'] : '';
    }

    private function getProviderProtocol(): string
    {
        return $this->provider instanceof IdentifiedProvider
            ? $this->provider->capabilities()->protocol
            : 'unknown';
    }
}
