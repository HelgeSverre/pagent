<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Pagent\Observability\Exporters\InMemoryExporter;
use Pagent\Observability\Exporters\ZipkinExporter;
use Pagent\Observability\TelemetryManager;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

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

    expect($result)->toBeInstanceOf(FutureInterface::class)
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

test('it reports export failures through the composed logger without global output', function () {
    $memory = new InMemoryExporter;
    TelemetryManager::reset();
    TelemetryManager::instance()
        ->initialize([
            'enabled' => true,
            'exporter' => 'inmemory',
            'inmemory' => ['instance' => $memory],
        ]);
    TelemetryManager::instance()->startSpan('zipkin.test')->end();
    $span = $memory->getLastSpan();
    TelemetryManager::reset();

    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new RuntimeException('collector unavailable');
        }
    };
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = [
                'level' => $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };
    $exporter = new ZipkinExporter(client: $client, logger: $logger);

    ob_start();
    try {
        $result = $exporter->export([$span])->await();
    } finally {
        $output = ob_get_clean();
    }

    expect($result)->toBeFalse()
        ->and($output)->toBe('')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('Failed to export spans to Zipkin')
        ->and($logger->records[0]['context']['exception'])->toBeInstanceOf(RuntimeException::class);
});
