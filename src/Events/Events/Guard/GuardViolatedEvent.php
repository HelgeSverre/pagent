<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Guard;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired when a guard check fails.
 *
 * Allows listeners to:
 * - Log guard violations
 * - Track failure patterns
 * - Alert on repeated violations
 * - Analyze problematic content
 */
final class GuardViolatedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly string $content,
        public readonly string $reason,
    ) {
        parent::__construct();
    }
}
