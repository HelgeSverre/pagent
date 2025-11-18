<?php

declare(strict_types=1);

/**
 * Integration tests for MCP HTTP/SSE transport with context7.
 *
 * These tests require an HTTP/SSE transport implementation and context7 MCP server.
 * Currently marked as TODO pending HTTP transport implementation.
 */
test('can connect to context7 MCP server via HTTP', function () {
    // Skip if context7 is not available
    $context7Url = getenv('CONTEXT7_MCP_URL') ?: 'http://localhost:3000';

    $transport = new \Pagent\Mcp\Transports\HttpSseTransport(
        baseUrl: $context7Url,
        headers: [],
        timeoutMs: 5000
    );

    try {
        $client = new \Pagent\Mcp\McpClient($transport);
        $client->connect();

        expect($client->isConnected())->toBeTrue();

        $client->disconnect();
    } catch (\Pagent\Mcp\Exceptions\McpConnectionException $e) {
        $this->markTestSkipped("context7 MCP server not available at {$context7Url}: ".$e->getMessage());
    }
})->group('integration', 'mcp', 'http');

test('can discover context7 tools via HTTP', function () {
    $context7Url = getenv('CONTEXT7_MCP_URL') ?: 'http://localhost:3000';

    $transport = new \Pagent\Mcp\Transports\HttpSseTransport(
        baseUrl: $context7Url,
        timeoutMs: 5000
    );

    try {
        $client = new \Pagent\Mcp\McpClient($transport);
        $client->connect();

        $tools = $client->discoverTools();

        expect($tools)->toBeArray()
            ->and($tools)->not->toBeEmpty();

        // Expect context7 specific tools
        $toolNames = array_column($tools, 'name');
        expect($toolNames)->toContain('resolve-library-id')
            ->or($toolNames)->toContain('get-library-docs');

        $client->disconnect();
    } catch (\Pagent\Mcp\Exceptions\McpConnectionException $e) {
        $this->markTestSkipped("context7 MCP server not available at {$context7Url}: ".$e->getMessage());
    }
})->group('integration', 'mcp', 'http');

test('can call context7 get-library-docs tool', function () {
    $context7Url = getenv('CONTEXT7_MCP_URL') ?: 'http://localhost:3000';

    $transport = new \Pagent\Mcp\Transports\HttpSseTransport(
        baseUrl: $context7Url,
        timeoutMs: 10000
    );

    try {
        $client = new \Pagent\Mcp\McpClient($transport);
        $client->connect();

        $result = $client->callTool('get-library-docs', [
            'libraryId' => 'react@18.2.0',
            'query' => 'useState hook',
        ]);

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('content');

        $client->disconnect();
    } catch (\Pagent\Mcp\Exceptions\McpConnectionException $e) {
        $this->markTestSkipped("context7 MCP server not available at {$context7Url}: ".$e->getMessage());
    } catch (\Pagent\Mcp\Exceptions\McpProtocolException $e) {
        // Tool might not exist or different schema
        $this->markTestSkipped('context7 tool call failed: '.$e->getMessage());
    }
})->group('integration', 'mcp', 'http');

/*
 * Future HTTP/SSE Transport Implementation Notes:
 *
 * The HTTP/SSE transport for MCP requires:
 * 1. HTTP client for sending requests (POST to /messages endpoint)
 * 2. Server-Sent Events (SSE) for receiving responses
 * 3. Session management with session IDs
 * 4. Proper JSON-RPC 2.0 message framing over HTTP
 *
 * MCP HTTP/SSE Protocol:
 * - Client POSTs JSON-RPC messages to server endpoint
 * - Server responds via SSE stream
 * - Each message has a session ID for multiplexing
 *
 * Implementation steps for v0.8.0:
 * 1. Create HttpTransport implementing McpTransport
 * 2. Add Guzzle or native curl support for HTTP requests
 * 3. Implement SSE parsing for response handling
 * 4. Add session management
 * 5. Update these tests with real implementations
 *
 * See: https://spec.modelcontextprotocol.io/specification/2024-11-05/basic/transports/#http-with-sse
 */
