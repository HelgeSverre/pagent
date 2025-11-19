<?php

declare(strict_types=1);

use Pagent\Events\Event;
use Pagent\Events\Events\Mcp\McpConnectionEstablishedEvent;
use Pagent\Events\Events\Mcp\McpConnectionFailedEvent;
use Pagent\Events\Events\Mcp\McpConnectionInitiatingEvent;
use Pagent\Events\Events\Mcp\McpDisconnectedEvent;
use Pagent\Events\Events\Mcp\McpDisconnectingEvent;
use Pagent\Events\Events\Mcp\McpToolCalledEvent;
use Pagent\Events\Events\Mcp\McpToolCallingEvent;
use Pagent\Events\Events\Mcp\McpToolErrorEvent;
use Pagent\Events\Events\Mcp\McpToolsDiscoveredEvent;
use Pagent\Events\Events\Mcp\McpToolsDiscoveringEvent;
use Pagent\Mcp\McpClient;
use Pagent\Mcp\Transports\StdioTransport;

test('McpConnectionInitiatingEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport, 'test-client', '1.0.0');

    $event = new McpConnectionInitiatingEvent($client, 'test-client', '1.0.0');

    expect($event->getEventName())->toBe('mcp_connection_initiating')
        ->and($event->client)->toBe($client)
        ->and($event->clientName)->toBe('test-client')
        ->and($event->clientVersion)->toBe('1.0.0')
        ->and($event->timestamp)->toBeFloat();
});

test('McpConnectionEstablishedEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport, 'test-client', '1.0.0');
    $serverCapabilities = ['tools' => true];
    $serverInfo = ['name' => 'test-server', 'version' => '2.0.0'];

    $event = new McpConnectionEstablishedEvent(
        $client,
        'test-client',
        '1.0.0',
        $serverCapabilities,
        $serverInfo,
        123.45
    );

    expect($event->getEventName())->toBe('mcp_connection_established')
        ->and($event->client)->toBe($client)
        ->and($event->clientName)->toBe('test-client')
        ->and($event->clientVersion)->toBe('1.0.0')
        ->and($event->serverCapabilities)->toBe($serverCapabilities)
        ->and($event->serverInfo)->toBe($serverInfo)
        ->and($event->durationMs)->toBe(123.45)
        ->and($event->timestamp)->toBeFloat();
});

test('McpConnectionFailedEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport, 'test-client', '1.0.0');
    $error = new RuntimeException('Connection failed');

    $event = new McpConnectionFailedEvent($client, 'test-client', '1.0.0', $error);

    expect($event->getEventName())->toBe('mcp_connection_failed')
        ->and($event->client)->toBe($client)
        ->and($event->clientName)->toBe('test-client')
        ->and($event->clientVersion)->toBe('1.0.0')
        ->and($event->error)->toBe($error)
        ->and($event->timestamp)->toBeFloat();
});

test('McpDisconnectingEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport, 'test-client', '1.0.0');

    $event = new McpDisconnectingEvent($client, 'test-client', '1.0.0');

    expect($event->getEventName())->toBe('mcp_disconnecting')
        ->and($event->client)->toBe($client)
        ->and($event->clientName)->toBe('test-client')
        ->and($event->clientVersion)->toBe('1.0.0')
        ->and($event->timestamp)->toBeFloat();
});

test('McpDisconnectedEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport, 'test-client', '1.0.0');

    $event = new McpDisconnectedEvent($client, 'test-client', '1.0.0');

    expect($event->getEventName())->toBe('mcp_disconnected')
        ->and($event->client)->toBe($client)
        ->and($event->clientName)->toBe('test-client')
        ->and($event->clientVersion)->toBe('1.0.0')
        ->and($event->timestamp)->toBeFloat();
});

test('McpToolsDiscoveringEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);

    $event = new McpToolsDiscoveringEvent($client);

    expect($event->getEventName())->toBe('mcp_tools_discovering')
        ->and($event->client)->toBe($client)
        ->and($event->timestamp)->toBeFloat();
});

test('McpToolsDiscoveredEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);
    $tools = [
        ['name' => 'calculator', 'description' => 'Math tool'],
        ['name' => 'weather', 'description' => 'Weather tool'],
    ];

    $event = new McpToolsDiscoveredEvent($client, $tools, 2, 45.67);

    expect($event->getEventName())->toBe('mcp_tools_discovered')
        ->and($event->client)->toBe($client)
        ->and($event->tools)->toBe($tools)
        ->and($event->toolCount)->toBe(2)
        ->and($event->durationMs)->toBe(45.67)
        ->and($event->timestamp)->toBeFloat();
});

test('McpToolCallingEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);
    $arguments = ['a' => 5, 'b' => 3];

    $event = new McpToolCallingEvent($client, 'calculator', $arguments);

    expect($event->getEventName())->toBe('mcp_tool_calling')
        ->and($event->client)->toBe($client)
        ->and($event->toolName)->toBe('calculator')
        ->and($event->arguments)->toBe($arguments)
        ->and($event->timestamp)->toBeFloat();
});

test('McpToolCalledEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);
    $arguments = ['a' => 5, 'b' => 3];
    $result = ['answer' => 8];

    $event = new McpToolCalledEvent($client, 'calculator', $arguments, $result, 23.45);

    expect($event->getEventName())->toBe('mcp_tool_called')
        ->and($event->client)->toBe($client)
        ->and($event->toolName)->toBe('calculator')
        ->and($event->arguments)->toBe($arguments)
        ->and($event->result)->toBe($result)
        ->and($event->durationMs)->toBe(23.45)
        ->and($event->timestamp)->toBeFloat();
});

test('McpToolErrorEvent has correct properties', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);
    $arguments = ['a' => 5, 'b' => 3];
    $error = new RuntimeException('Tool execution failed');

    $event = new McpToolErrorEvent($client, 'calculator', $arguments, $error);

    expect($event->getEventName())->toBe('mcp_tool_error')
        ->and($event->client)->toBe($client)
        ->and($event->toolName)->toBe('calculator')
        ->and($event->arguments)->toBe($arguments)
        ->and($event->error)->toBe($error)
        ->and($event->timestamp)->toBeFloat();
});

test('all MCP events extend base Event class', function () {
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);
    $error = new RuntimeException('Test error');

    $events = [
        new McpConnectionInitiatingEvent($client, 'test', '1.0'),
        new McpConnectionEstablishedEvent($client, 'test', '1.0', [], [], 1.0),
        new McpConnectionFailedEvent($client, 'test', '1.0', $error),
        new McpDisconnectingEvent($client, 'test', '1.0'),
        new McpDisconnectedEvent($client, 'test', '1.0'),
        new McpToolsDiscoveringEvent($client),
        new McpToolsDiscoveredEvent($client, [], 0, 1.0),
        new McpToolCallingEvent($client, 'tool', []),
        new McpToolCalledEvent($client, 'tool', [], [], 1.0),
        new McpToolErrorEvent($client, 'tool', [], $error),
    ];

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(Event::class);
    }
});
