<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;
use Throwable;

/**
 * Fired when an MCP tool call fails.
 *
 * Allows listeners to:
 * - Log tool errors
 * - Track failure rates
 * - Implement retry logic
 * - Alert on issues
 */
final class McpToolErrorEvent extends Event
{
    /**
     * @param  McpClient  $client
     * @param  string  $toolName
     * @param  array<string, mixed>  $arguments
     * @param  Throwable  $error
     */
    public function __construct(
        public readonly McpClient $client,
        public readonly string $toolName,
        public readonly array $arguments,
        public readonly Throwable $error,
    ) {
        parent::__construct();
    }
}
