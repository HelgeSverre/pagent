<?php

declare(strict_types=1);

namespace Pagent\Observability;

use OpenTelemetry\API\Trace\SpanContextInterface as OtelSpanContextInterface;

final readonly class SpanContext
{
    public function __construct(
        private OtelSpanContextInterface $otelContext
    ) {}

    public function getOtelContext(): OtelSpanContextInterface
    {
        return $this->otelContext;
    }

    public function getTraceId(): string
    {
        return $this->otelContext->getTraceId();
    }

    public function getSpanId(): string
    {
        return $this->otelContext->getSpanId();
    }

    public function isValid(): bool
    {
        return $this->otelContext->isValid();
    }
}
