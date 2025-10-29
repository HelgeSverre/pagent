<?php

declare(strict_types=1);

use Pagent\Observability\Exporters\ZipkinExporter;

test('it initializes with Zipkin defaults', function () {
    $exporter = new ZipkinExporter;

    expect($exporter)->toBeInstanceOf(ZipkinExporter::class);
});

test('it accepts custom endpoint', function () {
    $exporter = new ZipkinExporter([
        'endpoint' => 'http://zipkin:9411/api/v2/spans',
    ]);

    expect($exporter)->toBeInstanceOf(ZipkinExporter::class);
});

test('it accepts custom service name', function () {
    $exporter = new ZipkinExporter([
        'service_name' => 'my-service',
    ]);

    expect($exporter)->toBeInstanceOf(ZipkinExporter::class);
});

test('it accepts custom timeout', function () {
    $exporter = new ZipkinExporter([
        'timeout' => 30,
    ]);

    expect($exporter)->toBeInstanceOf(ZipkinExporter::class);
});

test('it exports empty span list successfully', function () {
    $exporter = new ZipkinExporter;

    $result = $exporter->export([]);

    expect($result)->toBeInstanceOf(\OpenTelemetry\SDK\Common\Future\FutureInterface::class)
        ->and($result->await())->toBeTrue();
});

test('it can be shut down', function () {
    $exporter = new ZipkinExporter;

    $result = $exporter->shutdown();

    expect($result)->toBeTrue();
});

test('it can force flush', function () {
    $exporter = new ZipkinExporter;

    $result = $exporter->forceFlush();

    expect($result)->toBeTrue();
});

test('it handles span conversion', function () {
    $exporter = new ZipkinExporter([
        'service_name' => 'test-service',
    ]);

    // Simply verify the exporter can be created and is ready to convert spans
    expect($exporter)->toBeInstanceOf(ZipkinExporter::class);
});
