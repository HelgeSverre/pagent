<?php

declare(strict_types=1);

use Pagent\Mcp\McpClient;
use Pagent\Mcp\McpToolAdapter;
use Pagent\Mcp\Transports\StdioTransport;

/**
 * Integration tests for MCP stdio transport.
 *
 * These tests require actual MCP servers to be installed.
 * They are marked as integration tests and can be skipped if MCP servers are not available.
 */
test('can connect to test MCP server', function () {
    // Create a simple test server script
    $serverScript = __DIR__.'/test-mcp-server.php';

    if (! file_exists($serverScript)) {
        $this->markTestSkipped('Test MCP server script not found');
    }

    $transport = new StdioTransport(
        command: "php {$serverScript}",
        cwd: __DIR__,
        timeoutMs: 5000
    );

    $client = new McpClient($transport, 'test-client', '1.0.0');

    $client->connect();

    expect($client->isConnected())->toBeTrue();

    $capabilities = $client->getServerCapabilities();
    expect($capabilities)->toBeArray();

    $client->disconnect();
})->group('integration', 'mcp');

test('can discover tools from test server', function () {
    $serverScript = __DIR__.'/test-mcp-server.php';

    if (! file_exists($serverScript)) {
        $this->markTestSkipped('Test MCP server script not found');
    }

    $transport = new StdioTransport(
        command: "php {$serverScript}",
        cwd: __DIR__,
        timeoutMs: 5000
    );

    $client = new McpClient($transport);
    $client->connect();

    $tools = $client->discoverTools();

    expect($tools)->toBeArray()
        ->and($tools)->not->toBeEmpty();

    // Verify tool structure
    if (count($tools) > 0) {
        expect($tools[0])->toHaveKey('name')
            ->and($tools[0])->toHaveKey('description');
    }

    $client->disconnect();
})->group('integration', 'mcp');

test('can call tool on test server', function () {
    $serverScript = __DIR__.'/test-mcp-server.php';

    if (! file_exists($serverScript)) {
        $this->markTestSkipped('Test MCP server script not found');
    }

    $transport = new StdioTransport(
        command: "php {$serverScript}",
        cwd: __DIR__,
        timeoutMs: 5000
    );

    $client = new McpClient($transport);
    $client->connect();

    $tools = $client->discoverTools();

    if (count($tools) === 0) {
        $this->markTestSkipped('No tools available on test server');
    }

    // Call the first available tool
    $toolName = $tools[0]['name'];
    $result = $client->callTool($toolName, []);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('content');

    $client->disconnect();
})->group('integration', 'mcp');

test('can create tool adapters from MCP tools', function () {
    $serverScript = __DIR__.'/test-mcp-server.php';

    if (! file_exists($serverScript)) {
        $this->markTestSkipped('Test MCP server script not found');
    }

    $transport = new StdioTransport(
        command: "php {$serverScript}",
        cwd: __DIR__,
        timeoutMs: 5000
    );

    $client = new McpClient($transport);
    $client->connect();

    $mcpTools = $client->discoverTools();
    $adapters = McpToolAdapter::fromToolList($client, $mcpTools);

    expect($adapters)->toBeArray()
        ->and($adapters)->not->toBeEmpty();

    foreach ($adapters as $adapter) {
        expect($adapter)->toBeInstanceOf(\Pagent\Contracts\Tool::class)
            ->and($adapter->getName())->toBeString()
            ->and($adapter->getDescription())->toBeString()
            ->and($adapter->getInputSchema())->toBeArray();
    }

    $client->disconnect();
})->group('integration', 'mcp');

test('tool adapter executes successfully', function () {
    $serverScript = __DIR__.'/test-mcp-server.php';

    if (! file_exists($serverScript)) {
        $this->markTestSkipped('Test MCP server script not found');
    }

    $transport = new StdioTransport(
        command: "php {$serverScript}",
        cwd: __DIR__,
        timeoutMs: 5000
    );

    $client = new McpClient($transport);
    $client->connect();

    $mcpTools = $client->discoverTools();

    if (count($mcpTools) === 0) {
        $this->markTestSkipped('No tools available on test server');
    }

    $adapters = McpToolAdapter::fromToolList($client, $mcpTools);
    $tool = $adapters[0];

    // Execute the tool
    $result = $tool->execute([]);

    expect($result)->not->toBeNull();

    $client->disconnect();
})->group('integration', 'mcp');
