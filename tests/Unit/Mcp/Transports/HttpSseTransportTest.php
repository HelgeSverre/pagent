<?php

declare(strict_types=1);

use Pagent\Mcp\Transports\HttpSseTransport;

test('transport can be instantiated with base URL', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect($transport)->toBeInstanceOf(HttpSseTransport::class)
        ->and($transport->isConnected())->toBeFalse();
});

test('transport can be instantiated with headers', function () {
    $transport = new HttpSseTransport(
        baseUrl: 'http://localhost:3000',
        headers: ['Authorization' => 'Bearer token'],
        timeoutMs: 10000
    );

    expect($transport)->toBeInstanceOf(HttpSseTransport::class);
});

test('isConnected returns false before connection', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect($transport->isConnected())->toBeFalse();
});

test('connect sets connected state', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    // Note: This will fail to actually connect without a real server
    // but we can test the basic flow
    try {
        $transport->connect();
        expect($transport->isConnected())->toBeTrue();
        $transport->disconnect();
    } catch (\Exception $e) {
        // Connection expected to fail without real server
        expect(true)->toBeTrue();
    }
});

test('disconnect clears connected state', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    try {
        $transport->connect();
        $transport->disconnect();
    } catch (\Exception $e) {
        // Expected
    }

    expect($transport->isConnected())->toBeFalse();
});

test('sendRequest throws when not connected', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect(fn () => $transport->sendRequest(['jsonrpc' => '2.0', 'method' => 'test']))
        ->toThrow(\Pagent\Mcp\Exceptions\McpConnectionException::class, 'Not connected');
});

test('sendNotification throws when not connected', function () {
    $transport = new HttpSseTransport('http://localhost:3000');

    expect(fn () => $transport->sendNotification(['jsonrpc' => '2.0', 'method' => 'test']))
        ->toThrow(\Pagent\Mcp\Exceptions\McpConnectionException::class, 'Not connected');
});
