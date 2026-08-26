<?php

declare(strict_types=1);

namespace Pagent\Observability;

use Pagent\Agent;
use Pagent\Events\Event;
use Pagent\Events\EventListener;
use Pagent\Events\EventManager;
use Pagent\Events\Events\Guard\GuardCheckingEvent;
use Pagent\Events\Events\Guard\GuardFallbackEvent;
use Pagent\Events\Events\Guard\GuardPassedEvent;
use Pagent\Events\Events\Guard\GuardViolatedEvent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;
use Pagent\Events\Events\Mcp\McpConnectionEstablishedEvent;
use Pagent\Events\Events\Mcp\McpConnectionFailedEvent;
use Pagent\Events\Events\Mcp\McpToolCalledEvent;
use Pagent\Events\Events\Mcp\McpToolCallingEvent;
use Pagent\Events\Events\Mcp\McpToolErrorEvent;
use Pagent\Events\Events\Mcp\McpToolsDiscoveredEvent;
use Pagent\Events\Events\Mcp\McpToolsDiscoveringEvent;
use Pagent\Events\Events\Memory\MemoryLoadedEvent;
use Pagent\Events\Events\Memory\MemoryLoadingEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Events\Events\Stream\StreamStartedEvent;
use Pagent\Events\Events\Tool\ToolErrorEvent;
use Pagent\Events\Events\Tool\ToolExecutedEvent;
use Pagent\Events\Events\Tool\ToolExecutingEvent;
use Pagent\Events\EventSubscription;
use Pagent\Exceptions\ConfigurationException;

use function microtime;

/**
 * TelemetryEventBridge automatically creates OpenTelemetry spans from Events.
 *
 * This bridge listens to agent events and creates corresponding telemetry spans,
 * eliminating the need for manual span creation throughout the Agent class.
 *
 * Currently supports:
 * - LLM request/response operations
 *
 * Future support planned for:
 * - Tool execution
 * - Memory operations
 * - Guard checks
 * - Stream operations
 *
 * @example
 * ```php
 * // Register bridge globally
 * EventManager::instance()->listen(new TelemetryEventBridge());
 *
 * // Or use helper function
 * telemetry_bridge();
 *
 * // Now all LLM operations automatically create spans
 * agent('bot')->prompt('Hello'); // Creates llm.request span automatically
 * ```
 */
final class TelemetryEventBridge implements EventListener
{
    /**
     * Process-wide bridge used by telemetry_bridge().
     */
    private static ?self $globalInstance = null;

    /**
     * Registration for the global bridge, if one is active.
     */
    private static ?EventSubscription $globalSubscription = null;

    /**
     * EventManager generation that owns the current registration.
     */
    private static ?int $globalManagerGeneration = null;

    /**
     * Active spans indexed by operation key.
     *
     * Format: ['llm:{agent_name}#{spl_object_id}' => ['span' => Span, 'started_at' => float]]
     *
     * @var array<string, array{span: Span|NullSpan, started_at: float}>
     */
    private array $activeSpans = [];

    /**
     * Bridge configuration.
     *
     * @var array{enabled: bool, trace_llm: bool, trace_tools: bool, trace_memory: bool, trace_guards: bool, trace_streams: bool, trace_mcp: bool}
     */
    private array $config;

    /**
     * Create a new TelemetryEventBridge.
     *
     * @param  array{enabled?: bool, trace_llm?: bool, trace_tools?: bool, trace_memory?: bool, trace_guards?: bool, trace_streams?: bool, trace_mcp?: bool}  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled' => true,
            'trace_llm' => true,
            'trace_tools' => true,
            'trace_memory' => true,
            'trace_guards' => true,
            'trace_streams' => true,
            'trace_mcp' => true,
        ], $config);
    }

    /**
     * Return the process-wide bridge and bind it to the current global bus.
     *
     * Repeated calls are idempotent. If EventManager has been reset, the same
     * bridge is rebound to the new manager on the next call.
     *
     * @param  array{enabled?: bool, trace_llm?: bool, trace_tools?: bool, trace_memory?: bool, trace_guards?: bool, trace_streams?: bool, trace_mcp?: bool}  $config
     */
    public static function global(array $config = []): self
    {
        if (self::$globalInstance !== null && $config !== []) {
            throw new ConfigurationException(
                'TelemetryEventBridge::global() was already initialized; call TelemetryEventBridge::resetGlobal() before passing a new config.'
            );
        }

        $instance = self::$globalInstance ??= new self($config);

        self::registerGlobalInstance($instance);

        return $instance;
    }

    /**
     * Unregister and discard the global bridge.
     *
     * Useful for test isolation and worker shutdown/reconfiguration.
     */
    public static function resetGlobal(): void
    {
        self::$globalSubscription?->unsubscribe();
        self::$globalInstance = null;
        self::$globalSubscription = null;
        self::$globalManagerGeneration = null;
    }

    /**
     * Get list of event names this bridge listens to.
     *
     * @return array<int, string>
     */
    public function listensTo(): array
    {
        $events = [];

        if ($this->config['trace_llm']) {
            $events[] = 'before_llm_request';
            $events[] = 'after_llm_response';
        }

        if ($this->config['trace_tools']) {
            $events[] = 'tool_executing';
            $events[] = 'tool_executed';
            $events[] = 'tool_error';
        }

        if ($this->config['trace_guards']) {
            $events[] = 'guard_checking';
            $events[] = 'guard_passed';
            $events[] = 'guard_violated';
            $events[] = 'guard_fallback';
        }

        if ($this->config['trace_memory']) {
            $events[] = 'memory_loading';
            $events[] = 'memory_loaded';
        }

        if ($this->config['trace_streams']) {
            $events[] = 'stream_started';
            $events[] = 'stream_completed';
        }

        if ($this->config['trace_mcp']) {
            $events[] = 'mcp_connection_established';
            $events[] = 'mcp_connection_failed';
            $events[] = 'mcp_tools_discovering';
            $events[] = 'mcp_tools_discovered';
            $events[] = 'mcp_tool_calling';
            $events[] = 'mcp_tool_called';
            $events[] = 'mcp_tool_error';
        }

        return $events;
    }

    /**
     * Handle incoming events and create corresponding telemetry spans.
     */
    public function handle(Event $event): void
    {
        // Skip if bridge or telemetry is disabled
        if (! $this->config['enabled'] || ! TelemetryManager::instance()->isEnabled()) {
            return;
        }

        // Skip if the agent behind this event has telemetry disabled
        if (property_exists($event, 'agent') && $event->agent instanceof Agent && ! $event->agent->telemetryEnabled) {
            return;
        }

        // Route to appropriate handler based on event type
        match (true) {
            // LLM events
            $event instanceof BeforeLLMRequestEvent => $this->handleBeforeLLMRequest($event),
            $event instanceof AfterLLMResponseEvent => $this->handleAfterLLMResponse($event),
            // Tool events
            $event instanceof ToolExecutingEvent => $this->handleToolExecuting($event),
            $event instanceof ToolExecutedEvent => $this->handleToolExecuted($event),
            $event instanceof ToolErrorEvent => $this->handleToolError($event),
            // Guard events
            $event instanceof GuardCheckingEvent => $this->handleGuardChecking($event),
            $event instanceof GuardPassedEvent => $this->handleGuardPassed($event),
            $event instanceof GuardViolatedEvent => $this->handleGuardViolated($event),
            $event instanceof GuardFallbackEvent => $this->handleGuardFallback($event),
            // Memory events
            $event instanceof MemoryLoadingEvent => $this->handleMemoryLoading($event),
            $event instanceof MemoryLoadedEvent => $this->handleMemoryLoaded($event),
            // Stream events
            $event instanceof StreamStartedEvent => $this->handleStreamStarted($event),
            $event instanceof StreamCompletedEvent => $this->handleStreamCompleted($event),
            // MCP events
            $event instanceof McpConnectionEstablishedEvent => $this->handleMcpConnectionEstablished($event),
            $event instanceof McpConnectionFailedEvent => $this->handleMcpConnectionFailed($event),
            $event instanceof McpToolsDiscoveringEvent => $this->handleMcpToolsDiscovering($event),
            $event instanceof McpToolsDiscoveredEvent => $this->handleMcpToolsDiscovered($event),
            $event instanceof McpToolCallingEvent => $this->handleMcpToolCalling($event),
            $event instanceof McpToolCalledEvent => $this->handleMcpToolCalled($event),
            $event instanceof McpToolErrorEvent => $this->handleMcpToolError($event),
            default => null,
        };
    }

    /**
     * Handle BeforeLLMRequestEvent - start LLM span.
     */
    private function handleBeforeLLMRequest(BeforeLLMRequestEvent $event): void
    {
        if (! $this->config['trace_llm']) {
            return;
        }

        $span = TelemetryManager::instance()->startLLMSpan(
            $event->provider,
            $event->model,
            [
                'gen_ai.request.temperature' => $event->requestPayload['temperature'] ?? null,
                'gen_ai.request.max_tokens' => $event->requestPayload['max_tokens'] ?? null,
            ]
        );

        $this->storeSpan('llm', $this->agentKey($event->agent), $span);
    }

    /**
     * Handle AfterLLMResponseEvent - end LLM span with response data.
     */
    private function handleAfterLLMResponse(AfterLLMResponseEvent $event): void
    {
        $spanData = $this->retrieveSpan('llm', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        // Add response attributes if available
        if (isset($event->responseData['model'])) {
            $span->setAttribute('gen_ai.response.model', $event->responseData['model']);
        }

        // Add usage/token information
        if (isset($event->responseData['usage'])) {
            $usage = $event->responseData['usage'];

            if (isset($usage['input_tokens'])) {
                $span->setAttribute('gen_ai.usage.input_tokens', $usage['input_tokens']);
            }

            if (isset($usage['output_tokens'])) {
                $span->setAttribute('gen_ai.usage.output_tokens', $usage['output_tokens']);
            }

            if (isset($usage['total_tokens'])) {
                $span->setAttribute('gen_ai.usage.total_tokens', $usage['total_tokens']);
            }

            // Anthropic-specific: cache tokens
            if (isset($usage['cache_read_input_tokens'])) {
                $span->setAttribute('gen_ai.usage.cache_read_input_tokens', $usage['cache_read_input_tokens']);
            }

            if (isset($usage['cache_creation_input_tokens'])) {
                $span->setAttribute('gen_ai.usage.cache_creation_input_tokens', $usage['cache_creation_input_tokens']);
            }
        }

        // Add duration if available
        if ($event->durationMs > 0) {
            $span->setAttribute('llm.duration_ms', $event->durationMs);
        }

        // Set span status and end
        if ($span instanceof Span) {
            $span->setStatus('ok');
        }

        $span->end();
    }

    /**
     * Handle ToolExecutingEvent - start Tool span.
     */
    private function handleToolExecuting(ToolExecutingEvent $event): void
    {
        if (! $this->config['trace_tools']) {
            return;
        }

        $span = TelemetryManager::instance()->startToolSpan(
            $event->toolName,
            $event->parameters
        );

        $this->storeSpan('tool', $this->agentKey($event->agent), $span);
    }

    /**
     * Handle ToolExecutedEvent - end Tool span with success.
     */
    private function handleToolExecuted(ToolExecutedEvent $event): void
    {
        $spanData = $this->retrieveSpan('tool', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        // Add duration and success attributes
        $span->setAttribute('tool.duration_ms', $event->durationMs);
        $span->setAttribute('tool.status', 'success');

        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle ToolErrorEvent - end Tool span with error.
     */
    private function handleToolError(ToolErrorEvent $event): void
    {
        $spanData = $this->retrieveSpan('tool', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        // Add error attributes
        $span->setAttribute('tool.status', 'error');
        $span->setAttribute('tool.error.type', get_class($event->error));
        $span->setAttribute('tool.error.message', $event->error->getMessage());

        $span->setStatus('error', $event->error->getMessage());
        $span->end();
    }

    /**
     * Handle GuardCheckingEvent - start Guard span.
     */
    private function handleGuardChecking(GuardCheckingEvent $event): void
    {
        if (! $this->config['trace_guards']) {
            return;
        }

        $span = TelemetryManager::instance()->startGuardSpan(
            $event->guardName,
            [
                'guard.content_length' => strlen($event->content),
            ]
        );

        $this->storeSpan('guard', $this->agentKey($event->agent), $span);
    }

    /**
     * Handle GuardPassedEvent - end Guard span with success.
     */
    private function handleGuardPassed(GuardPassedEvent $event): void
    {
        $spanData = $this->retrieveSpan('guard', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        $span->setAttribute('guard.result', 'passed');
        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle GuardViolatedEvent - end Guard span with violation.
     */
    private function handleGuardViolated(GuardViolatedEvent $event): void
    {
        $spanData = $this->retrieveSpan('guard', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        $span->setAttribute('guard.result', 'violated');
        $span->setAttribute('guard.violation_reason', $event->reason);
        $span->setStatus('ok'); // Guard working as intended, not an error
        $span->end();
    }

    /**
     * Handle GuardFallbackEvent - end Guard span with fallback.
     */
    private function handleGuardFallback(GuardFallbackEvent $event): void
    {
        $spanData = $this->retrieveSpan('guard', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        $span->setAttribute('guard.result', 'fallback');
        $span->setAttribute('guard.attempt_number', $event->attemptNumber);
        $span->setAttribute('guard.max_attempts', $event->maxAttempts);
        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle MemoryLoadingEvent - start Memory span.
     */
    private function handleMemoryLoading(MemoryLoadingEvent $event): void
    {
        if (! $this->config['trace_memory']) {
            return;
        }

        $span = TelemetryManager::instance()->startMemorySpan(
            'load',
            $event->key,
            $event->namespace
        );

        $this->storeSpan('memory', $this->agentKey($event->agent), $span);
    }

    /**
     * Handle MemoryLoadedEvent - end Memory span with loaded data.
     */
    private function handleMemoryLoaded(MemoryLoadedEvent $event): void
    {
        $spanData = $this->retrieveSpan('memory', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        // Add metadata about loaded value
        $span->setAttribute('memory.loaded', true);
        $span->setAttribute('memory.value_type', gettype($event->value));

        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle StreamStartedEvent - start Stream span.
     */
    private function handleStreamStarted(StreamStartedEvent $event): void
    {
        if (! $this->config['trace_streams']) {
            return;
        }

        $span = TelemetryManager::instance()->startStreamSpan(
            $event->provider,
            $event->model
        );

        $this->storeSpan('stream', $this->agentKey($event->agent), $span);
    }

    /**
     * Handle StreamCompletedEvent - end Stream span with completion data.
     */
    private function handleStreamCompleted(StreamCompletedEvent $event): void
    {
        $spanData = $this->retrieveSpan('stream', $this->agentKey($event->agent));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        // Add stream metadata
        $span->setAttribute('stream.total_chunks', $event->totalChunks);
        $span->setAttribute('stream.duration_ms', $event->durationMs);
        $span->setAttribute('stream.content_length', strlen($event->fullContent));

        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle McpConnectionEstablishedEvent - record MCP connection.
     */
    private function handleMcpConnectionEstablished(McpConnectionEstablishedEvent $event): void
    {
        if (! $this->config['trace_mcp']) {
            return;
        }

        // Create a completed span for the connection
        $span = TelemetryManager::instance()->startSpan(
            'mcp.connection',
            [
                'mcp.client.name' => $event->clientName,
                'mcp.client.version' => $event->clientVersion,
                'mcp.server.name' => $event->serverInfo['name'] ?? 'unknown',
                'mcp.server.version' => $event->serverInfo['version'] ?? 'unknown',
                'mcp.duration_ms' => $event->durationMs,
            ]
        );

        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle McpConnectionFailedEvent - record MCP connection failure.
     */
    private function handleMcpConnectionFailed(McpConnectionFailedEvent $event): void
    {
        if (! $this->config['trace_mcp']) {
            return;
        }

        $span = TelemetryManager::instance()->startSpan(
            'mcp.connection',
            [
                'mcp.client.name' => $event->clientName,
                'mcp.client.version' => $event->clientVersion,
                'mcp.error.type' => get_class($event->error),
                'mcp.error.message' => $event->error->getMessage(),
            ]
        );

        $span->setStatus('error', $event->error->getMessage());
        $span->end();
    }

    /**
     * Handle McpToolsDiscoveringEvent - start MCP tools discovery span.
     */
    private function handleMcpToolsDiscovering(McpToolsDiscoveringEvent $event): void
    {
        if (! $this->config['trace_mcp']) {
            return;
        }

        $span = TelemetryManager::instance()->startSpan(
            'mcp.tools.discover',
            []
        );

        // Store span with a unique key (use object hash)
        $this->storeSpan('mcp_discover', (string) spl_object_id($event->client), $span);
    }

    /**
     * Handle McpToolsDiscoveredEvent - end MCP tools discovery span.
     */
    private function handleMcpToolsDiscovered(McpToolsDiscoveredEvent $event): void
    {
        $spanData = $this->retrieveSpan('mcp_discover', (string) spl_object_id($event->client));

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        $span->setAttribute('mcp.tools.count', $event->toolCount);
        $span->setAttribute('mcp.duration_ms', $event->durationMs);

        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle McpToolCallingEvent - start MCP tool call span.
     */
    private function handleMcpToolCalling(McpToolCallingEvent $event): void
    {
        if (! $this->config['trace_mcp']) {
            return;
        }

        $span = TelemetryManager::instance()->startSpan(
            'mcp.tool.call',
            [
                'mcp.tool.name' => $event->toolName,
            ]
        );

        // Store span with client ID and tool name
        $this->storeSpan('mcp_tool', (string) spl_object_id($event->client).':'.$event->toolName, $span);
    }

    /**
     * Handle McpToolCalledEvent - end MCP tool call span with success.
     */
    private function handleMcpToolCalled(McpToolCalledEvent $event): void
    {
        $spanData = $this->retrieveSpan('mcp_tool', (string) spl_object_id($event->client).':'.$event->toolName);

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        $span->setAttribute('mcp.tool.duration_ms', $event->durationMs);
        $span->setAttribute('mcp.tool.status', 'success');

        $span->setStatus('ok');
        $span->end();
    }

    /**
     * Handle McpToolErrorEvent - end MCP tool call span with error.
     */
    private function handleMcpToolError(McpToolErrorEvent $event): void
    {
        $spanData = $this->retrieveSpan('mcp_tool', (string) spl_object_id($event->client).':'.$event->toolName);

        if ($spanData === null) {
            return;
        }

        $span = $spanData['span'];

        $span->setAttribute('mcp.tool.status', 'error');
        $span->setAttribute('mcp.tool.error.type', get_class($event->error));
        $span->setAttribute('mcp.tool.error.message', $event->error->getMessage());

        $span->setStatus('error', $event->error->getMessage());
        $span->end();
    }

    /**
     * Build a span key segment that is unique per live agent instance.
     *
     * Includes spl_object_id so same-named agents do not share spans.
     */
    private function agentKey(Agent $agent): string
    {
        return $agent->getName().'#'.spl_object_id($agent);
    }

    /**
     * Store an active span for later retrieval.
     *
     * If a span is already active under the same key (e.g. a start event that
     * never saw its matching end), it is ended and evicted first so it cannot
     * leak or be silently clobbered.
     *
     * @param  string  $type  Operation type (e.g., 'llm', 'tool', 'memory')
     * @param  string  $ownerKey  Owner identity (agent key or client id)
     * @param  Span|NullSpan  $span  The span to store
     */
    private function storeSpan(string $type, string $ownerKey, Span|NullSpan $span): void
    {
        $key = $this->makeSpanKey($type, $ownerKey);

        if (isset($this->activeSpans[$key])) {
            $this->activeSpans[$key]['span']->end();
            unset($this->activeSpans[$key]);
        }

        $this->activeSpans[$key] = [
            'span' => $span,
            'started_at' => microtime(true),
        ];
    }

    /**
     * Retrieve and remove an active span.
     *
     * @param  string  $type  Operation type
     * @param  string  $ownerKey  Owner identity (agent key or client id)
     * @return array{span: Span|NullSpan, started_at: float}|null
     */
    private function retrieveSpan(string $type, string $ownerKey): ?array
    {
        $key = $this->makeSpanKey($type, $ownerKey);

        if (! isset($this->activeSpans[$key])) {
            return null;
        }

        $spanData = $this->activeSpans[$key];
        unset($this->activeSpans[$key]);

        return $spanData;
    }

    /**
     * Create the storage key for a span: "{type}:{ownerKey}".
     *
     * The owner key embeds spl_object_id of the agent (or MCP client), so
     * distinct instances with the same name cannot collide.
     *
     * @param  string  $type  Operation type
     * @param  string  $ownerKey  Owner identity (agent key or client id)
     */
    private function makeSpanKey(string $type, string $ownerKey): string
    {
        return "{$type}:{$ownerKey}";
    }

    /**
     * Get count of active spans (useful for debugging/testing).
     */
    public function getActiveSpanCount(): int
    {
        return count($this->activeSpans);
    }

    /**
     * Clear all active spans (useful for testing).
     */
    public function clearActiveSpans(): void
    {
        $this->activeSpans = [];
    }

    /**
     * Bind the singleton to the current EventManager exactly once.
     */
    private static function registerGlobalInstance(self $instance): void
    {
        $generation = EventManager::generation();

        if (
            self::$globalSubscription?->isActive()
            && self::$globalManagerGeneration === $generation
        ) {
            return;
        }

        self::$globalSubscription?->unsubscribe();
        self::$globalSubscription = EventManager::instance()->subscribe($instance);
        self::$globalManagerGeneration = $generation;
    }
}
