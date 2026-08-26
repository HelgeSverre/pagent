<?php

declare(strict_types=1);

namespace Pagent\Observability\Exporters;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Psr18Client;
use Throwable;

final class ZipkinExporter implements ExporterInterface
{
    private readonly ClientInterface $client;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly string $endpoint;

    private readonly string $serviceName;

    private readonly LoggerInterface $logger;

    public function __construct(
        array $config = [],
        ?ClientInterface $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->endpoint = $config['endpoint'] ?? 'http://localhost:9411/api/v2/spans';
        $this->serviceName = $config['service_name'] ?? 'pagent';

        $psr18Client = new Psr18Client;
        $this->client = $client ?? $psr18Client;
        $this->requestFactory = $psr18Client;
        $this->streamFactory = $psr18Client;
        $this->logger = $logger ?? new NullLogger;
    }

    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $zipkinSpans = [];

        foreach ($spans as $span) {
            $zipkinSpans[] = $this->convertToZipkinFormat($span);
        }

        if (empty($zipkinSpans)) {
            return new CompletedFuture(true);
        }

        try {
            $json = json_encode($zipkinSpans, JSON_THROW_ON_ERROR);
            $request = $this->requestFactory->createRequest('POST', $this->endpoint)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));

            $response = $this->client->sendRequest($request);

            $success = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;

            return new CompletedFuture($success);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to export spans to Zipkin', [
                'exception' => $e,
            ]);

            return new CompletedFuture(false);
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function convertToZipkinFormat(SpanDataInterface $span): array
    {
        $traceId = $span->getContext()->getTraceId();
        $spanId = $span->getContext()->getSpanId();
        $parentSpanId = $span->getParentContext()->getSpanId();

        // Convert nanoseconds to microseconds for Zipkin
        $timestamp = (int) ($span->getStartEpochNanos() / 1000);
        $duration = (int) (($span->getEndEpochNanos() - $span->getStartEpochNanos()) / 1000);

        $zipkinSpan = [
            'traceId' => $traceId,
            'id' => $spanId,
            'name' => $span->getName(),
            'timestamp' => $timestamp,
            'duration' => $duration,
            'localEndpoint' => [
                'serviceName' => $this->serviceName,
            ],
        ];

        if ($parentSpanId !== '') {
            $zipkinSpan['parentId'] = $parentSpanId;
        }

        // Convert attributes to tags
        $tags = [];
        foreach ($span->getAttributes() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $tags[(string) $key] = (string) $value;
            }
        }

        if (! empty($tags)) {
            $zipkinSpan['tags'] = $tags;
        }

        // Set kind if available
        $kindValue = match ($span->getKind()) {
            1 => 'CLIENT',
            2 => 'SERVER',
            3 => 'PRODUCER',
            4 => 'CONSUMER',
            default => null,
        };

        if ($kindValue !== null) {
            $zipkinSpan['kind'] = $kindValue;
        }

        return $zipkinSpan;
    }
}
