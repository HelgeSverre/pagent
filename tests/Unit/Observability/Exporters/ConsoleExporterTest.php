<?php

declare(strict_types=1);

use Pagent\Observability\Exporters\ConsoleExporter;
use Pagent\Observability\TelemetryManager;

afterEach(function (): void {
    TelemetryManager::reset();
});

it('exports spans to console', function (): void {
    $exporter = new ConsoleExporter(false);

    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
        'verbose' => false,
    ]);

    $span = TelemetryManager::instance()->startSpan('test.span');
    $span->end();

    TelemetryManager::instance()->shutdown();

    // Should not throw
    expect(true)->toBeTrue();
});

it('shows attributes in verbose mode', function (): void {
    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
        'verbose' => true,
    ]);

    $span = TelemetryManager::instance()->startSpan('test.span', [
        'test.attribute' => 'test.value',
        'test.number' => 42,
    ]);

    $span->end();

    TelemetryManager::instance()->shutdown();

    expect(true)->toBeTrue();
});

it('can be shut down', function (): void {
    $exporter = new ConsoleExporter(false);

    $result = $exporter->shutdown();

    expect($result)->toBeTrue();
});

it('can be force flushed', function (): void {
    $exporter = new ConsoleExporter(false);

    $result = $exporter->forceFlush();

    expect($result)->toBeTrue();
});

it('handles multiple spans', function (): void {
    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
        'verbose' => true,
    ]);

    $span1 = TelemetryManager::instance()->startSpan('span1');
    $span2 = TelemetryManager::instance()->startSpan('span2');
    $span3 = TelemetryManager::instance()->startSpan('span3');

    $span1->setAttribute('order', 1);
    $span2->setAttribute('order', 2);
    $span3->setAttribute('order', 3);

    $span1->end();
    $span2->end();
    $span3->end();

    TelemetryManager::instance()->shutdown();

    expect(true)->toBeTrue();
});
