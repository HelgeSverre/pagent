<?php

declare(strict_types=1);

use Pagent\Observability\Exporters\OTLPExporter;

test('it initializes with default config', function () {
    $exporter = new OTLPExporter;

    expect($exporter)->toBeInstanceOf(OTLPExporter::class);
});

test('it accepts custom endpoint', function () {
    $exporter = new OTLPExporter([
        'endpoint' => 'http://custom:4318/v1/traces',
    ]);

    expect($exporter)->toBeInstanceOf(OTLPExporter::class);
});

test('it accepts custom headers', function () {
    $exporter = new OTLPExporter([
        'endpoint' => 'http://localhost:4318/v1/traces',
        'headers' => [
            'Authorization' => 'Bearer token123',
            'X-Custom-Header' => 'value',
        ],
    ]);

    expect($exporter)->toBeInstanceOf(OTLPExporter::class);
});

test('it accepts compression setting', function () {
    $exporter = new OTLPExporter([
        'endpoint' => 'http://localhost:4318/v1/traces',
        'compression' => 'gzip',
    ]);

    expect($exporter)->toBeInstanceOf(OTLPExporter::class);
});

test('it handles empty span export', function () {
    $exporter = new OTLPExporter([
        'endpoint' => 'http://localhost:4318/v1/traces',
    ]);

    $result = $exporter->export([]);

    expect($result)->toBeInstanceOf(\OpenTelemetry\SDK\Common\Future\FutureInterface::class);
});

test('it can be shut down', function () {
    $exporter = new OTLPExporter;

    $result = $exporter->shutdown();

    expect($result)->toBeTrue();
});

test('it can force flush', function () {
    $exporter = new OTLPExporter;

    $result = $exporter->forceFlush();

    expect($result)->toBeTrue();
});
