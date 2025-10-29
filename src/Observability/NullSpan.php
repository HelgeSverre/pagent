<?php

declare(strict_types=1);

namespace Pagent\Observability;

use Throwable;

final class NullSpan
{
    public function setAttribute(string $key, mixed $value): self
    {
        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        return $this;
    }

    public function addEvent(string $name, array $attributes = []): self
    {
        return $this;
    }

    public function recordException(Throwable $exception): self
    {
        return $this;
    }

    public function setStatus(string $code, string $description = ''): self
    {
        return $this;
    }

    public function end(): void
    {
        // No-op
    }

    public function getContext(): NullSpanContext
    {
        return new NullSpanContext;
    }
}
