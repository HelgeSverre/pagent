<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired after tools are discovered from MCP server.
 *
 * Allows listeners to:
 * - Log discovered tools
 * - Validate tool definitions
 * - Track tool availability
 * - Update tool registries
 */
final class McpToolsDiscoveredEvent extends Event
{
    /**
     * @param  McpClient  $client
     * @param  array<int, array<string, mixed>>  $tools
     * @param  int  $toolCount
     * @param  float  $durationMs
     */
    public function __construct(
        public readonly McpClient $client,
        public readonly array $tools,
        public readonly int $toolCount,
        public readonly float $durationMs,
    ) {
        parent::__construct();
    }
}
