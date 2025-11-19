<?php

declare(strict_types=1);

use Pagent\Http\ConnectionException;

test('it is a RuntimeException', function (): void {
    $exception = new ConnectionException('Connection failed');

    expect($exception)->toBeInstanceOf(RuntimeException::class);
});

test('it creates exception with message', function (): void {
    $exception = new ConnectionException('Connection timeout');

    expect($exception->getMessage())->toBe('Connection timeout');
});

test('it creates exception with message and code', function (): void {
    $exception = new ConnectionException('Connection refused', 500);

    expect($exception->getMessage())->toBe('Connection refused')
        ->and($exception->getCode())->toBe(500);
});

test('it creates exception with message code and previous', function (): void {
    $previous = new RuntimeException('Network error');
    $exception = new ConnectionException('Connection failed', 0, $previous);

    expect($exception->getMessage())->toBe('Connection failed')
        ->and($exception->getPrevious())->toBe($previous);
});

test('it can be thrown and caught', function (): void {
    expect(fn () => throw new ConnectionException('Test exception'))
        ->toThrow(ConnectionException::class, 'Test exception');
});

test('it preserves exception chain', function (): void {
    $root = new RuntimeException('Root cause');
    $middle = new RuntimeException('Middle error', 0, $root);
    $top = new ConnectionException('Connection error', 0, $middle);

    expect($top->getPrevious())->toBe($middle)
        ->and($top->getPrevious()?->getPrevious())->toBe($root);
});

test('it handles empty message', function (): void {
    $exception = new ConnectionException('');

    expect($exception->getMessage())->toBe('');
});

test('it handles long descriptive messages', function (): void {
    $message = 'Unable to establish connection to https://api.example.com: '.
        'cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received';

    $exception = new ConnectionException($message);

    expect($exception->getMessage())->toBe($message);
});

test('it can be caught as RuntimeException', function (): void {
    try {
        throw new ConnectionException('Test');
    } catch (RuntimeException $e) {
        expect($e)->toBeInstanceOf(ConnectionException::class);

        return;
    }

    throw new Exception('Exception was not caught');
});

test('it can be caught as Exception', function (): void {
    try {
        throw new ConnectionException('Test');
    } catch (Exception $e) {
        expect($e)->toBeInstanceOf(ConnectionException::class);

        return;
    }

    throw new Exception('Exception was not caught');
});
