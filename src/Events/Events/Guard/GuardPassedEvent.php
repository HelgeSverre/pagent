<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Guard;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired when a guard check passes.
 *
 * Allows listeners to:
 * - Log successful guards
 * - Track pass rates
 * - Monitor content quality
 * - Trigger downstream workflows
 */
final class GuardPassedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $guardName,
        public readonly string $content,
    ) {
        parent::__construct();
    }
}
