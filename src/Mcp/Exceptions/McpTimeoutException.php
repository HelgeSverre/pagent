<?php

declare(strict_types=1);

namespace Pagent\Mcp\Exceptions;

/**
 * Exception thrown when MCP request times out.
 */
class McpTimeoutException extends McpException
{
    public static function requestTimedOut(int $timeoutMs): self
    {
        return new self("MCP request timed out after {$timeoutMs}ms");
    }
}
