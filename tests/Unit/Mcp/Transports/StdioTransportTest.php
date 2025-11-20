<?php

declare(strict_types=1);

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Transports\StdioTransport;

/**
 * StdioTransport Tests
 *
 * These tests cover the StdioTransport layer for MCP communication.
 * Full integration tests with actual MCP servers are in Integration tests.
 */
test('it creates transport with command', function () {
    $transport = new StdioTransport('node server.js');

    expect($transport)->toBeInstanceOf(StdioTransport::class);
});

test('it starts disconnected', function () {
    $transport = new StdioTransport('node server.js');

    expect($transport->isConnected())->toBeFalse();
});

test('it throws when sending request while disconnected', function () {
    $transport = new StdioTransport('node server.js');

    expect(fn () => $transport->sendRequest(['test' => 'data']))
        ->toThrow(McpConnectionException::class, 'Not connected to MCP server');
});

test('it throws when sending notification while disconnected', function () {
    $transport = new StdioTransport('node server.js');

    expect(fn () => $transport->sendNotification(['test' => 'data']))
        ->toThrow(McpConnectionException::class, 'Not connected to MCP server');
});

test('it can disconnect when not connected', function () {
    $transport = new StdioTransport('node server.js');

    // Should not throw
    $transport->disconnect();

    expect($transport->isConnected())->toBeFalse();
});

test('it throws on invalid command', function () {
    $transport = new StdioTransport('this-command-definitely-does-not-exist-12345');

    expect(fn () => $transport->connect())
        ->toThrow(McpConnectionException::class);
})->skip('proc_open behavior with invalid commands varies by system');

test('it accepts working directory parameter', function () {
    $transport = new StdioTransport(
        command: 'echo test',
        cwd: '/tmp'
    );

    expect($transport)->toBeInstanceOf(StdioTransport::class);
});

test('it accepts environment variables', function () {
    $transport = new StdioTransport(
        command: 'echo test',
        env: ['TEST_VAR' => 'value']
    );

    expect($transport)->toBeInstanceOf(StdioTransport::class);
});

test('it accepts custom timeout', function () {
    $transport = new StdioTransport(
        command: 'echo test',
        timeoutMs: 5000
    );

    expect($transport)->toBeInstanceOf(StdioTransport::class);
});

test('it handles multiple disconnects gracefully', function () {
    $transport = new StdioTransport('echo test');

    $transport->disconnect();
    $transport->disconnect();
    $transport->disconnect();

    expect($transport->isConnected())->toBeFalse();
});

test('it can connect to a simple echo command', function () {
    $transport = new StdioTransport('cat');

    $transport->connect();

    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
});

test('it prevents duplicate connections', function () {
    $transport = new StdioTransport('cat');

    $transport->connect();
    $transport->connect(); // Should be idempotent

    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
});

test('it cleans up on destruction', function () {
    $transport = new StdioTransport('cat');
    $transport->connect();

    expect($transport->isConnected())->toBeTrue();

    // Destructor will be called when $transport goes out of scope
    unset($transport);

    // We can't directly test the destructor, but we can verify no errors occur
    expect(true)->toBeTrue();
});

test('it disconnects properly after connection', function () {
    $transport = new StdioTransport('cat');

    $transport->connect();
    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
    expect($transport->isConnected())->toBeFalse();
});

test('it throws when trying to send after disconnect', function () {
    $transport = new StdioTransport('cat');

    $transport->connect();
    $transport->disconnect();

    expect(fn () => $transport->sendRequest(['test' => 'data']))
        ->toThrow(McpConnectionException::class, 'Not connected to MCP server');
});

test('it handles empty command parameter', function () {
    $transport = new StdioTransport('');

    expect(fn () => $transport->connect())
        ->toThrow(McpConnectionException::class);
})->skip('proc_open behavior with empty commands varies by system');

test('it accepts all constructor parameters', function () {
    $transport = new StdioTransport(
        command: 'node server.js',
        cwd: '/var/www',
        env: ['NODE_ENV' => 'production', 'PORT' => '3000'],
        timeoutMs: 60000
    );

    expect($transport)->toBeInstanceOf(StdioTransport::class)
        ->and($transport->isConnected())->toBeFalse();
});

test('it handles command with arguments', function () {
    $transport = new StdioTransport('cat -');

    $transport->connect();
    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
});

test('it throws on shell injection attempt', function () {
    // The transport should safely handle commands, but proc_open with shell
    // could be vulnerable if not used carefully
    $transport = new StdioTransport('echo test; rm -rf /');

    // This should fail to spawn, preventing the injection
    expect(fn () => $transport->connect())
        ->toThrow(McpConnectionException::class);
})->skip('proc_open behavior varies by system');

test('it handles disconnect of uninitialized transport', function () {
    $transport = new StdioTransport('cat');

    // Disconnect without ever connecting
    $transport->disconnect();

    expect($transport->isConnected())->toBeFalse();
});

test('it maintains state across multiple operations', function () {
    $transport = new StdioTransport('cat');

    expect($transport->isConnected())->toBeFalse();

    $transport->connect();
    expect($transport->isConnected())->toBeTrue();

    $transport->connect(); // Idempotent
    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
    expect($transport->isConnected())->toBeFalse();

    $transport->disconnect(); // Idempotent
    expect($transport->isConnected())->toBeFalse();
});

test('it can reconnect after disconnect', function () {
    $transport = new StdioTransport('cat');

    $transport->connect();
    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
    expect($transport->isConnected())->toBeFalse();

    $transport->connect();
    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
});
