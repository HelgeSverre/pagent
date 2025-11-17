<?php

declare(strict_types=1);

namespace Pagent\Observability\Exporters;

use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

final class OTLPExporter implements ExporterInterface
{
    private readonly OtlpSpanExporter $exporter;

    public function __construct(array $config = [])
    {
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318/v1/traces';
        $headers = $config['headers'] ?? [];
        $compression = $config['compression'] ?? null;
        $timeout = $config['timeout'] ?? 10.0;
        $contentType = $config['content_type'] ?? 'application/x-protobuf';

        $transportFactory = new OtlpHttpTransportFactory;
        $transport = $transportFactory->create(
            $endpoint,
            $contentType,
            $headers,
            $compression,
            $timeout
        );

        $this->exporter = new OtlpSpanExporter($transport);
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
