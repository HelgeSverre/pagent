<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Guard;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired when a guard triggers fallback behavior.
 *
 * Allows listeners to:
 * - Log fallback executions
 * - Track fallback success rates
 * - Monitor retry patterns
 * - Alert on excessive fallbacks
 */
final class GuardFallbackEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly int $attemptNumber,
        public readonly int $maxAttempts,
    ) {
        parent::__construct();
    }
}
