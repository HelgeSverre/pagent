<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired before MCP client disconnects.
 *
 * Allows listeners to:
 * - Log disconnection events
 * - Clean up resources
 * - Finalize operations
 */
final class McpDisconnectingEvent extends Event
{
    public function __construct(
        public readonly McpClient $client,
        public readonly string $clientName,
        public readonly string $clientVersion,
    ) {
        parent::__construct();
    }
}
