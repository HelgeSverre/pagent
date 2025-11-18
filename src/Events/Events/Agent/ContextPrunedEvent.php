<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Agent;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired when agent context is pruned due to token limits.
 *
 * Allows listeners to:
 * - Log pruning events
 * - Save pruned context to memory
 * - Alert on frequent pruning
 * - Adjust context window size
 */
final class ContextPrunedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly int $messagesBefore,
        public readonly int $messagesAfter,
        public readonly int $tokensSaved,
    ) {
        parent::__construct();
    }
}
