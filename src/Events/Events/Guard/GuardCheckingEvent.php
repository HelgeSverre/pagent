<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Guard;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired before a guard is checked.
 *
 * Allows listeners to:
 * - Log guard execution
 * - Track guard coverage
 * - Monitor guard performance
 * - Bypass guards conditionally
 */
final class GuardCheckingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly string $content,
    ) {
        parent::__construct();
    }
}
