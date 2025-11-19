<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;
use Throwable;

/**
 * Fired when MCP connection fails.
 *
 * Allows listeners to:
 * - Log connection failures
 * - Alert on connection issues
 * - Implement retry logic
 * - Track reliability metrics
 */
final class McpConnectionFailedEvent extends Event
{
    public function __construct(
        public readonly McpClient $client,
        public readonly string $clientName,
        public readonly string $clientVersion,
        public readonly Throwable $error,
    ) {
        parent::__construct();
    }
}
