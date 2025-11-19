<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Mcp;

use Pagent\Events\Event;
use Pagent\Mcp\McpClient;

/**
 * Fired after successfully calling an MCP tool.
 *
 * Allows listeners to:
 * - Log tool results
 * - Track tool performance
 * - Process tool outputs
 * - Update metrics
 */
final class McpToolCalledEvent extends Event
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public readonly McpClient $client,
        public readonly string $toolName,
        public readonly array $arguments,
        public readonly array $result,
        public readonly float $durationMs,
    ) {
        parent::__construct();
    }
}
