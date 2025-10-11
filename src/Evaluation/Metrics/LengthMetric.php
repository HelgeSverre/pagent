<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use Pagent\Contracts\Metric;

final class LengthMetric implements Metric
{
    public function __construct(
        private readonly int $minLength = 0,
        private readonly int $maxLength = PHP_INT_MAX,
    ) {}

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        $length = mb_strlen($output);

        if ($length < $this->minLength || $length > $this->maxLength) {
            return 0.0;
        }

        if (PHP_INT_MAX === $this->maxLength) {
            return 1.0;
        }

        $range = $this->maxLength - $this->minLength;
        $position = $length - $this->minLength;

        return $range > 0 ? min(1.0, $position / $range) : 1.0;
    }

    public function getName(): string
    {
        return 'length_check';
    }

    public function getDescription(): string
    {
        if (PHP_INT_MAX === $this->maxLength) {
            return "Checks if output is at least {$this->minLength} characters";
        }

        return "Checks if output is between {$this->minLength} and {$this->maxLength} characters";
    }
}
