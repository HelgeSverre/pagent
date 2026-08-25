<?php

declare(strict_types=1);

namespace Pagent\Events;

use Closure;

/**
 * A cancellable event-listener registration.
 *
 * Subscriptions let long-lived applications and tests explicitly release
 * global listeners instead of relying on singleton resets or object lifetime.
 */
final class EventSubscription
{
    private bool $active = true;

    /**
     * @param  Closure(): void  $unsubscribe
     */
    public function __construct(private readonly Closure $unsubscribe) {}

    /**
     * Remove the registered listener(s). This operation is idempotent.
     */
    public function unsubscribe(): void
    {
        if (! $this->active) {
            return;
        }

        ($this->unsubscribe)();
        $this->active = false;
    }

    /**
     * Whether this subscription is still active.
     */
    public function isActive(): bool
    {
        return $this->active;
    }
}
