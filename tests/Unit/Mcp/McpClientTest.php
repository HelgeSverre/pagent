<?php

declare(strict_types=1);

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\McpClient;
use Tests\Unit\Mcp\Transports\FakeTransport;

beforeEach(function () {
    $this->transport = new FakeTransport;
    $this->client = new McpClient($this->transport, 'test-client', '1.0.0');
});

test('connect performs initialization handshake', function () {
    // Queue the initialize response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [],
            ],
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '1.0.0',
            ],
        ],
    ]);

    $this->client->connect();

    expect($this->client->isConnected())->toBeTrue();
});

test('connect throws exception if initialize response is invalid', function () {
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        // Missing 'result'
    ]);

    expect(fn () => $this->client->connect())
        ->toThrow(McpProtocolException::class, 'Missing result in initialize response');
});

test('disconnect closes transport and resets state', function () {
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);

    $this->client->connect();

    $this->client->disconnect();

    expect($this->client->isConnected())->toBeFalse();
});

test('isConnected returns false when not initialized', function () {
    $this->transport->connect();

    expect($this->client->isConnected())->toBeFalse();
});

test('isConnected checks both initialization and transport', function () {
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);

    $this->client->connect();

    expect($this->client->isConnected())->toBeTrue();

    $this->transport->disconnect();

    expect($this->client->isConnected())->toBeFalse();
});

test('discoverTools requests and caches tool list', function () {
    // Setup connected client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => ['tools' => []],
        ],
    ]);

    $this->client->connect();

    // Queue tools/list response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'tools' => [
                [
                    'name' => 'calculator',
                    'description' => 'Performs calculations',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'expression' => ['type' => 'string'],
                        ],
                    ],
                ],
                [
                    'name' => 'weather',
                    'description' => 'Gets weather info',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $tools = $this->client->discoverTools();

    expect($tools)->toHaveCount(2)
        ->and($tools[0]['name'])->toBe('calculator')
        ->and($tools[1]['name'])->toBe('weather');

    // Verify tools are cached
    expect($this->client->getAvailableTools())->toBe($tools);
});

test('discoverTools throws exception when not connected', function () {
    expect(fn () => $this->client->discoverTools())
        ->toThrow(McpConnectionException::class, 'Not connected to MCP server');
});

test('discoverTools throws exception on invalid response', function () {
    // Setup connected client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);

    $this->client->connect();

    // Queue invalid tools/list response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            // Missing 'tools' array
        ],
    ]);

    expect(fn () => $this->client->discoverTools())
        ->toThrow(McpProtocolException::class, 'Missing or invalid tools array');
});

test('callTool sends tools/call request', function () {
    // Setup connected client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);

    $this->client->connect();

    // Queue tools/call response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'content' => [
                ['type' => 'text', 'text' => '4'],
            ],
        ],
    ]);

    $result = $this->client->callTool('calculator', ['expression' => '2 + 2']);

    expect($result)->toHaveKey('content');
});

test('callTool throws exception when not connected', function () {
    expect(fn () => $this->client->callTool('test', []))
        ->toThrow(McpConnectionException::class, 'Not connected to MCP server');
});

test('callTool throws exception on server error', function () {
    // Setup connected client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);

    $this->client->connect();

    // Queue error response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'error' => [
            'code' => -32601,
            'message' => 'Tool not found',
        ],
    ]);

    expect(fn () => $this->client->callTool('nonexistent', []))
        ->toThrow(McpProtocolException::class, 'MCP server error [-32601]: Tool not found');
});

test('getServerCapabilities returns capabilities from initialization', function () {
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [],
                'prompts' => [],
            ],
        ],
    ]);

    $this->client->connect();

    $capabilities = $this->client->getServerCapabilities();

    expect($capabilities)->toHaveKey('tools')
        ->and($capabilities)->toHaveKey('prompts');
});

test('getServerCapabilities returns null when not connected', function () {
    expect($this->client->getServerCapabilities())->toBeNull();
});

// Resources tests
test('discoverResources requests and caches resource list', function () {
    // Setup connected client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue resources/list response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'resources' => [
                [
                    'uri' => 'file:///test.txt',
                    'name' => 'Test File',
                    'mimeType' => 'text/plain',
                ],
            ],
        ],
    ]);

    $result = $this->client->discoverResources();

    expect($result)->toHaveKey('resources')
        ->and($result['resources'])->toHaveCount(1)
        ->and($result['resources'][0]['uri'])->toBe('file:///test.txt');
});

test('readResource returns resource content', function () {
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'contents' => [
                [
                    'uri' => 'file:///test.txt',
                    'mimeType' => 'text/plain',
                    'text' => 'Test content',
                ],
            ],
        ],
    ]);

    $result = $this->client->readResource('file:///test.txt');

    expect($result)->toHaveKey('contents');
});

// Prompts tests
test('discoverPrompts requests and caches prompt list', function () {
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'prompts' => [
                [
                    'name' => 'greeting',
                    'description' => 'Generate greeting',
                ],
            ],
        ],
    ]);

    $result = $this->client->discoverPrompts();

    expect($result)->toHaveKey('prompts')
        ->and($result['prompts'])->toHaveCount(1)
        ->and($result['prompts'][0]['name'])->toBe('greeting');
});

test('getPrompt returns prompt with messages', function () {
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'Hello'],
                ],
            ],
        ],
    ]);

    $result = $this->client->getPrompt('greeting', ['name' => 'World']);

    expect($result)->toHaveKey('messages');
});

// Progress notifications tests
test('progress callback is invoked on progress notifications', function () {
    $progressData = [];

    $this->client->setProgressCallback(function ($token, $progress, $total) use (&$progressData) {
        $progressData = [
            'token' => $token,
            'progress' => $progress,
            'total' => $total,
        ];
    });

    $this->client->handleNotification([
        'jsonrpc' => '2.0',
        'method' => 'notifications/progress',
        'params' => [
            'progressToken' => 'test-token',
            'progress' => 50,
            'total' => 100,
        ],
    ]);

    expect($progressData)->toBe([
        'token' => 'test-token',
        'progress' => 50,
        'total' => 100,
    ]);
});

test('progress callback can be cleared', function () {
    $called = false;
    $this->client->setProgressCallback(function () use (&$called) {
        $called = true;
    });

    $this->client->setProgressCallback(null);

    $this->client->handleNotification([
        'jsonrpc' => '2.0',
        'method' => 'notifications/progress',
        'params' => [],
    ]);

    expect($called)->toBeFalse();
});

// Event dispatching tests
test('connection initiating and established events are dispatched', function () {
    $events = [];

    $this->client->on('mcp_connection_initiating', function ($event) use (&$events) {
        $events[] = ['type' => 'initiating', 'event' => $event];
    });

    $this->client->on('mcp_connection_established', function ($event) use (&$events) {
        $events[] = ['type' => 'established', 'event' => $event];
    });

    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => ['tools' => []],
            'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
        ],
    ]);

    $this->client->connect();

    expect($events)->toHaveCount(2)
        ->and($events[0]['type'])->toBe('initiating')
        ->and($events[0]['event']->clientName)->toBe('test-client')
        ->and($events[0]['event']->clientVersion)->toBe('1.0.0')
        ->and($events[1]['type'])->toBe('established')
        ->and($events[1]['event']->serverInfo)->toBe(['name' => 'test-server', 'version' => '1.0.0'])
        ->and($events[1]['event']->durationMs)->toBeGreaterThan(0);
});

test('connection failed event is dispatched', function () {
    $failedEvent = null;

    $this->client->on('mcp_connection_failed', function ($event) use (&$failedEvent) {
        $failedEvent = $event;
    });

    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        // Missing 'result' to trigger failure
    ]);

    try {
        $this->client->connect();
    } catch (Exception $e) {
        // Expected exception
    }

    expect($failedEvent)->not->toBeNull()
        ->and($failedEvent->clientName)->toBe('test-client')
        ->and($failedEvent->error)->toBeInstanceOf(Exception::class);
});

test('disconnecting and disconnected events are dispatched', function () {
    $events = [];

    $this->client->on('mcp_disconnecting', function ($event) use (&$events) {
        $events[] = ['type' => 'disconnecting', 'event' => $event];
    });

    $this->client->on('mcp_disconnected', function ($event) use (&$events) {
        $events[] = ['type' => 'disconnected', 'event' => $event];
    });

    // Connect first
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    $this->client->disconnect();

    expect($events)->toHaveCount(2)
        ->and($events[0]['type'])->toBe('disconnecting')
        ->and($events[1]['type'])->toBe('disconnected')
        ->and($events[0]['event']->clientName)->toBe('test-client')
        ->and($events[1]['event']->clientName)->toBe('test-client');
});

test('tool discovery events are dispatched', function () {
    $events = [];

    $this->client->on('mcp_tools_discovering', function ($event) use (&$events) {
        $events[] = ['type' => 'discovering', 'event' => $event];
    });

    $this->client->on('mcp_tools_discovered', function ($event) use (&$events) {
        $events[] = ['type' => 'discovered', 'event' => $event];
    });

    // Connect first
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue tools/list response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'tools' => [
                ['name' => 'calculator'],
                ['name' => 'weather'],
                ['name' => 'translator'],
            ],
        ],
    ]);

    $this->client->discoverTools();

    expect($events)->toHaveCount(2)
        ->and($events[0]['type'])->toBe('discovering')
        ->and($events[1]['type'])->toBe('discovered')
        ->and($events[1]['event']->toolCount)->toBe(3)
        ->and($events[1]['event']->durationMs)->toBeGreaterThan(0);
});

test('tool call success events are dispatched', function () {
    $events = [];

    $this->client->on('mcp_tool_calling', function ($event) use (&$events) {
        $events[] = ['type' => 'calling', 'event' => $event];
    });

    $this->client->on('mcp_tool_called', function ($event) use (&$events) {
        $events[] = ['type' => 'called', 'event' => $event];
    });

    // Connect first
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue tools/call response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'content' => [
                ['type' => 'text', 'text' => '8'],
            ],
        ],
    ]);

    $this->client->callTool('calculator', ['a' => 5, 'b' => 3]);

    expect($events)->toHaveCount(2)
        ->and($events[0]['type'])->toBe('calling')
        ->and($events[0]['event']->toolName)->toBe('calculator')
        ->and($events[0]['event']->arguments)->toBe(['a' => 5, 'b' => 3])
        ->and($events[1]['type'])->toBe('called')
        ->and($events[1]['event']->toolName)->toBe('calculator')
        ->and($events[1]['event']->durationMs)->toBeGreaterThan(0);
});

test('tool error event is dispatched', function () {
    $errorEvent = null;

    $this->client->on('mcp_tool_error', function ($event) use (&$errorEvent) {
        $errorEvent = $event;
    });

    // Connect first
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue error response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'error' => [
            'code' => -32601,
            'message' => 'Tool not found',
        ],
    ]);

    try {
        $this->client->callTool('nonexistent', ['arg' => 'value']);
    } catch (Exception $e) {
        // Expected exception
    }

    expect($errorEvent)->not->toBeNull()
        ->and($errorEvent->toolName)->toBe('nonexistent')
        ->and($errorEvent->arguments)->toBe(['arg' => 'value'])
        ->and($errorEvent->error)->toBeInstanceOf(Exception::class);
});

test('custom event listeners can be registered', function () {
    $calledCount = 0;

    // Register a custom listener using the on() method
    $this->client->on('mcp_tool_called', function () use (&$calledCount) {
        $calledCount++;
    });

    // Connect first
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Call a tool twice
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => ['content' => []],
    ]);
    $this->client->callTool('test1', []);

    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 3,
        'result' => ['content' => []],
    ]);
    $this->client->callTool('test2', []);

    expect($calledCount)->toBe(2);
});
