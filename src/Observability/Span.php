<?php

declare(strict_types=1);

namespace Pagent\Observability;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class Span
{
    private const ALLOWED_TYPES = ['array', 'bool', 'float', 'int', 'string', 'null'];

    public function __construct(
        private readonly SpanInterface $span,
        private readonly ?ScopeInterface $scope = null
    ) {}

    public function setAttribute(string $key, mixed $value): self
    {
        if ($key === '') {
            return $this;
        }

        if (! in_array(get_debug_type($value), self::ALLOWED_TYPES, true)) {
            return $this;
        }

        /** @phpstan-ignore argument.type */
        $this->span->setAttribute($key, $value);

        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function addEvent(string $name, array $attributes = []): self
    {
        $this->span->addEvent($name, $attributes);

        return $this;
    }

    public function recordException(Throwable $exception): self
    {
        $this->span->recordException($exception);

        return $this;
    }

    public function setStatus(string $code, string $description = ''): self
    {
        $statusCode = $code === 'ok' ? StatusCode::STATUS_OK : StatusCode::STATUS_ERROR;

        $this->span->setStatus($statusCode, $description);

        return $this;
    }

    public function end(): void
    {
        $this->span->end();

        // Detach context scope to restore previous context
        if ($this->scope !== null) {
            $this->scope->detach();
        }
    }

    public function getContext(): SpanContext
    {
        return new SpanContext($this->span->getContext());
    }
}
