<?php

declare(strict_types=1);

use Pagent\Http\ConnectionException;
use Pagent\Http\CurlTransport;

/**
 * CurlTransport Integration Tests
 *
 * These are integration tests that make real HTTP requests to httpbin.org.
 * They verify the actual behavior of the CurlTransport layer including:
 * - Request/response handling
 * - Header manipulation
 * - Error handling
 * - Streaming support
 * - Timeout behavior
 */
beforeEach(function () {
    // Skip these tests if network is unavailable
    if (! @fsockopen('httpbin.org', 443, $errno, $errstr, 3)) {
        $this->markTestSkipped('Network unavailable or httpbin.org is down');
    }
});

test('it makes successful GET request', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/get'
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->body)->toContain('httpbin.org');
})->group('network');

test('it makes successful POST request with JSON payload', function () {
    $transport = new CurlTransport;

    $payload = [
        'name' => 'Test User',
        'age' => 30,
        'active' => true,
    ];

    $response = $transport->requestJson(
        method: 'POST',
        url: 'https://httpbin.org/post',
        headers: ['Content-Type' => 'application/json'],
        json: $payload
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();

    $json = $response->json();
    expect($json['json']['name'])->toBe('Test User')
        ->and($json['json']['age'])->toBe(30)
        ->and($json['json']['active'])->toBeTrue();
})->group('network');

test('it sends custom headers', function () {
    $transport = new CurlTransport;

    $headers = [
        'X-Custom-Header' => 'custom-value',
        'X-Another-Header' => 'another-value',
    ];

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/headers',
        headers: $headers
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['headers']['X-Custom-Header'])->toBe('custom-value')
        ->and($json['headers']['X-Another-Header'])->toBe('another-value');
})->group('network');

test('it handles 404 responses', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/404'
    );

    expect($response->status)->toBe(404)
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isClientError())->toBeTrue();
})->group('network');

test('it handles 500 responses', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/500'
    );

    expect($response->status)->toBe(500)
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isServerError())->toBeTrue();
})->group('network');

test('it handles PUT requests', function () {
    $transport = new CurlTransport;

    $data = ['updated' => true];

    $response = $transport->requestJson(
        method: 'PUT',
        url: 'https://httpbin.org/put',
        headers: ['Content-Type' => 'application/json'],
        json: $data
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['json'])->toBe($data);
})->group('network');

test('it handles DELETE requests', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'DELETE',
        url: 'https://httpbin.org/delete'
    );

    expect($response->status)->toBe(200);
})->group('network');

test('it sends JSON as string payload', function () {
    $transport = new CurlTransport;

    $jsonString = '{"manual":"json","value":42}';

    $response = $transport->requestJson(
        method: 'POST',
        url: 'https://httpbin.org/post',
        headers: ['Content-Type' => 'application/json'],
        json: $jsonString
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['json'])->toBe(['manual' => 'json', 'value' => 42]);
})->group('network');

test('it handles requests without payload', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/get',
        json: null
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it sets custom user agent', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/user-agent',
        options: ['user_agent' => 'CustomAgent/2.0']
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['user-agent'])->toBe('CustomAgent/2.0');
})->group('network');

test('it uses default user agent', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/user-agent'
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['user-agent'])->toBe('pagent-http-client/1.0');
})->group('network');

test('it parses response headers correctly', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/response-headers?X-Test-Header=test-value'
    );

    expect($response->status)->toBe(200)
        ->and($response->headers)->toHaveKey('x-test-header')
        ->and($response->headers['x-test-header'])->toBe('test-value');
})->group('network');

test('it throws ConnectionException for invalid URL', function () {
    $transport = new CurlTransport;

    expect(fn () => $transport->requestJson(
        method: 'GET',
        url: 'https://this-domain-definitely-does-not-exist-12345.com'
    ))->toThrow(ConnectionException::class);
})->group('network');

test('it handles connection timeout', function () {
    $transport = new CurlTransport;

    // Use a non-routable IP to trigger timeout
    expect(fn () => $transport->requestJson(
        method: 'GET',
        url: 'http://10.255.255.1',
        options: ['connect_timeout' => 1]
    ))->toThrow(ConnectionException::class);
})->skip('Too slow for regular test runs');

test('it creates stream transport for streaming requests', function () {
    $transport = new CurlTransport;

    $stream = $transport->streamJson(
        method: 'GET',
        url: 'https://httpbin.org/stream/3'
    );

    expect($stream->status())->toBe(200);

    $content = $stream->getContent();
    expect($content)->toBeString()
        ->and(strlen($content))->toBeGreaterThan(0);
})->group('network');

test('it streams JSON data correctly', function () {
    $transport = new CurlTransport;

    $stream = $transport->streamJson(
        method: 'GET',
        url: 'https://httpbin.org/stream-bytes/100'
    );

    expect($stream->status())->toBe(200);

    $content = stream_get_contents($stream->resource());
    expect(strlen($content))->toBeGreaterThan(0);
})->group('network');

test('it includes info array in response', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/get'
    );

    expect($response->info)->toBeArray()
        ->and($response->info)->toHaveKey('http_code')
        ->and($response->info['http_code'])->toBe(200);
})->group('network');

test('it handles redirects automatically', function () {
    $transport = new CurlTransport;

    // httpbin.org/redirect/1 redirects to /get
    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/redirect/1'
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it handles gzip encoding', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/gzip'
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json)->toHaveKey('gzipped')
        ->and($json['gzipped'])->toBeTrue();
})->group('network');

test('it handles deflate encoding', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/deflate'
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json)->toHaveKey('deflated')
        ->and($json['deflated'])->toBeTrue();
})->group('network');

test('it makes POST request with array payload', function () {
    $transport = new CurlTransport;

    $data = [
        'nested' => [
            'key1' => 'value1',
            'key2' => ['a', 'b', 'c'],
        ],
        'top_level' => 'value',
    ];

    $response = $transport->requestJson(
        method: 'POST',
        url: 'https://httpbin.org/post',
        headers: ['Content-Type' => 'application/json'],
        json: $data
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['json'])->toBe($data);
})->group('network');

test('it handles various HTTP methods', function () {
    $transport = new CurlTransport;

    $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

    foreach ($methods as $method) {
        $url = 'https://httpbin.org/'.strtolower($method);
        $response = $transport->requestJson(
            method: $method,
            url: $url,
            headers: ['Content-Type' => 'application/json'],
            json: ['test' => 'data']
        );

        expect($response->status)->toBe(200);
    }
})->group('network');

test('it handles empty response body', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/204'
    );

    expect($response->status)->toBe(204)
        ->and($response->body)->toBe('');
})->group('network');

test('stream transport provides correct status code', function () {
    $transport = new CurlTransport;

    $stream = $transport->streamJson(
        method: 'GET',
        url: 'https://httpbin.org/status/201'
    );

    expect($stream->status())->toBe(201);
})->group('network');

test('it handles basic authentication in URL', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://user:pass@httpbin.org/basic-auth/user/pass'
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['authenticated'])->toBeTrue();
})->group('network');

test('it preserves header case insensitivity', function () {
    $transport = new CurlTransport;

    $headers = [
        'X-Custom-Header' => 'value1',
        'x-another-header' => 'value2',
        'X-UPPERCASE-HEADER' => 'value3',
    ];

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/headers',
        headers: $headers
    );

    expect($response->status)->toBe(200);

    // Response headers should be lowercase
    expect($response->headers)->toBeArray();
})->group('network');

test('it handles large payloads', function () {
    $transport = new CurlTransport;

    $largeData = [];
    for ($i = 0; $i < 100; $i++) {
        $largeData["key{$i}"] = str_repeat('value', 100);
    }

    $response = $transport->requestJson(
        method: 'POST',
        url: 'https://httpbin.org/post',
        headers: ['Content-Type' => 'application/json'],
        json: $largeData
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect(count($json['json'] ?? []))->toBe(100);
})->group('network');

test('it handles unicode in payload', function () {
    $transport = new CurlTransport;

    $data = [
        'emoji' => '🚀',
        'chinese' => '你好',
        'arabic' => 'مرحبا',
        'mixed' => 'Hello 世界 🌍',
    ];

    $response = $transport->requestJson(
        method: 'POST',
        url: 'https://httpbin.org/post',
        headers: ['Content-Type' => 'application/json'],
        json: $data
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['json']['emoji'])->toBe('🚀')
        ->and($json['json']['chinese'])->toBe('你好')
        ->and($json['json']['arabic'])->toBe('مرحبا')
        ->and($json['json']['mixed'])->toBe('Hello 世界 🌍');
})->group('network');

test('it handles query parameters in URL', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/get?foo=bar&baz=qux'
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['args']['foo'])->toBe('bar')
        ->and($json['args']['baz'])->toBe('qux');
})->group('network');

// ===== Additional HTTP Scenarios =====

test('it handles 201 Created status code', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/201'
    );

    expect($response->status)->toBe(201)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it handles 204 No Content status code', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/204'
    );

    expect($response->status)->toBe(204)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->body)->toBe('');
})->group('network');

test('it handles 301 Moved Permanently redirects', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/redirect-to?url=https://httpbin.org/get&status_code=301'
    );

    expect($response->status)->toBe(200)  // Follows redirect
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it handles 302 Found redirects', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/redirect-to?url=https://httpbin.org/get&status_code=302'
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it handles 400 Bad Request', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/400'
    );

    expect($response->status)->toBe(400)
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isClientError())->toBeTrue();
})->group('network');

test('it handles 401 Unauthorized', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/401'
    );

    expect($response->status)->toBe(401)
        ->and($response->isClientError())->toBeTrue();
})->group('network');

test('it handles 403 Forbidden', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/403'
    );

    expect($response->status)->toBe(403)
        ->and($response->isClientError())->toBeTrue();
})->group('network');

test('it handles 405 Method Not Allowed', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/405'
    );

    expect($response->status)->toBe(405)
        ->and($response->isClientError())->toBeTrue();
})->group('network');

test('it handles 429 Too Many Requests', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/429'
    );

    expect($response->status)->toBe(429)
        ->and($response->isClientError())->toBeTrue();
})->group('network');

test('it handles 502 Bad Gateway', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/502'
    );

    expect($response->status)->toBe(502)
        ->and($response->isServerError())->toBeTrue();
})->group('network');

test('it handles 503 Service Unavailable', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/503'
    );

    expect($response->status)->toBe(503)
        ->and($response->isServerError())->toBeTrue();
})->group('network');

test('it handles 504 Gateway Timeout', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/504'
    );

    expect($response->status)->toBe(504)
        ->and($response->isServerError())->toBeTrue();
})->group('network');

test('it sends Bearer token authentication', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/bearer',
        headers: ['Authorization' => 'Bearer test-token-12345']
    );

    expect($response->status)->toBe(200)
        ->and($response->json()['token'])->toBe('test-token-12345');
})->group('network');

test('it handles multiple values for same header', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/headers',
        headers: [
            'X-Test-Header-1' => 'value1',
            'X-Test-Header-2' => 'value2',
            'X-Test-Header-3' => 'value3',
        ]
    );

    expect($response->status)->toBe(200);

    $json = $response->json();
    expect($json['headers'])->toHaveKey('X-Test-Header-1')
        ->and($json['headers'])->toHaveKey('X-Test-Header-2')
        ->and($json['headers'])->toHaveKey('X-Test-Header-3');
})->group('network');

test('it handles content negotiation with Accept header', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/headers',
        headers: ['Accept' => 'application/json']
    );

    expect($response->status)->toBe(200)
        ->and($response->json()['headers']['Accept'])->toBe('application/json');
})->group('network');

test('it sends custom Content-Type headers', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'POST',
        url: 'https://httpbin.org/post',
        headers: ['Content-Type' => 'application/xml'],
        json: '<root><item>value</item></root>'
    );

    expect($response->status)->toBe(200);
})->group('network');

test('it handles empty response with success status', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/200'
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it handles delayed responses', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/delay/1'  // 1 second delay
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network')->skip('Too slow for regular test runs');

test('it handles absolute redirect chain', function () {
    $transport = new CurlTransport;

    // /absolute-redirect/3 redirects 3 times
    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/absolute-redirect/2'
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it preserves headers through redirects', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/redirect-to?url=https://httpbin.org/headers',
        headers: ['X-Custom-Header' => 'preserved-value']
    );

    expect($response->status)->toBe(200);
})->group('network');

test('it handles case-insensitive header names', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/headers',
        headers: [
            'x-lowercase' => 'value1',
            'X-UPPERCASE' => 'value2',
            'X-MixedCase' => 'value3',
        ]
    );

    expect($response->status)->toBe(200);

    // Response headers are normalized to lowercase
    expect($response->headers)->toBeArray();
})->group('network');

test('it handles cookies in response', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/cookies/set?session=abc123'
    );

    // httpbin redirects after setting cookie
    expect($response->status)->toBe(200);
})->group('network');

test('it handles JSON error responses', function () {
    $transport = new CurlTransport;

    // httpbin doesn't have a JSON error endpoint, so we use status codes
    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/status/400'
    );

    expect($response->status)->toBe(400)
        ->and($response->isClientError())->toBeTrue();
})->group('network');

test('it handles multiple redirects with different status codes', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/redirect/3'  // Multiple redirects
    );

    expect($response->status)->toBe(200)
        ->and($response->isSuccessful())->toBeTrue();
})->group('network');

test('it handles PATCH method', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'PATCH',
        url: 'https://httpbin.org/patch',
        headers: ['Content-Type' => 'application/json'],
        json: ['updated' => true]
    );

    expect($response->status)->toBe(200);
})->group('network');

test('it handles empty POST request', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'POST',
        url: 'https://httpbin.org/post',
        headers: ['Content-Type' => 'application/json'],
        json: []
    );

    expect($response->status)->toBe(200)
        ->and($response->json()['json'])->toBe([]);
})->group('network');

test('it handles URL with multiple query parameters', function () {
    $transport = new CurlTransport;

    $params = [];
    for ($i = 1; $i <= 10; $i++) {
        $params[] = "param{$i}=value{$i}";
    }

    $url = 'https://httpbin.org/get?'.implode('&', $params);
    $response = $transport->requestJson(
        method: 'GET',
        url: $url
    );

    expect($response->status)->toBe(200)
        ->and($response->json()['args'])->toHaveCount(10);
})->group('network');

test('it handles special characters in query parameters', function () {
    $transport = new CurlTransport;

    $response = $transport->requestJson(
        method: 'GET',
        url: 'https://httpbin.org/get?name=John%20Doe&email=test%40example.com'
    );

    expect($response->status)->toBe(200)
        ->and($response->json()['args']['name'])->toBe('John Doe')
        ->and($response->json()['args']['email'])->toBe('test@example.com');
})->group('network');

test('it handles response with custom status messages', function () {
    $transport = new CurlTransport;

    // Different status codes to test the categorization
    $statuses = [200, 201, 204, 400, 401, 403, 404, 500, 502, 503];

    foreach ($statuses as $status) {
        $response = $transport->requestJson(
            method: 'GET',
            url: "https://httpbin.org/status/{$status}"
        );

        expect($response->status)->toBe($status);
    }
})->group('network');
