<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired after MCP connection is successfully established.
 *
 * Allows listeners to:
 * - Log successful connections
 * - Record connection metadata
 * - Track connection performance
 * - Initialize server-specific features
 */
final class McpConnectionEstablishedEvent extends Event
{
    /**
     * @param  array<string, mixed>  $serverCapabilities
     * @param  array<string, mixed>  $serverInfo
     */
    public function __construct(
        public readonly McpClient $client,
        public readonly string $clientName,
        public readonly string $clientVersion,
        public readonly array $serverCapabilities,
        public readonly array $serverInfo,
        public readonly float $durationMs,
    ) {
        parent::__construct();
    }
}
