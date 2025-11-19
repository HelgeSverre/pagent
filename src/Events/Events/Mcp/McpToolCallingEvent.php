<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired before calling an MCP tool.
 *
 * Allows listeners to:
 * - Log tool calls
 * - Validate parameters
 * - Cancel execution (via stopPropagation)
 * - Track tool usage
 */
final class McpToolCallingEvent extends Event
{
    /**
     * @param  McpClient  $client
     * @param  string  $toolName
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly McpClient $client,
        public readonly string $toolName,
        public readonly array $arguments,
    ) {
        parent::__construct();
    }
}
