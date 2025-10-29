<?php

declare(strict_types=1);

namespace Pagent\Observability\Exporters;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

final class JaegerExporter implements ExporterInterface
{
    private readonly OTLPExporter $exporter;

    public function __construct(array $config = [])
    {
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318/v1/traces';
        $serviceName = $config['service_name'] ?? 'pagent';

        // Jaeger supports OTLP natively (1.35+), so we use OTLP exporter
        $this->exporter = new OTLPExporter([
            'endpoint' => $endpoint,
            'headers' => $config['headers'] ?? [],
            'compression' => $config['compression'] ?? null,
        ]);
    }

    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return $this->exporter->export($spans, $cancellation);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->forceFlush($cancellation);
    }
}
