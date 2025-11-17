<?php

declare(strict_types=1);

use Pagent\Http\HttpResponse;

test('it creates response with all properties', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: ['content-type' => 'application/json'],
        body: '{"message":"success"}',
        info: ['total_time' => 0.5]
    );

    expect($response->status)->toBe(200)
        ->and($response->headers)->toBe(['content-type' => 'application/json'])
        ->and($response->body)->toBe('{"message":"success"}')
        ->and($response->info)->toBe(['total_time' => 0.5]);
});

test('it parses json response', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '{"name":"John","age":30}'
    );

    $json = $response->json();

    expect($json)->toBe([
        'name' => 'John',
        'age' => 30,
    ]);
});

test('it parses complex nested json', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '{"user":{"name":"Alice","tags":["admin","user"]},"count":5}'
    );

    $json = $response->json();

    expect($json)->toBeArray()
        ->and($json['user'])->toBe(['name' => 'Alice', 'tags' => ['admin', 'user']])
        ->and($json['count'])->toBe(5);
});

test('it throws on invalid json', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '{invalid json}'
    );

    expect(fn () => $response->json())
        ->toThrow(JsonException::class);
});

test('it throws when json decodes to non-array', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '"just a string"'
    );

    expect(fn () => $response->json())
        ->toThrow(UnexpectedValueException::class, 'JSON response did not decode to an array');
});

test('it throws when json decodes to null', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: 'null'
    );

    expect(fn () => $response->json())
        ->toThrow(UnexpectedValueException::class, 'JSON response did not decode to an array');
});

test('it identifies successful responses', function (): void {
    $response200 = new HttpResponse(status: 200, headers: [], body: '');
    $response201 = new HttpResponse(status: 201, headers: [], body: '');
    $response299 = new HttpResponse(status: 299, headers: [], body: '');

    expect($response200->isSuccessful())->toBeTrue()
        ->and($response201->isSuccessful())->toBeTrue()
        ->and($response299->isSuccessful())->toBeTrue();
});

test('it identifies non-successful responses', function (): void {
    $response199 = new HttpResponse(status: 199, headers: [], body: '');
    $response300 = new HttpResponse(status: 300, headers: [], body: '');
    $response400 = new HttpResponse(status: 400, headers: [], body: '');
    $response500 = new HttpResponse(status: 500, headers: [], body: '');

    expect($response199->isSuccessful())->toBeFalse()
        ->and($response300->isSuccessful())->toBeFalse()
        ->and($response400->isSuccessful())->toBeFalse()
        ->and($response500->isSuccessful())->toBeFalse();
});

test('it identifies client errors', function (): void {
    $response400 = new HttpResponse(status: 400, headers: [], body: '');
    $response401 = new HttpResponse(status: 401, headers: [], body: '');
    $response404 = new HttpResponse(status: 404, headers: [], body: '');
    $response499 = new HttpResponse(status: 499, headers: [], body: '');

    expect($response400->isClientError())->toBeTrue()
        ->and($response401->isClientError())->toBeTrue()
        ->and($response404->isClientError())->toBeTrue()
        ->and($response499->isClientError())->toBeTrue();
});

test('it identifies non-client errors', function (): void {
    $response200 = new HttpResponse(status: 200, headers: [], body: '');
    $response399 = new HttpResponse(status: 399, headers: [], body: '');
    $response500 = new HttpResponse(status: 500, headers: [], body: '');

    expect($response200->isClientError())->toBeFalse()
        ->and($response399->isClientError())->toBeFalse()
        ->and($response500->isClientError())->toBeFalse();
});

test('it identifies server errors', function (): void {
    $response500 = new HttpResponse(status: 500, headers: [], body: '');
    $response502 = new HttpResponse(status: 502, headers: [], body: '');
    $response503 = new HttpResponse(status: 503, headers: [], body: '');
    $response599 = new HttpResponse(status: 599, headers: [], body: '');

    expect($response500->isServerError())->toBeTrue()
        ->and($response502->isServerError())->toBeTrue()
        ->and($response503->isServerError())->toBeTrue()
        ->and($response599->isServerError())->toBeTrue();
});

test('it identifies non-server errors', function (): void {
    $response200 = new HttpResponse(status: 200, headers: [], body: '');
    $response400 = new HttpResponse(status: 400, headers: [], body: '');
    $response499 = new HttpResponse(status: 499, headers: [], body: '');

    expect($response200->isServerError())->toBeFalse()
        ->and($response400->isServerError())->toBeFalse()
        ->and($response499->isServerError())->toBeFalse();
});

test('it extracts timing information', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '',
        info: [
            'namelookup_time' => 0.1,
            'connect_time' => 0.2,
            'starttransfer_time' => 0.3,
            'total_time' => 0.5,
        ]
    );

    $timing = $response->timing();

    expect($timing)->toBe([
        'namelookup' => 0.1,
        'connect' => 0.2,
        'starttransfer' => 0.3,
        'total' => 0.5,
    ]);
});

test('it handles missing timing information', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '',
        info: []
    );

    $timing = $response->timing();

    expect($timing)->toBe([
        'namelookup' => 0.0,
        'connect' => 0.0,
        'starttransfer' => 0.0,
        'total' => 0.0,
    ]);
});

test('it handles partial timing information', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '',
        info: [
            'total_time' => 1.5,
        ]
    );

    $timing = $response->timing();

    expect($timing['total'])->toBe(1.5)
        ->and($timing['namelookup'])->toBe(0.0)
        ->and($timing['connect'])->toBe(0.0)
        ->and($timing['starttransfer'])->toBe(0.0);
});

test('it converts numeric string timing values to float', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '',
        info: [
            'total_time' => '1.5',
            'connect_time' => 0.5,
        ]
    );

    $timing = $response->timing();

    expect($timing['total'])->toBe(1.5)
        ->and($timing['connect'])->toBe(0.5);
});

test('it handles non-numeric timing values', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '',
        info: [
            'total_time' => 'invalid',
        ]
    );

    $timing = $response->timing();

    expect($timing['total'])->toBe(0.0);
});

test('it parses empty json object', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '{}'
    );

    $json = $response->json();

    expect($json)->toBe([]);
});

test('it parses empty json array', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: '[]'
    );

    $json = $response->json();

    expect($json)->toBe([]);
});

test('it is readonly', function (): void {
    $response = new HttpResponse(
        status: 200,
        headers: [],
        body: 'test'
    );

    expect(fn () => $response->status = 404)
        ->toThrow(Error::class);
});

test('it handles empty body', function (): void {
    $response = new HttpResponse(
        status: 204,
        headers: [],
        body: ''
    );

    expect($response->body)->toBe('')
        ->and($response->isSuccessful())->toBeTrue();
});

test('it handles various status codes correctly', function () {
    $testCases = [
        ['status' => 100, 'success' => false, 'client' => false, 'server' => false],
        ['status' => 200, 'success' => true, 'client' => false, 'server' => false],
        ['status' => 204, 'success' => true, 'client' => false, 'server' => false],
        ['status' => 301, 'success' => false, 'client' => false, 'server' => false],
        ['status' => 400, 'success' => false, 'client' => true, 'server' => false],
        ['status' => 401, 'success' => false, 'client' => true, 'server' => false],
        ['status' => 403, 'success' => false, 'client' => true, 'server' => false],
        ['status' => 404, 'success' => false, 'client' => true, 'server' => false],
        ['status' => 429, 'success' => false, 'client' => true, 'server' => false],
        ['status' => 500, 'success' => false, 'client' => false, 'server' => true],
        ['status' => 502, 'success' => false, 'client' => false, 'server' => true],
        ['status' => 503, 'success' => false, 'client' => false, 'server' => true],
    ];

    foreach ($testCases as $case) {
        $response = new HttpResponse(
            status: $case['status'],
            headers: [],
            body: ''
        );

        expect($response->isSuccessful())->toBe($case['success'])
            ->and($response->isClientError())->toBe($case['client'])
            ->and($response->isServerError())->toBe($case['server']);
    }
});
