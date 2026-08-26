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

test('it blocks IPv6 loopback and private ranges', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://[::1]']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
    expect(fn () => $tool->execute(['url' => 'http://[fc00::1]']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
    expect(fn () => $tool->execute(['url' => 'http://[2001:db8::1]']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('SSRF protection fails closed when DNS cannot resolve a host', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://definitely-missing.invalid']))
        ->toThrow(RuntimeException::class, 'Could not resolve host');
});

test('it allows SSRF protection to be disabled', function () {
    $server = startRedirectServer();
    [, $port] = $server;

    try {
        $tool = new WebFetch(
            ssrfProtection: false,
            allowList: ['127.0.0.1'],
            timeout: 3,
        );

        expect($tool->execute(['url' => "http://127.0.0.1:{$port}/"])['content'])
            ->toBe('root');
    } finally {
        stopRedirectServer($server);
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

    expect(fn () => $tool->execute([
        'url' => 'http://127.0.0.1',
        'headers' => ['User-Agent' => 'TestAgent'],
    ]))->toThrow(RuntimeException::class, 'SSRF protection');
});

test('it rejects header injection via newlines before making a request', function () {
    $tool = new WebFetch(ssrfProtection: false);

    expect(fn () => $tool->execute([
        'url' => 'http://example-nonexistent-domain-xyz.com',
        'headers' => ['X-Custom' => "value\r\nInjected-Header: malicious"],
    ]))->toThrow(InvalidArgumentException::class);
});

test('allow list blocks non-matching domain', function () {
    $tool = new WebFetch(allowList: ['example.com']);

    expect(fn () => $tool->execute(['url' => 'http://other.example']))
        ->toThrow(RuntimeException::class, 'URL not in allow list');
});

test('allow list blocks non-matching URL paths', function () {
    $tool = new WebFetch(allowList: ['api.github.com/repos/*']);

    expect(fn () => $tool->execute(['url' => 'http://api.github.com/users/test']))
        ->toThrow(RuntimeException::class, 'URL not in allow list');
});

test('allow list does not bypass SSRF protection for localhost', function () {
    $tool = new WebFetch(
        ssrfProtection: true,
        allowList: ['localhost', '127.0.0.1'],
        timeout: 1
    );

    expect(fn () => $tool->execute(['url' => 'http://localhost:8080']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
    expect(fn () => $tool->execute(['url' => 'http://127.0.0.1:8080']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('allow list does not bypass SSRF protection for private IPs', function () {
    $tool = new WebFetch(
        ssrfProtection: true,
        allowList: ['192.168.1.0/24'],
        timeout: 1
    );

    expect(fn () => $tool->execute(['url' => 'http://192.168.1.100']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('disallow list blocks exact domain match', function () {
    $tool = new WebFetch(disallowList: ['blocked.example']);

    expect(fn () => $tool->execute(['url' => 'http://blocked.example']))
        ->toThrow(RuntimeException::class, 'URL is in disallow list');
});

test('disallow list does not bypass SSRF protection', function () {
    $tool = new WebFetch(
        ssrfProtection: true,
        disallowList: ['external-blocked.com']
    );

    // SSRF protection should still block private IPs
    expect(fn () => $tool->execute(['url' => 'http://192.168.1.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');

    expect(fn () => $tool->execute(['url' => 'http://localhost']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

// ========================================
// REDIRECT RE-VALIDATION TESTS
// ========================================

function startRedirectServer(): array
{
    $router = sys_get_temp_dir().'/webfetch_router_'.uniqid().'.php';
    file_put_contents($router, <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'];
if ($uri === '/meta') { header('Location: http://169.254.169.254/latest/meta-data', true, 302); exit; }
if ($uri === '/a') { header('Location: /b', true, 302); exit; }
if ($uri === '/b') { echo 'final-content'; exit; }
if ($uri === '/large') { echo str_repeat('x', 32); exit; }
if (str_starts_with($uri, '/cross?')) { parse_str(parse_url($uri, PHP_URL_QUERY), $query); header('Location: http://127.0.0.1:' . $query['port'] . '/capture', true, 302); exit; }
if ($uri === '/capture') { echo $_SERVER['HTTP_AUTHORIZATION'] ?? 'no-authorization'; exit; }
if ($uri === '/loop') { header('Location: /loop', true, 302); exit; }
echo 'root';
PHP);

    $port = random_int(18300, 18999);
    $process = proc_open(
        ['php', '-S', "127.0.0.1:{$port}", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    // Wait for the server to accept connections (suppress connect warnings)
    set_error_handler(static fn (): bool => true);
    try {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                break;
            }
            usleep(50000);
        }
    } finally {
        restore_error_handler();
    }

    return [$process, $port, $router];
}

function stopRedirectServer(array $server): void
{
    [$process, , $router] = $server;
    proc_terminate($process);
    proc_close($process);
    @unlink($router);
}

test('redirect from allowlisted host to non-allowlisted target is blocked', function () {
    $server = startRedirectServer();
    [, $port] = $server;

    try {
        $tool = new WebFetch(allowList: ['127.0.0.1'], timeout: 3, ssrfProtection: false);

        expect(fn () => $tool->execute(['url' => "http://127.0.0.1:{$port}/meta"]))
            ->toThrow(RuntimeException::class, 'URL not in allow list');
    } finally {
        stopRedirectServer($server);
    }
});

test('relative redirects are followed and re-validated', function () {
    $server = startRedirectServer();
    [, $port] = $server;

    try {
        $tool = new WebFetch(allowList: ['127.0.0.1'], timeout: 3, ssrfProtection: false);
        $result = $tool->execute(['url' => "http://127.0.0.1:{$port}/a"]);

        expect($result['content'])->toBe('final-content');
        expect($result['url'])->toContain('/b');
    } finally {
        stopRedirectServer($server);
    }
});

test('redirect loops stop after max redirects', function () {
    $server = startRedirectServer();
    [, $port] = $server;

    try {
        $tool = new WebFetch(allowList: ['127.0.0.1'], timeout: 3, ssrfProtection: false);

        expect(fn () => $tool->execute(['url' => "http://127.0.0.1:{$port}/loop"]))
            ->toThrow(RuntimeException::class, 'Too many redirects');
    } finally {
        stopRedirectServer($server);
    }
});

test('non-redirecting requests behave as before', function () {
    $server = startRedirectServer();
    [, $port] = $server;

    try {
        $tool = new WebFetch(allowList: ['127.0.0.1'], timeout: 3, ssrfProtection: false);
        $result = $tool->execute(['url' => "http://127.0.0.1:{$port}/"]);

        expect($result['content'])->toBe('root');
        expect($result['url'])->toBe("http://127.0.0.1:{$port}/");
        expect($result['size'])->toBe(strlen('root'));
    } finally {
        stopRedirectServer($server);
    }
});

test('response size is enforced while bytes are downloaded', function () {
    $server = startRedirectServer();
    [, $port] = $server;

    try {
        $tool = new WebFetch(maxSize: 8, ssrfProtection: false, allowList: ['127.0.0.1']);

        expect(fn () => $tool->execute(['url' => "http://127.0.0.1:{$port}/large"]))
            ->toThrow(RuntimeException::class, 'Response too large');
    } finally {
        stopRedirectServer($server);
    }
});

test('sensitive headers are stripped on cross-origin redirects', function () {
    $first = startRedirectServer();
    $second = startRedirectServer();
    [, $firstPort] = $first;
    [, $secondPort] = $second;

    try {
        $tool = new WebFetch(ssrfProtection: false, allowList: ['127.0.0.1']);
        $result = $tool->execute([
            'url' => "http://127.0.0.1:{$firstPort}/cross?port={$secondPort}",
            'headers' => ['Authorization' => 'Bearer should-not-leak'],
        ]);

        expect($result['content'])->toBe('no-authorization');
    } finally {
        stopRedirectServer($first);
        stopRedirectServer($second);
    }
});
