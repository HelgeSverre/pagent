<?php

declare(strict_types=1);

use Pagent\Observability\Exporters\ConsoleExporter;
use Pagent\Observability\TelemetryManager;

afterEach(function (): void {
    TelemetryManager::reset();
});

it('exports spans to console', function (): void {
    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
        'verbose' => false,
    ]);

    ob_start();
    try {
        $span = TelemetryManager::instance()->startSpan('test.span');
        $span->end();
        TelemetryManager::instance()->shutdown();
    } finally {
        $output = ob_get_clean();
    }

    expect($output)->toContain('┌─ Span: test.span')
        ->and($output)->toContain('│  Duration:')
        ->and($output)->toContain('└─');
});

it('shows attributes in verbose mode', function (): void {
    TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'console',
        'verbose' => true,
    ]);

    ob_start();
    try {
        $span = TelemetryManager::instance()->startSpan('test.span', [
            'test.attribute' => 'test.value',
            'test.number' => 42,
        ]);
        $span->end();
        TelemetryManager::instance()->shutdown();
    } finally {
        $output = ob_get_clean();
    }

    expect($output)->toContain('test.attribute: test.value')
        ->and($output)->toContain('test.number: 42');
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

    ob_start();
    try {
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
    } finally {
        $output = ob_get_clean();
    }

    expect(substr_count($output, '┌─ Span:'))->toBe(3)
        ->and($output)->toContain('Span: span1')
        ->and($output)->toContain('Span: span2')
        ->and($output)->toContain('Span: span3');
});
