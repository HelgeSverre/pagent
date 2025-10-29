<?php

declare(strict_types=1);

use Pagent\Observability\NullSpan;
use Pagent\Observability\Span;
use Pagent\Observability\TelemetryManager;

beforeEach(function (): void {
    TelemetryManager::reset();
});

afterEach(function (): void {
    TelemetryManager::reset();
});

it('returns singleton instance', function (): void {
    $instance1 = TelemetryManager::instance();
    $instance2 = TelemetryManager::instance();

    expect($instance1)->toBe($instance2);
});

it('initializes with default config', function (): void {
    $manager = TelemetryManager::instance()->initialize([]);

    expect($manager->isEnabled())->toBeTrue();
});

it('can be disabled', function (): void {
    $manager = TelemetryManager::instance()->initialize(['enabled' => false]);

    expect($manager->isEnabled())->toBeFalse();
});

it('returns NullSpan when disabled', function (): void {
    $manager = TelemetryManager::instance()->initialize(['enabled' => false]);

    $span = $manager->startSpan('test.span');

    expect($span)->toBeInstanceOf(NullSpan::class);
});

it('returns real Span when enabled', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $span = $manager->startSpan('test.span');

    expect($span)->toBeInstanceOf(Span::class);
});

it('creates agent spans with correct attributes', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $span = $manager->startAgentSpan('prompt', 'assistant', [
        'custom.attribute' => 'value',
    ]);

    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('creates LLM spans with semantic attributes', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $span = $manager->startLLMSpan('anthropic', 'claude-sonnet-4-20250514', [
        'gen_ai.request.temperature' => 0.7,
        'gen_ai.request.max_tokens' => 1024,
    ]);

    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('creates tool spans with correct attributes', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $span = $manager->startToolSpan('calculator', ['a' => 5, 'b' => 3]);

    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('propagates context to child spans', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $parentSpan = $manager->startSpan('parent.span');
    $childSpan = $manager->startSpan('child.span');

    $parentContext = $parentSpan->getContext();
    $childContext = $childSpan->getContext();

    expect($parentContext->getTraceId())->toBe($childContext->getTraceId());
    expect($parentContext->getSpanId())->not->toBe($childContext->getSpanId());

    $childSpan->end();
    $parentSpan->end();
});

it('clears context', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $span = $manager->startSpan('test.span');
    $manager->clearContext();

    // Should not throw
    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('shuts down gracefully', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    expect($manager->isEnabled())->toBeTrue();

    $manager->shutdown();

    expect($manager->isEnabled())->toBeFalse();
});

it('handles multiple spans concurrently', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $span1 = $manager->startSpan('span1');
    $span2 = $manager->startSpan('span2');
    $span3 = $manager->startSpan('span3');

    expect($span1)->toBeInstanceOf(Span::class);
    expect($span2)->toBeInstanceOf(Span::class);
    expect($span3)->toBeInstanceOf(Span::class);

    $span3->end();
    $span2->end();
    $span1->end();
});

it('can be reinitialized after shutdown', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    $manager->shutdown();

    $manager->initialize([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    expect($manager->isEnabled())->toBeTrue();

    $span = $manager->startSpan('test.span');
    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('initializes with OTLP exporter', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'otlp',
        'otlp' => [
            'endpoint' => 'http://localhost:4318/v1/traces',
        ],
    ]);

    expect($manager->isEnabled())->toBeTrue();

    $span = $manager->startSpan('test.span');
    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('initializes with Jaeger exporter', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'jaeger',
        'jaeger' => [
            'endpoint' => 'http://localhost:4318/v1/traces',
            'service_name' => 'pagent',
        ],
    ]);

    expect($manager->isEnabled())->toBeTrue();

    $span = $manager->startSpan('test.span');
    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('initializes with Zipkin exporter', function (): void {
    $manager = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'zipkin',
        'zipkin' => [
            'endpoint' => 'http://localhost:9411/api/v2/spans',
            'service_name' => 'pagent',
        ],
    ]);

    expect($manager->isEnabled())->toBeTrue();

    $span = $manager->startSpan('test.span');
    expect($span)->toBeInstanceOf(Span::class);

    $span->end();
});

it('throws exception for unknown exporter', function (): void {
    expect(fn () => TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'unknown',
    ]))->toThrow(InvalidArgumentException::class, 'Unknown exporter: unknown');
});
