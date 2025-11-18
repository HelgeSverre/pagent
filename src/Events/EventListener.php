<?php

declare(strict_types=1);

namespace Pagent\Events;

/**
 * Interface for class-based event listeners.
 *
 * Event listeners can handle multiple event types by implementing
 * this interface and specifying which events they listen to.
 *
 * @example
 * ```php
 * class MyListener implements EventListener
 * {
 *     public function listensTo(): array
 *     {
 *         return ['before_prompt', 'after_prompt'];
 *     }
 *
 *     public function handle(Event $event): void
 *     {
 *         if ($event instanceof BeforePromptEvent) {
 *             // Handle before prompt
 *         } elseif ($event instanceof AfterPromptEvent) {
 *             // Handle after prompt
 *         }
 *     }
 * }
 * ```
 */
interface EventListener
{
    /**
     * Handle the event.
     *
     * @param  Event  $event  The event to handle
     */
    public function handle(Event $event): void;

    /**
     * Get the list of event names this listener handles.
     *
     * Return an array of event names (e.g., ['before_prompt', 'after_prompt'])
     *
     * @return array<string> Event names this listener handles
     */
    public function listensTo(): array;
}
