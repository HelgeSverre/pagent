<?php

declare(strict_types=1);

namespace Pagent\Observability\Exporters;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

final class ConsoleExporter implements ExporterInterface
{
    public function __construct(
        private readonly bool $verbose = false
    ) {}

    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        foreach ($spans as $span) {
            $name = $span->getName();
            $duration = ($span->getEndEpochNanos() - $span->getStartEpochNanos()) / 1_000_000_000;

            echo "┌─ Span: {$name}\n";
            echo '│  Duration: '.number_format($duration, 3)."s\n";

            if ($this->verbose) {
                $attributes = $span->getAttributes();
                if ($attributes->count() > 0) {
                    echo "│  Attributes:\n";
                    foreach ($attributes as $key => $value) {
                        echo "│    - {$key}: {$value}\n";
                    }
                }
            }

            echo "└─\n";
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
}
