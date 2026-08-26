<?php

declare(strict_types=1);

namespace Pagent\Middleware;

use Pagent\Contracts\Middleware;

use function array_column;
use function array_pop;
use function array_sum;
use function count;
use function microtime;
use function round;
use function time;

final class MetricsMiddleware implements Middleware
{
    private array $metrics = [];

    /**
     * Stack of start times so nested/interleaved rounds are timed correctly.
     *
     * @var array<int, float>
     */
    private array $startTimes = [];

    public function before(string $message, array $options): array
    {
        $this->startTimes[] = microtime(true);

        return $options;
    }

    public function after(object $response): object
    {
        $startTime = array_pop($this->startTimes);

        if ($startTime !== null) {
            $duration = microtime(true) - $startTime;

            $this->metrics[] = [
                'timestamp' => time(),
                'duration_ms' => round($duration * 1000, 2),
                'tokens' => $response->tokens ?? 0,
                'provider' => $response->provider ?? null,
                'model' => $response->model ?? null,
            ];
        }

        return $response;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getAverageDuration(): float
    {
        if (empty($this->metrics)) {
            return 0.0;
        }

        $total = array_sum(array_column($this->metrics, 'duration_ms'));

        return $total / count($this->metrics);
    }

    public function getTotalTokens(): int
    {
        return array_sum(array_column($this->metrics, 'tokens'));
    }
}
