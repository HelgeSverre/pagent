<?php

declare(strict_types=1);

namespace Pagent\Events;

use Closure;

/**
 * Global event manager singleton.
 *
 * Provides a global event dispatcher for cross-agent event listening.
 * Use this for events that should be heard by all agents,
 * or for application-level event handling.
 *
 * @example
 * ```php
 * // Listen to all agent prompts globally
 * EventManager::instance()->on('after_prompt', function($event) {
 *     Log::info('Agent completed', ['agent' => $event->agent->getName()]);
 * });
 * ```
 */
final class EventManager
{
    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Event dispatcher instance.
     */
    private EventDispatcher $dispatcher;

    /**
     * Private constructor (singleton pattern).
     */
    private function __construct()
    {
        $this->dispatcher = new EventDispatcher;
    }

    /**
     * Get the singleton instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
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
        return $this->dispatcher->on($eventName, $listener, $priority);
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
        return $this->dispatcher->once($eventName, $listener, $priority);
    }

    /**
     * Remove a listener by ID.
     *
     * @param  string  $eventName  Event name
     * @param  string  $listenerId  Listener ID
     */
    public function off(string $eventName, string $listenerId): void
    {
        $this->dispatcher->off($eventName, $listenerId);
    }

    /**
     * Dispatch an event.
     *
     * @param  Event  $event  The event to dispatch
     */
    public function dispatch(Event $event): void
    {
        $this->dispatcher->dispatch($event);
    }

    /**
     * Register a class-based listener for multiple events.
     *
     * @param  EventListener  $listener  The listener instance
     * @param  int  $priority  Priority for all events
     */
    public function listen(EventListener $listener, int $priority = 0): void
    {
        $this->dispatcher->listen($listener, $priority);
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
