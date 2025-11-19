<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired before MCP client initiates connection.
 *
 * Allows listeners to:
 * - Log connection attempts
 * - Prepare connection context
 * - Cancel connection (via stopPropagation)
 */
final class McpConnectionInitiatingEvent extends Event
{
    public function __construct(
        public readonly McpClient $client,
        public readonly string $clientName,
        public readonly string $clientVersion,
    ) {
        parent::__construct();
    }
}
