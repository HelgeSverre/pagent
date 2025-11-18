<?php

declare(strict_types=1);

namespace Pagent\Mcp;

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\Exceptions\McpTimeoutException;

/**
 * Transport interface for MCP communication.
 *
 * Implementations handle the underlying communication mechanism
 * (stdio, HTTP/SSE, WebSocket, etc.) for JSON-RPC 2.0 messages.
 */
interface McpTransport
{
    /**
     * Connect to the MCP server.
     *
     * @throws McpConnectionException If connection fails
     */
    public function connect(): void;

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void;

    /**
     * Check if transport is connected.
     */
    public function isConnected(): bool;

    /**
     * Send a JSON-RPC 2.0 request and wait for response.
     *
     * @param  array<string, mixed>  $request  JSON-RPC 2.0 request
     * @return array<string, mixed> JSON-RPC 2.0 response
     *
     * @throws McpConnectionException If not connected or connection lost
     * @throws McpProtocolException If response is invalid
     * @throws McpTimeoutException If request times out
     */
    public function sendRequest(array $request): array;

    /**
     * Send a JSON-RPC 2.0 notification (no response expected).
     *
     * @param  array<string, mixed>  $notification  JSON-RPC 2.0 notification
     *
     * @throws McpConnectionException If not connected or connection lost
     */
    public function sendNotification(array $notification): void;
}
