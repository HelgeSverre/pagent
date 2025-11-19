<?php

declare(strict_types=1);

use Pagent\Http\StreamTransport;

test('it creates stream transport with all properties', function (): void {
    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'test content');
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: ['content-type' => 'application/json'],
        info: ['total_time' => 0.5]
    );

    expect($transport->status())->toBe(200)
        ->and($transport->headers())->toBe(['content-type' => 'application/json'])
        ->and($transport->info())->toBe(['total_time' => 0.5])
        ->and($transport->resource())->toBe($resource);

    fclose($resource);
});

test('it gets content from stream', function (): void {
    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'Hello, World!');
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: []
    );

    $content = $transport->getContent();

    expect($content)->toBe('Hello, World!');

    fclose($resource);
});

test('it rewinds stream before getting content', function (): void {
    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'Test data');
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: []
    );

    // Read once to move pointer
    $transport->getContent();

    // Read again - should still work due to rewind
    $content = $transport->getContent();

    expect($content)->toBe('Test data');

    fclose($resource);
});

test('it handles empty stream', function (): void {
    $resource = fopen('php://temp', 'r+');

    $transport = new StreamTransport(
        resource: $resource,
        status: 204,
        headers: []
    );

    expect($transport->getContent())->toBe('');

    fclose($resource);
});

test('it handles large content', function (): void {
    $resource = fopen('php://temp', 'r+');
    $largeContent = str_repeat('A', 10000);
    fwrite($resource, $largeContent);
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: []
    );

    expect($transport->getContent())->toBe($largeContent);

    fclose($resource);
});

test('it handles json content', function (): void {
    $resource = fopen('php://temp', 'r+');
    $json = '{"name":"John","age":30}';
    fwrite($resource, $json);
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: ['content-type' => 'application/json']
    );

    $content = $transport->getContent();

    expect($content)->toBe($json)
        ->and(json_decode($content, true))->toBe([
            'name' => 'John',
            'age' => 30,
        ]);

    fclose($resource);
});

test('it handles streaming sse data', function (): void {
    $resource = fopen('php://temp', 'r+');
    $sseData = "data: first event\n\ndata: second event\n\n";
    fwrite($resource, $sseData);
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: ['content-type' => 'text/event-stream']
    );

    expect($transport->getContent())->toBe($sseData);

    fclose($resource);
});

test('it preserves info array', function (): void {
    $resource = fopen('php://temp', 'r+');

    $info = [
        'url' => 'https://api.example.com',
        'content_type' => 'application/json',
        'http_code' => 200,
        'total_time' => 1.5,
        'namelookup_time' => 0.1,
        'connect_time' => 0.2,
    ];

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: [],
        info: $info
    );

    expect($transport->info())->toBe($info);

    fclose($resource);
});

test('it handles various status codes', function (): void {
    $resource = fopen('php://temp', 'r+');

    $testCases = [200, 201, 204, 301, 400, 401, 404, 500, 502, 503];

    foreach ($testCases as $statusCode) {
        $transport = new StreamTransport(
            resource: $resource,
            status: $statusCode,
            headers: []
        );

        expect($transport->status())->toBe($statusCode);
    }

    fclose($resource);
});

test('it handles empty headers', function (): void {
    $resource = fopen('php://temp', 'r+');

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: []
    );

    expect($transport->headers())->toBe([]);

    fclose($resource);
});

test('it handles multiple headers', function (): void {
    $resource = fopen('php://temp', 'r+');

    $headers = [
        'content-type' => 'application/json',
        'content-length' => '1234',
        'x-request-id' => 'abc123',
        'cache-control' => 'no-cache',
    ];

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: $headers
    );

    expect($transport->headers())->toBe($headers);

    fclose($resource);
});

test('it handles binary content', function (): void {
    $resource = fopen('php://temp', 'r+');
    $binaryData = pack('C*', 0, 1, 2, 3, 255);
    fwrite($resource, $binaryData);
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: ['content-type' => 'application/octet-stream']
    );

    expect($transport->getContent())->toBe($binaryData);

    fclose($resource);
});

test('it handles multiline text content', function (): void {
    $resource = fopen('php://temp', 'r+');
    $multiline = "Line 1\nLine 2\nLine 3\n";
    fwrite($resource, $multiline);
    rewind($resource);

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: ['content-type' => 'text/plain']
    );

    expect($transport->getContent())->toBe($multiline);

    fclose($resource);
});

test('it defaults info to empty array when not provided', function (): void {
    $resource = fopen('php://temp', 'r+');

    $transport = new StreamTransport(
        resource: $resource,
        status: 200,
        headers: []
    );

    expect($transport->info())->toBe([]);

    fclose($resource);
});
