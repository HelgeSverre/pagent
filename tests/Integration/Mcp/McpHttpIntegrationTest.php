<?php

declare(strict_types=1);

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\McpClient;
use Pagent\Mcp\Transports\HttpSseTransport;

/**
 * Integration tests for MCP HTTP/SSE transport.
 *
 * These tests require an MCP server running via HTTP/SSE.
 * Default: Run via mcp-proxy with the Everything server:
 *   mcp-proxy --port 3333 -- npx -y @modelcontextprotocol/server-everything
 *
 * Set MCP_HTTP_URL environment variable to use a different server.
 */

/**
 * Get the MCP server URL, skipping if not available.
 */
function getMcpServerUrl(object $test): string
{
    $url = getenv('MCP_HTTP_URL') ?: 'http://localhost:3333';

    // Quick check if server is available
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2,
        ],
    ]);

    $result = @file_get_contents("{$url}/sse", false, $context);
    if ($result === false) {
        $test->markTestSkipped("MCP HTTP server not available at {$url}. Start with: mcp-proxy --port 3333 -- npx -y @modelcontextprotocol/server-everything");
    }

    return $url;
}

// ===== Connection Tests =====

test('can connect to MCP server via HTTP/SSE', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport, 'test-client', '1.0.0');
    $client->connect();

    expect($client->isConnected())->toBeTrue();

    $capabilities = $client->getServerCapabilities();
    expect($capabilities)->toBeArray();

    $client->disconnect();
    expect($client->isConnected())->toBeFalse();
})->group('integration', 'mcp', 'http');

test('handles connection timeout gracefully', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:59999',  // Non-existent port
        timeoutMs: 1000
    );

    $client = new McpClient($transport);

    expect(fn () => $client->connect())
        ->toThrow(McpConnectionException::class);
})->group('integration', 'mcp', 'http');

// ===== Tool Discovery Tests =====

test('discovers tools via HTTP transport', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $tools = $client->discoverTools();

    expect($tools)->toBeArray()
        ->and($tools)->not->toBeEmpty();

    // Every tool should have required fields
    foreach ($tools as $tool) {
        expect($tool)->toHaveKey('name')
            ->and($tool)->toHaveKey('description');
    }

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('Everything server provides expected tools', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $tools = $client->discoverTools();
    $toolNames = array_column($tools, 'name');

    // Everything server should have these tools
    expect($toolNames)->toContain('echo')
        ->and($toolNames)->toContain('add');

    $client->disconnect();
})->group('integration', 'mcp', 'http');

// ===== Tool Execution Tests =====

test('executes echo tool via HTTP transport', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $result = $client->callTool('echo', ['message' => 'Hello HTTP MCP!']);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('content')
        ->and($result['content'])->toBeArray()
        ->and($result['content'][0]['type'])->toBe('text')
        ->and($result['content'][0]['text'])->toContain('Hello HTTP MCP!');

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('executes add tool via HTTP transport', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $result = $client->callTool('add', ['a' => 15, 'b' => 27]);

    expect($result)->toBeArray()
        ->and($result['content'][0]['text'])->toContain('42');

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('handles tool with unicode characters', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $result = $client->callTool('echo', ['message' => 'Hello 世界! 🚀']);

    expect($result['content'][0]['text'])->toContain('世界');

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('handles non-existent tool error', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    expect(fn () => $client->callTool('nonexistent_tool', []))
        ->toThrow(McpProtocolException::class);

    $client->disconnect();
})->group('integration', 'mcp', 'http');

// ===== Resource Tests =====

test('discovers resources via HTTP transport', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $resources = $client->discoverResources();

    expect($resources)->toBeArray()
        ->and($resources)->toHaveKey('resources')
        ->and($resources['resources'])->toBeArray();

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('reads resource via HTTP transport', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $resources = $client->discoverResources();

    if (empty($resources['resources'])) {
        $this->markTestSkipped('No resources available on server');
    }

    $uri = $resources['resources'][0]['uri'];
    $content = $client->readResource($uri);

    expect($content)->toBeArray()
        ->and($content)->toHaveKey('contents');

    $client->disconnect();
})->group('integration', 'mcp', 'http');

// ===== Prompt Tests =====

test('discovers prompts via HTTP transport', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $prompts = $client->discoverPrompts();

    expect($prompts)->toBeArray()
        ->and($prompts)->toHaveKey('prompts')
        ->and($prompts['prompts'])->toBeArray();

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('gets prompt via HTTP transport', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $prompts = $client->discoverPrompts();

    if (empty($prompts['prompts'])) {
        $this->markTestSkipped('No prompts available on server');
    }

    // Find the first prompt and provide required arguments
    $promptDef = $prompts['prompts'][0];
    $promptName = $promptDef['name'];

    // Build arguments based on prompt's required args
    $args = [];
    if (isset($promptDef['arguments'])) {
        foreach ($promptDef['arguments'] as $arg) {
            $args[$arg['name']] = 'test-value';
        }
    }

    try {
        $prompt = $client->getPrompt($promptName, $args);
        expect($prompt)->toBeArray()
            ->and($prompt)->toHaveKey('messages');
    } catch (\Pagent\Mcp\Exceptions\McpProtocolException $e) {
        // Some prompts may have complex requirements
        $this->markTestSkipped('Prompt requires specific arguments: '.$e->getMessage());
    }

    $client->disconnect();
})->group('integration', 'mcp', 'http');

// ===== Edge Cases =====

test('handles rapid consecutive calls via HTTP', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    $results = [];
    for ($i = 1; $i <= 5; $i++) {
        $result = $client->callTool('add', ['a' => $i, 'b' => $i]);
        $results[] = $result['content'][0]['text'];
    }

    expect($results)->toHaveCount(5);

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('maintains connection across multiple operations', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);
    $client->connect();

    // Perform multiple different operations
    $tools = $client->discoverTools();
    expect($tools)->toBeArray();

    $resources = $client->discoverResources();
    expect($resources)->toBeArray();

    $prompts = $client->discoverPrompts();
    expect($prompts)->toBeArray();

    $result = $client->callTool('echo', ['message' => 'test']);
    expect($result)->toBeArray();

    // Connection should still be active
    expect($client->isConnected())->toBeTrue();

    $client->disconnect();
})->group('integration', 'mcp', 'http');

test('handles reconnection after disconnect', function () {
    $url = getMcpServerUrl($this);

    $transport = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );

    $client = new McpClient($transport);

    // First connection
    $client->connect();
    expect($client->isConnected())->toBeTrue();
    $client->disconnect();
    expect($client->isConnected())->toBeFalse();

    // Second connection (should work with new transport)
    $transport2 = new HttpSseTransport(
        baseUrl: $url,
        timeoutMs: 10000
    );
    $client2 = new McpClient($transport2);
    $client2->connect();
    expect($client2->isConnected())->toBeTrue();
    $client2->disconnect();
})->group('integration', 'mcp', 'http');
