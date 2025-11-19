<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired before discovering tools from MCP server.
 *
 * Allows listeners to:
 * - Log discovery attempts
 * - Prepare for tool registration
 * - Track discovery operations
 */
final class McpToolsDiscoveringEvent extends Event
{
    public function __construct(
        public readonly McpClient $client,
    ) {
        parent::__construct();
    }
}
