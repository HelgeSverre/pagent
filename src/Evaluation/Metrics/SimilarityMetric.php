<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use Pagent\Contracts\Metric;

final class SimilarityMetric implements Metric
{
    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        if (null === $expected || ! is_string($expected)) {
            return 0.0;
        }

        if (empty($expected) && empty($output)) {
            return 1.0;
        }

        if (empty($expected) || empty($output)) {
            return 0.0;
        }

        $percent = 0.0;
        similar_text($output, $expected, $percent);

        return $percent / 100.0;
    }

    public function getName(): string
    {
        return 'similarity';
    }

    public function getDescription(): string
    {
        return 'Calculates text similarity between output and expected using similar_text()';
    }
}
