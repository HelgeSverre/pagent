<?php

declare(strict_types=1);

use Pagent\Exceptions\InvalidArgumentException;
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
});

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

test('custom environment variables augment the inherited process environment', function () {
    $script = <<<'PHP'
    fgets(STDIN);
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'custom' => getenv('PAGENT_TEST_VAR'),
            'path' => getenv('PATH'),
        ],
    ]) . "\n";
    PHP;
    $transport = new StdioTransport(
        [PHP_BINARY, '-r', $script],
        env: ['PAGENT_TEST_VAR' => 'value'],
    );
    $transport->connect();

    $response = $transport->sendRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test',
    ]);
    $transport->disconnect();

    expect($response['result']['custom'])->toBe('value')
        ->and($response['result']['path'])->toBe(getenv('PATH'));
});

test('it rejects invalid environment entries', function () {
    expect(fn () => new StdioTransport(['cat'], env: ['BAD=NAME' => 'value']))
        ->toThrow(InvalidArgumentException::class, 'environment variable names');

    expect(fn () => new StdioTransport(['cat'], env: ['VALID_NAME' => "bad\0value"]))
        ->toThrow(InvalidArgumentException::class, 'environment variable values');
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
    expect(fn () => new StdioTransport(''))
        ->toThrow(InvalidArgumentException::class, 'non-empty');
});

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
    $marker = sys_get_temp_dir().'/pagent-stdio-injection-'.bin2hex(random_bytes(8));

    expect(fn () => new StdioTransport('echo test; touch '.escapeshellarg($marker)))
        ->toThrow(InvalidArgumentException::class, 'Shell operators are not allowed')
        ->and(file_exists($marker))->toBeFalse();
});

test('it passes argv values literally without shell expansion', function () {
    $script = <<<'PHP'
    fgets(STDIN);
    echo json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['argument' => $argv[1]]]) . "\n";
    PHP;
    $literal = 'value; $(touch should-not-run)';
    $transport = new StdioTransport([PHP_BINARY, '-r', $script, $literal]);
    $transport->connect();

    $response = $transport->sendRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test',
    ]);
    $transport->disconnect();

    expect($response['result']['argument'])->toBe($literal);
});

test('shell execution requires the explicit shell factory', function () {
    $transport = StdioTransport::fromShellCommand('exec cat');

    $transport->connect();

    expect($transport->isConnected())->toBeTrue();

    $transport->disconnect();
});

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

test('it skips notifications and returns the id-matched response, draining stderr', function () {
    $script = <<<'PHP'
    fgets(STDIN);
    fwrite(STDERR, str_repeat("noisy server log line\n", 50));
    echo json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 'tok', 'progress' => 1, 'total' => 2]]) . "\n";
    echo json_encode(['jsonrpc' => '2.0', 'id' => 42, 'result' => ['ok' => true]]) . "\n";
    PHP;

    $transport = new StdioTransport(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script), timeoutMs: 10000);
    $transport->connect();

    $notifications = [];
    $transport->setNotificationHandler(function (array $notification) use (&$notifications): void {
        $notifications[] = $notification;
    });

    $response = $transport->sendRequest([
        'jsonrpc' => '2.0',
        'id' => 42,
        'method' => 'tools/list',
        'params' => (object) [],
    ]);

    $transport->disconnect();

    expect($response['id'])->toBe(42)
        ->and($response['result'])->toBe(['ok' => true])
        ->and($notifications)->toHaveCount(1)
        ->and($notifications[0]['method'])->toBe('notifications/progress');
});

test('it buffers responses for other request ids', function () {
    $script = <<<'PHP'
    fgets(STDIN);
    echo json_encode(['jsonrpc' => '2.0', 'id' => 99, 'result' => ['other' => true]]) . "\n";
    echo json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['mine' => true]]) . "\n";
    fgets(STDIN);
    PHP;

    $transport = new StdioTransport(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script), timeoutMs: 10000);
    $transport->connect();

    $response = $transport->sendRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ]);

    expect($response['result'])->toBe(['mine' => true]);

    // The buffered id-99 response is returned without further reading
    $response99 = $transport->sendRequest([
        'jsonrpc' => '2.0',
        'id' => 99,
        'method' => 'tools/list',
        'params' => (object) [],
    ]);

    $transport->disconnect();

    expect($response99['result'])->toBe(['other' => true]);
});
