<?php

declare(strict_types=1);

use Pagent\Observability\SpanContext;
use Pagent\Observability\TelemetryManager;

beforeEach(function (): void {
    TelemetryManager::reset();
    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'inmemory',
    ]);
});

afterEach(function (): void {
    TelemetryManager::reset();
});

it('sets single attribute', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $result = $span->setAttribute('test.key', 'test.value');

    expect($result)->toBe($span);

    $span->end();
});

it('sets multiple attributes', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $result = $span->setAttributes([
        'key1' => 'value1',
        'key2' => 'value2',
        'key3' => 123,
    ]);

    expect($result)->toBe($span);

    $span->end();
});

it('adds events', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $result = $span->addEvent('test.event', [
        'event.key' => 'event.value',
    ]);

    expect($result)->toBe($span);

    $span->end();
});

it('records exceptions', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $exception = new Exception('Test exception');

    $result = $span->recordException($exception);

    expect($result)->toBe($span);

    $span->end();
});

it('sets status to ok', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $result = $span->setStatus('ok');

    expect($result)->toBe($span);

    $span->end();
});

it('sets status to error', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $result = $span->setStatus('error', 'Something went wrong');

    expect($result)->toBe($span);

    $span->end();
});

it('provides context for propagation', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $context = $span->getContext();

    expect($context)->toBeInstanceOf(SpanContext::class);
    expect($context->isValid())->toBeTrue();
    expect($context->getTraceId())->toBeString()->not->toBeEmpty();
    expect($context->getSpanId())->toBeString()->not->toBeEmpty();

    $span->end();
});

it('ends properly', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $span->end();

    // Should not throw
    expect(true)->toBeTrue();
});

it('supports method chaining', function (): void {
    $span = TelemetryManager::instance()->startSpan('test.span');

    $span
        ->setAttribute('key1', 'value1')
        ->setAttributes(['key2' => 'value2'])
        ->addEvent('event1')
        ->setStatus('ok')
        ->end();

    expect(true)->toBeTrue();
});
