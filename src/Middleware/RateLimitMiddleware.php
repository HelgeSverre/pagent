<?php

declare(strict_types=1);

namespace Pagent\Middleware;

use Pagent\Contracts\Middleware;
use RuntimeException;

final class RateLimitMiddleware implements Middleware
{
    private array $requests = [];

    public function __construct(
        private readonly int $maxRequests = 100,
        private readonly int $windowSeconds = 3600,
    ) {}

    public function before(string $message, array $options): array
    {
        $this->pruneOldRequests();

        if (count($this->requests) >= $this->maxRequests) {
            $oldestRequest = min($this->requests);
            $waitSeconds = ($oldestRequest + $this->windowSeconds) - time();

            throw new RuntimeException(
                "Rate limit exceeded. Try again in {$waitSeconds} seconds. "
                . "Limit: {$this->maxRequests} requests per {$this->windowSeconds} seconds.",
            );
        }

        $this->requests[] = time();

        return $options;
    }

    public function after(object $response): object
    {
        return $response;
    }

    public function getRemainingRequests(): int
    {
        $this->pruneOldRequests();

        return max(0, $this->maxRequests - count($this->requests));
    }

    private function pruneOldRequests(): void
    {
        $cutoff = time() - $this->windowSeconds;
        $this->requests = array_filter($this->requests, fn($timestamp) => $timestamp > $cutoff);
    }
}
