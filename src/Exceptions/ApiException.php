<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

use Throwable;

use function in_array;

/**
 * A provider API returned an error response. Carries the HTTP status and
 * provider identity so callers can decide on retries without parsing messages.
 */
class ApiException extends RuntimeException implements PagentException
{
    public function __construct(
        string $message,
        public readonly string $provider = 'unknown',
        public readonly ?int $statusCode = null,
        public readonly ?string $errorType = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function isRetryable(): bool
    {
        if ($this->statusCode === null) {
            return false;
        }

        return $this->statusCode >= 500 || in_array($this->statusCode, [408, 429], true);
    }
}
