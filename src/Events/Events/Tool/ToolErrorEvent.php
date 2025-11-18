<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Tool;

use Pagent\Agent;
use Pagent\Events\Event;
use Throwable;

/**
 * Fired when a tool execution fails.
 *
 * Allows listeners to:
 * - Log errors and stack traces
 * - Alert on critical failures
 * - Implement retry logic
 * - Provide fallback behavior
 */
final class ToolErrorEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $toolName,
        public readonly array $parameters,
        public readonly Throwable $error,
    ) {
        parent::__construct();
    }
}
