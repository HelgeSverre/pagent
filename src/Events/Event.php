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
