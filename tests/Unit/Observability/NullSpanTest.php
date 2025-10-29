<?php

declare(strict_types=1);

use Pagent\Observability\NullSpan;
use Pagent\Observability\NullSpanContext;

it('does nothing on setAttribute', function (): void {
    $span = new NullSpan;

    $result = $span->setAttribute('key', 'value');

    expect($result)->toBe($span);
});

it('does nothing on setAttributes', function (): void {
    $span = new NullSpan;

    $result = $span->setAttributes([
        'key1' => 'value1',
        'key2' => 'value2',
    ]);

    expect($result)->toBe($span);
});

it('does nothing on addEvent', function (): void {
    $span = new NullSpan;

    $result = $span->addEvent('event.name', ['key' => 'value']);

    expect($result)->toBe($span);
});

it('does nothing on recordException', function (): void {
    $span = new NullSpan;

    $exception = new Exception('Test exception');

    $result = $span->recordException($exception);

    expect($result)->toBe($span);
});

it('does nothing on setStatus', function (): void {
    $span = new NullSpan;

    $result = $span->setStatus('error', 'Error message');

    expect($result)->toBe($span);
});

it('does nothing on end', function (): void {
    $span = new NullSpan;

    $span->end();

    // Should not throw
    expect(true)->toBeTrue();
});

it('returns null context', function (): void {
    $span = new NullSpan;

    $context = $span->getContext();

    expect($context)->toBeInstanceOf(NullSpanContext::class);
    expect($context->isValid())->toBeFalse();
    expect($context->getTraceId())->toBe('');
    expect($context->getSpanId())->toBe('');
});

it('can be chained', function (): void {
    $span = new NullSpan;

    $span
        ->setAttribute('key', 'value')
        ->setAttributes(['key2' => 'value2'])
        ->addEvent('event')
        ->recordException(new Exception('test'))
        ->setStatus('ok')
        ->end();

    expect(true)->toBeTrue();
});

it('has zero overhead', function (): void {
    $span = new NullSpan;

    $start = microtime(true);

    for ($i = 0; $i < 1000; $i++) {
        $span
            ->setAttribute('key', 'value')
            ->addEvent('event')
            ->end();
    }

    $duration = microtime(true) - $start;

    // Should complete 1000 operations in less than 1ms
    expect($duration)->toBeLessThan(0.001);
});
