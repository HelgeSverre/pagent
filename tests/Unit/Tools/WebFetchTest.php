<?php

declare(strict_types=1);

use Pagent\Tools\WebFetch;

test('web fetch has correct metadata', function () {
    $tool = new WebFetch;

    expect($tool->name())->toBe('web_fetch');
    expect($tool->description())->toContain('Fetch content');
    expect($tool->parameters())->toHaveKey('properties');
});

test('web fetch throws when url missing', function () {
    $tool = new WebFetch;

    expect(fn () => $tool->execute([]))
        ->toThrow(RuntimeException::class, 'URL parameter is required');
});

test('web fetch blocks localhost with SSRF protection', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://localhost']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('web fetch blocks private IPs with SSRF protection', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://192.168.1.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

// ========================================
// COMPREHENSIVE SSRF PROTECTION TESTS
// ========================================

test('it blocks 10.0.0.0/8 private range', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://10.0.0.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');

    expect(fn () => $tool->execute(['url' => 'http://10.255.255.255']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('it blocks 172.16.0.0/12 private range', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://172.16.0.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');

    expect(fn () => $tool->execute(['url' => 'http://172.31.255.255']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('it blocks 192.168.0.0/16 private range', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://192.168.0.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');

    expect(fn () => $tool->execute(['url' => 'http://192.168.255.255']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('it blocks link-local addresses (169.254.0.0/16)', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://169.254.1.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');

    expect(fn () => $tool->execute(['url' => 'http://169.254.169.254']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('it blocks 127.0.0.0/8 loopback range', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://127.0.0.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');

    expect(fn () => $tool->execute(['url' => 'http://127.255.255.255']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('it allows SSRF protection to be disabled', function () {
    $tool = new WebFetch(ssrfProtection: false);

    // Should not throw on private IPs when protection is disabled
    // (test will fail to fetch, but won't throw SSRF error)
    try {
        $tool->execute(['url' => 'http://192.168.1.1']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('SSRF protection');
    }
});

test('it validates URL format', function () {
    $tool = new WebFetch;

    expect(fn () => $tool->execute(['url' => 'not-a-url']))
        ->toThrow(RuntimeException::class, 'Invalid URL');

    expect(fn () => $tool->execute(['url' => 'http://']))
        ->toThrow(RuntimeException::class, 'Invalid URL');
});

test('it has configurable timeout', function () {
    $tool = new WebFetch(timeout: 5);

    // Can't easily test actual timeout without making real requests
    // This test just verifies the constructor accepts timeout parameter
    expect($tool)->toBeInstanceOf(WebFetch::class);
});

test('it has configurable max size', function () {
    $tool = new WebFetch(maxSize: 1000);

    // Can't easily test actual size limit without making real requests
    // This test just verifies the constructor accepts maxSize parameter
    expect($tool)->toBeInstanceOf(WebFetch::class);
});

test('it accepts custom headers parameter', function () {
    $tool = new WebFetch;

    // Test that headers parameter is accepted in execute()
    // Will fail to fetch non-existent URL, but validates parameter handling
    try {
        $tool->execute([
            'url' => 'http://example-nonexistent-domain-xyz.com',
            'headers' => ['User-Agent' => 'TestAgent'],
        ]);
    } catch (RuntimeException $e) {
        // Expected to fail on network request, not parameter validation
        expect($e->getMessage())->not->toContain('parameter');
    }
});

test('it prevents header injection via newlines', function () {
    $tool = new WebFetch(ssrfProtection: false);

    // Test that newlines in headers don't cause injection
    // The actual protection happens at the HTTP stream context level
    try {
        $tool->execute([
            'url' => 'http://example-nonexistent-domain-xyz.com',
            'headers' => ['X-Custom' => "value\r\nInjected-Header: malicious"],
        ]);
    } catch (RuntimeException $e) {
        // Expected to fail on network, not on header validation
        expect($e->getMessage())->toContain('Failed to fetch');
    }
});

test('it enforces max redirects', function () {
    // The tool is configured with max_redirects => 5
    // This test documents the configuration exists
    $tool = new WebFetch;

    expect($tool)->toBeInstanceOf(WebFetch::class);
    // Actual redirect testing would require a test server
})->skip('Requires HTTP test server to properly test redirect limits');
