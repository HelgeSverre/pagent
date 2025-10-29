<?php

declare(strict_types=1);

namespace Pagent\Observability\Exporters;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;

/**
 * In-memory exporter for testing purposes.
 * Collects spans in memory so they can be inspected in tests.
 */
final class InMemoryExporter implements ExporterInterface
{
    /** @var array<SpanDataInterface> */
    private array $spans = [];

    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        foreach ($spans as $span) {
            $this->spans[] = $span;
        }

        return new CompletedFuture(true);
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
     * Get all collected spans.
     *
     * @return array<SpanDataInterface>
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    /**
     * Find spans by name.
     *
     * @return array<SpanDataInterface>
     */
    public function findSpansByName(string $name): array
    {
        return array_values(array_filter(
            $this->spans,
            fn (SpanDataInterface $span) => $span->getName() === $name
        ));
    }

    /**
     * Find spans by attribute value.
     *
     * @return array<SpanDataInterface>
     */
    public function findSpansByAttribute(string $key, mixed $value): array
    {
        return array_values(array_filter(
            $this->spans,
            fn (SpanDataInterface $span) => $span->getAttributes()->get($key) === $value
        ));
    }

    /**
     * Get the most recent span.
     */
    public function getLastSpan(): ?SpanDataInterface
    {
        return empty($this->spans) ? null : end($this->spans);
    }

    /**
     * Get count of collected spans.
     */
    public function getSpanCount(): int
    {
        return count($this->spans);
    }

    /**
     * Clear all collected spans.
     */
    public function clear(): void
    {
        $this->spans = [];
    }

    /**
     * Check if a span with given name exists.
     */
    public function hasSpanWithName(string $name): bool
    {
        return ! empty($this->findSpansByName($name));
    }

    /**
     * Check if a span with given attribute exists.
     */
    public function hasSpanWithAttribute(string $key, mixed $value): bool
    {
        return ! empty($this->findSpansByAttribute($key, $value));
    }
}
