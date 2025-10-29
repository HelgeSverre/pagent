<?php

declare(strict_types=1);

namespace Pagent\Observability;

final readonly class NullSpanContext
{
    public function getTraceId(): string
    {
        return '';
    }

    public function getSpanId(): string
    {
        return '';
    }

    public function isValid(): bool
    {
        return false;
    }
}
