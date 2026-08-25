<?php

declare(strict_types=1);

namespace Pagent\Events;

/**
 * Base event class for all Pagent events.
 *
 * Provides propagation control and automatic event naming.
 */
abstract class Event
{
    /**
     * Whether event propagation has been stopped.
     */
    private bool $propagationStopped = false;

    /**
     * Listener identities that have handled this event.
     *
     * An event may be published to both the application-wide and an
     * agent-scoped dispatcher. Keeping delivery state on the event makes that
     * one logical publication, so registering the same listener in both
     * scopes cannot produce duplicate side effects.
     *
     * @var array<string, true>
     */
    private array $deliveredTo = [];

    /**
     * Event timestamp (microtime).
     */
    public readonly float $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    /**
     * Stop event propagation to remaining listeners.
     *
     * Once stopped, no other listeners will receive this event.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /**
     * Check if event propagation has been stopped.
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Determine whether a listener has already handled this event.
     *
     * @internal Used by event dispatchers to provide once-per-publication
     * delivery across event scopes.
     */
    public function wasDeliveredTo(string $listenerId): bool
    {
        return isset($this->deliveredTo[$listenerId]);
    }

    /**
     * Mark a listener as having handled this event.
     *
     * @internal Used by event dispatchers to provide once-per-publication
     * delivery across event scopes.
     */
    public function markDeliveredTo(string $listenerId): void
    {
        $this->deliveredTo[$listenerId] = true;
    }

    /**
     * Get the event name for dispatcher registration.
     *
     * Automatically converts class name to snake_case.
     * Handles acronyms properly: BeforeLLMRequest -> before_llm_request
     * Examples:
     *   - BeforePromptEvent -> before_prompt
     *   - AfterLLMResponseEvent -> after_llm_response
     *   - APICallEvent -> api_call
     */
    public function getEventName(): string
    {
        // Get class name without namespace
        $parts = explode('\\', static::class);
        $class = end($parts);

        // Remove 'Event' suffix
        $name = str_replace('Event', '', $class);

        // Convert to snake_case, handling acronyms properly
        // Insert underscore before uppercase followed by lowercase (for acronyms: "LLMRequest" -> "LLM_Request")
        // Insert underscore between lowercase and uppercase (normal case: "beforePrompt" -> "before_Prompt")
        $snakeCase = (string) preg_replace([
            '/(?<=[a-z])(?=[A-Z])/',     // lowercase followed by uppercase
            '/(?<=[A-Z])(?=[A-Z][a-z])/', // acronym followed by word
        ], '_', $name);

        return strtolower($snakeCase);
    }
}
