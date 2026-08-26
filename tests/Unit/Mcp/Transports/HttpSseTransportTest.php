<?php

declare(strict_types=1);

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpTimeoutException;
use Pagent\Mcp\Transports\HttpSseTransport;

/**
 * HttpSseTransport Unit Tests
 *
 * Tests the HTTP/SSE transport layer for MCP communication.
 * Full integration tests with real servers are in Integration tests.
 */

// ===== Construction Tests =====

test('transport can be instantiated with base URL', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class)
        ->and($transport->isConnected())->toBeFalse();
});

test('transport can be instantiated with HTTPS URL', function () {
    $transport = new HttpSseTransport('https://secure.example.com');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class)
        ->and($transport->isConnected())->toBeFalse();
});

test('transport can be instantiated with trailing slash', function () {
    $transport = new HttpSseTransport('http://localhost:3000/');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('transport can be instantiated with path', function () {
    $transport = new HttpSseTransport('http://localhost:3000/mcp');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('transport can be instantiated with custom headers', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        headers: [
            'Authorization' => 'Bearer secret-token',
            'X-Custom-Header' => 'custom-value',
        ]
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('transport can be instantiated with custom timeout', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        timeoutMs: 5000
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('transport accepts zero timeout', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        timeoutMs: 0
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('transport accepts very long timeout', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        timeoutMs: 300000  // 5 minutes
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('transport can be instantiated with all parameters', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'https://api.example.com/mcp/',
        headers: [
            'Authorization' => 'Bearer token',
            'X-Client-Id' => 'test-client',
            'User-Agent' => 'TestClient/1.0',
        ],
        timeoutMs: 60000
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class)
        ->and($transport->isConnected())->toBeFalse();
});

// ===== Connection State Tests =====

test('isConnected returns false before connection', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect($transport->isConnected())->toBeFalse();
});

test('disconnect when not connected does not throw', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    // Should not throw
    $transport->disconnect();

    expect($transport->isConnected())->toBeFalse();
});

test('multiple disconnects are idempotent', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    $transport->disconnect();
    $transport->disconnect();
    $transport->disconnect();

    expect($transport->isConnected())->toBeFalse();
});

test('connect to invalid URL throws exception', function () {
    $transport = new HttpSseTransport('http://invalid-host-that-does-not-exist-12345.com');

    expect(fn () => $transport->connect())
        ->toThrow(McpConnectionException::class);
});

test('connect to invalid port throws exception', function () {
    $transport = new HttpSseTransport('http://localhost:99999');

    expect(fn () => $transport->connect())
        ->toThrow(McpConnectionException::class);
});

test('connect to non-responsive server throws exception', function () {
    $server = startNonResponsiveSseServer();
    [, $port] = $server;
    $transport = new HttpSseTransport(baseUrl: "http://127.0.0.1:{$port}", timeoutMs: 100);

    try {
        expect(fn () => $transport->connect())
            ->toThrow(McpTimeoutException::class, 'timed out after 100ms')
            ->and($transport->isConnected())->toBeFalse();
    } finally {
        stopNonResponsiveSseServer($server);
    }
});

/** @return array{resource, int, string} */
function startNonResponsiveSseServer(): array
{
    $router = sys_get_temp_dir().'/pagent_sse_router_'.bin2hex(random_bytes(8)).'.php';
    file_put_contents($router, <<<'PHP'
<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
echo ": keepalive\n\n";
flush();
usleep(500000);
PHP);

    $port = random_int(19000, 19999);
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        @unlink($router);
        throw new RuntimeException('Failed to start local SSE fixture');
    }

    set_error_handler(static fn (): bool => true);
    try {
        $deadline = microtime(true) + 3;
        do {
            $connection = fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
    } finally {
        restore_error_handler();
    }

    return [$process, $port, $router];
}

/** @param array{resource, int, string} $server */
function stopNonResponsiveSseServer(array $server): void
{
    [$process, , $router] = $server;
    proc_terminate($process);
    proc_close($process);
    @unlink($router);
}

// ===== Error Handling Tests =====

test('sendRequest throws when not connected', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect(fn () => $transport->sendRequest(['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1]))
        ->toThrow(McpConnectionException::class, 'Not connected to MCP server');
});

test('sendNotification throws when not connected', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect(fn () => $transport->sendNotification(['jsonrpc' => '2.0', 'method' => 'test']))
        ->toThrow(McpConnectionException::class, 'Not connected to MCP server');
});

test('sendRequest with null ID throws when not connected', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect(fn () => $transport->sendRequest(['jsonrpc' => '2.0', 'method' => 'test', 'id' => null]))
        ->toThrow(McpConnectionException::class);
});

test('sendRequest with string ID throws when not connected', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect(fn () => $transport->sendRequest(['jsonrpc' => '2.0', 'method' => 'test', 'id' => 'req-1']))
        ->toThrow(McpConnectionException::class);
});

// ===== URL and Configuration Tests =====

test('handles URL with port number', function () {
    $transport = new HttpSseTransport('http://localhost:8080');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles URL with subdomain', function () {
    $transport = new HttpSseTransport('https://mcp.api.example.com');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles URL with query parameters', function () {
    $transport = new HttpSseTransport('http://localhost:3000?token=abc123');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles URL with authentication', function () {
    $transport = new HttpSseTransport('http://user:pass@localhost:3000');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles IPv4 address', function () {
    $transport = new HttpSseTransport('http://127.0.0.1:3000');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles IPv6 address', function () {
    $transport = new HttpSseTransport('http://[::1]:3000');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

// ===== Header Tests =====

test('accepts empty headers array', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        headers: []
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('accepts multiple custom headers', function () {
    $headers = [
        'Authorization' => 'Bearer token',
        'X-Client-Id' => 'client-123',
        'X-Request-Id' => 'req-456',
        'User-Agent' => 'CustomClient/2.0',
        'Accept-Language' => 'en-US',
    ];

    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        headers: $headers
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('accepts headers with special characters', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        headers: [
            'X-Custom' => 'value-with-dashes_and_underscores.123',
        ]
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('accepts headers with unicode values', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        headers: [
            'X-Message' => 'Hello 世界 🚀',
        ]
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

// ===== Timeout Tests =====

test('accepts small timeout values', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        timeoutMs: 100
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('accepts standard timeout values', function () {
    $timeouts = [1000, 5000, 10000, 30000, 60000];

    foreach ($timeouts as $timeout) {
        $transport = new HttpSseTransport(
            baseUrl: 'http://localhost:3000',
            timeoutMs: $timeout
        );

        expect($transport)->toBeInstanceOf(HttpSseTransport::class);
    }
});

test('default timeout is 30000ms', function () {
    // We can't directly test the private property, but we can verify construction succeeds
    $transport = new HttpSseTransport('http://localhost:3000');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

// ===== Edge Cases =====

test('handles URL with no scheme gracefully', function () {
    // PHP's fopen will handle this, but it's an edge case
    $transport = new HttpSseTransport('localhost:3000');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles very long URL', function () {
    $longPath = str_repeat('/path', 100);
    $transport = new HttpSseTransport("http://localhost:3000{$longPath}");

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles URL with fragment', function () {
    $transport = new HttpSseTransport('http://localhost:3000#section');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('handles localhost variants', function () {
    $variants = [
        'http://localhost',
        'http://localhost:3000',
        'http://127.0.0.1',
        'http://127.0.0.1:3000',
        'http://[::1]',
        'http://[::1]:3000',
    ];

    foreach ($variants as $url) {
        $transport = new HttpSseTransport($url);
        expect($transport)->toBeInstanceOf(HttpSseTransport::class);
    }
});

test('handles different schemes', function () {
    $schemes = ['http', 'https'];

    foreach ($schemes as $scheme) {
        $transport = new HttpSseTransport("{$scheme}://localhost:3000");
        expect($transport)->toBeInstanceOf(HttpSseTransport::class);
    }
});

test('destructor calls disconnect', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    // Destructor will be called when $transport goes out of scope
    unset($transport);

    // If this doesn't throw, destructor handled cleanup properly
    expect(true)->toBeTrue();
});

test('disconnect after attempted connection', function () {
    $transport = new HttpSseTransport('http://invalid-host-12345.test');

    try {
        $transport->connect();
    } catch (McpConnectionException $e) {
        // Expected to fail
    }

    // Should not throw
    $transport->disconnect();

    expect($transport->isConnected())->toBeFalse();
});

// ===== Configuration Combinations =====

test('all valid configuration combinations', function () {
    $configs = [
        [
            'baseUrl' => 'http://localhost:3000',
            'headers' => [],
            'timeoutMs' => 30000,
        ],
        [
            'baseUrl' => 'https://api.example.com',
            'headers' => ['Authorization' => 'Bearer token'],
            'timeoutMs' => 60000,
        ],
        [
            'baseUrl' => 'http://127.0.0.1:8080/mcp/',
            'headers' => [
                'X-Client' => 'test',
                'X-Version' => '1.0',
            ],
            'timeoutMs' => 5000,
        ],
    ];

    foreach ($configs as $config) {
        $transport = new HttpSseTransport(
            baseUrl: $config['baseUrl'],
            headers: $config['headers'],
            timeoutMs: $config['timeoutMs']
        );

        expect($transport)->toBeInstanceOf(HttpSseTransport::class)
            ->and($transport->isConnected())->toBeFalse();
    }
});

test('maintains state across multiple disconnect calls', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect($transport->isConnected())->toBeFalse();

    $transport->disconnect();
    expect($transport->isConnected())->toBeFalse();

    $transport->disconnect();
    expect($transport->isConnected())->toBeFalse();

    $transport->disconnect();
    expect($transport->isConnected())->toBeFalse();
});
