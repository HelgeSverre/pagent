<?php

declare(strict_types=1);

namespace Pagent\Mcp\Exceptions;

/**
 * Exception thrown when MCP protocol errors occur.
 */
class McpProtocolException extends McpException
{
    public static function invalidResponse(string $reason): self
    {
        return new self("Invalid MCP response: {$reason}");
    }

    public static function serverError(string $message, int $code): self
    {
        return new self("MCP server error [{$code}]: {$message}");
    }

    public static function methodNotFound(string $method): self
    {
        return new self("MCP method not found: {$method}");
    }
}
