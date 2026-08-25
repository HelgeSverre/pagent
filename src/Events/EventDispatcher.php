<?php

declare(strict_types=1);

namespace Pagent\Events;

use Closure;

/**
 * Event dispatcher with priority-based listener execution.
 *
 * Supports both closure-based and class-based event listeners.
 * Listeners are executed in priority order (highest first).
 */
final class EventDispatcher
{
    /**
     * Registered listeners by event name.
     *
     * @var array<string, array<int, array{listener: EventListener, priority: int, id: string, delivery_id: string}>>
     */
    private array $listeners = [];

    /**
     * Flags indicating whether listeners for each event are sorted.
     *
     * @var array<string, bool>
     */
    private array $sorted = [];

    /**
     * Register an event listener.
     *
     * Accepts either a Closure or EventListener instance.
     * Closures are automatically wrapped in an anonymous EventListener.
     *
     * @param  string  $eventName  Event name (e.g., 'before_prompt')
     * @param  (Closure(Event): void)|EventListener  $listener  The listener to register
     * @param  int  $priority  Priority (higher = executed first, default 0)
     * @return string Unique listener ID for later removal
     */
    public function on(string $eventName, Closure|EventListener $listener, int $priority = 0): string
    {
        $id = $this->listenerId($listener);

        if ($listener instanceof Closure) {
            $listener = $this->wrapClosure($eventName, $listener);
        }

        if (! isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }

        $this->listeners[$eventName][] = [
            'listener' => $listener,
            'priority' => $priority,
            'id' => $id,
            'delivery_id' => $id,
        ];

        $this->sorted[$eventName] = false;

        return $id;
    }

    /**
     * Register a one-time event listener.
     *
     * The listener will be automatically removed after it executes once.
     *
     * @param  string  $eventName  Event name
     * @param  (Closure(Event): void)|EventListener  $listener  The listener
     * @param  int  $priority  Priority (higher = executed first)
     * @return string Unique listener ID
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
                $this->off($eventName, $this->listenerId($wrappedListener));
            }
        };

        $wrappedListener = $this->wrapClosure($eventName, $oneTimeListener);

        return $this->on($eventName, $wrappedListener, $priority);
    }

    /**
     * Remove a listener by its ID.
     *
     * @param  string  $eventName  Event name
     * @param  string  $listenerId  Listener ID (returned from on() or once())
     */
    public function off(string $eventName, string $listenerId): void
    {
        if (! isset($this->listeners[$eventName])) {
            return;
        }

        $this->listeners[$eventName] = array_filter(
            $this->listeners[$eventName],
            fn (array $item): bool => $item['id'] !== $listenerId
        );

        $this->sorted[$eventName] = false;
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * Listeners are executed in priority order (highest first).
     * If a listener calls $event->stopPropagation(), remaining listeners won't execute.
     *
     * @param  Event  $event  The event to dispatch
     */
    public function dispatch(Event $event): void
    {
        $eventName = $event->getEventName();

        if (! isset($this->listeners[$eventName])) {
            return;
        }

        // Sort by priority (descending) if not already sorted
        if (! ($this->sorted[$eventName] ?? false)) {
            usort(
                $this->listeners[$eventName],
                fn (array $a, array $b): int => $b['priority'] <=> $a['priority']
            );
            $this->sorted[$eventName] = true;
        }

        foreach ($this->listeners[$eventName] as $item) {
            if ($event->isPropagationStopped()) {
                break;
            }

            if ($event->wasDeliveredTo($item['delivery_id'])) {
                continue;
            }

            $event->markDeliveredTo($item['delivery_id']);
            $item['listener']->handle($event);
        }
    }

    /**
     * Register a class-based listener for multiple events.
     *
     * The listener's listensTo() method determines which events it handles.
     *
     * @param  EventListener  $listener  The listener instance
     * @param  int  $priority  Priority for all events (default 0)
     */
    public function listen(EventListener $listener, int $priority = 0): void
    {
        $this->subscribe($listener, $priority);
    }

    /**
     * Register a class-based listener and return a cancellable subscription.
     */
    public function subscribe(EventListener $listener, int $priority = 0): EventSubscription
    {
        $registrations = [];

        foreach ($listener->listensTo() as $eventName) {
            $registrations[] = [$eventName, $this->on($eventName, $listener, $priority)];
        }

        return new EventSubscription(function () use ($registrations): void {
            foreach ($registrations as [$eventName, $listenerId]) {
                $this->off($eventName, $listenerId);
            }
        });
    }

    /**
     * Wrap a closure in an anonymous EventListener.
     *
     * @param  string  $eventName  Event name this closure handles
     * @param  Closure(Event): void  $closure  The closure to wrap
     * @return EventListener Anonymous EventListener instance
     */
    private function wrapClosure(string $eventName, Closure $closure): EventListener
    {
        return new class($eventName, $closure) implements EventListener
        {
            private readonly string $eventName;

            /** @var Closure(Event): void */
            private readonly Closure $closure;

            /**
             * @param  Closure(Event): void  $closure
             */
            public function __construct(string $eventName, Closure $closure)
            {
                $this->eventName = $eventName;
                $this->closure = $closure;
            }

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

    /**
     * Stable identity used for unsubscription and cross-scope de-duplication.
     *
     * @param  (Closure(Event): void)|EventListener  $listener
     */
    private function listenerId(Closure|EventListener $listener): string
    {
        return ($listener instanceof Closure ? 'closure:' : 'listener:').spl_object_hash($listener);
    }
}
