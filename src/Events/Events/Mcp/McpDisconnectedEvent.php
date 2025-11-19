<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired after MCP client disconnects.
 *
 * Allows listeners to:
 * - Log disconnection completion
 * - Track session duration
 * - Clean up remaining resources
 */
final class McpDisconnectedEvent extends Event
{
    public function __construct(
        public readonly McpClient $client,
        public readonly string $clientName,
        public readonly string $clientVersion,
    ) {
        parent::__construct();
    }
}
