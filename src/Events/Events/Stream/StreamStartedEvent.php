<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Stream;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired when a streaming response starts.
 *
 * Allows listeners to:
 * - Initialize stream processors
 * - Set up real-time UI updates
 * - Start latency timers
 * - Log stream initiation
 */
final class StreamStartedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $provider,
        public readonly string $model,
    ) {
        parent::__construct();
    }
}
