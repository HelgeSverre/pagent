<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Tool;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired before a tool is executed.
 *
 * Allows listeners to:
 * - Log tool execution attempts
 * - Validate tool parameters
 * - Cancel execution (via stopPropagation)
 * - Inject additional context
 */
final class ToolExecutingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $toolName,
        public readonly array $parameters,
    ) {
        parent::__construct();
    }
}
