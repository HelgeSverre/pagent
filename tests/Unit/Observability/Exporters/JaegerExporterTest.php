<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Pagent\Observability\Exporters\JaegerExporter;

test('it initializes with Jaeger defaults', function () {
    $exporter = new JaegerExporter;

    expect($exporter)->toBeInstanceOf(JaegerExporter::class);
});

test('it accepts custom endpoint', function () {
    $exporter = new JaegerExporter([
        'endpoint' => 'http://jaeger:4318/v1/traces',
    ]);

    expect($exporter)->toBeInstanceOf(JaegerExporter::class);
});

test('it accepts service name configuration', function () {
    $exporter = new JaegerExporter([
        'service_name' => 'my-service',
    ]);

    expect($exporter)->toBeInstanceOf(JaegerExporter::class);
});

test('it handles empty span export', function () {
    $exporter = new JaegerExporter([
        'endpoint' => 'http://localhost:4318/v1/traces',
    ]);

    $result = $exporter->export([]);

    expect($result)->toBeInstanceOf(FutureInterface::class);
});

test('it can be shut down', function () {
    $exporter = new JaegerExporter;

    $result = $exporter->shutdown();

    expect($result)->toBeTrue();
});

test('it can force flush', function () {
    $exporter = new JaegerExporter;

    $result = $exporter->forceFlush();

    expect($result)->toBeTrue();
});

test('it accepts custom headers for authentication', function () {
    $exporter = new JaegerExporter([
        'endpoint' => 'http://localhost:4318/v1/traces',
        'headers' => [
            'Authorization' => 'Bearer token',
        ],
    ]);

    expect($exporter)->toBeInstanceOf(JaegerExporter::class);
});
