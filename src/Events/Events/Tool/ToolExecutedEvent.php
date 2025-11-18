<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Tool;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired after a tool executes successfully.
 *
 * Allows listeners to:
 * - Log successful executions
 * - Track tool usage metrics
 * - Process tool results
 * - Trigger follow-up actions
 */
final class ToolExecutedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $toolName,
        public readonly array $parameters,
        public readonly mixed $result,
        public readonly float $durationMs,
    ) {
        parent::__construct();
    }
}
