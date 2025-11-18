<?php

declare(strict_types=1);

namespace Pagent\Mcp\Exceptions;

/**
 * Exception thrown when MCP connection fails or is lost.
 */
class McpConnectionException extends McpException
{
    public static function connectionFailed(string $reason): self
    {
        return new self("Failed to connect to MCP server: {$reason}");
    }

    public static function connectionLost(string $reason): self
    {
        return new self("Connection to MCP server lost: {$reason}");
    }

    public static function notConnected(): self
    {
        return new self('Not connected to MCP server');
    }
}
