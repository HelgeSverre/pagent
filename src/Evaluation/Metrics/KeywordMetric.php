<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use Pagent\Contracts\Metric;

use function count;
use function implode;
use function mb_strtolower;
use function str_contains;

final class KeywordMetric implements Metric
{
    public function __construct(
        private readonly array $keywords,
        private readonly bool $requireAll = false,
    ) {}

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        $found = 0;
        $lowerOutput = mb_strtolower($output);

        foreach ($this->keywords as $keyword) {
            if (str_contains($lowerOutput, mb_strtolower($keyword))) {
                $found++;
            }
        }

        if ($this->requireAll) {
            return count($this->keywords) === $found ? 1.0 : 0.0;
        }

        return count($this->keywords) > 0 ? $found / count($this->keywords) : 0.0;
    }

    public function getName(): string
    {
        return 'keyword_match';
    }

    public function getDescription(): string
    {
        $mode = $this->requireAll ? 'all' : 'any';

        return "Checks if {$mode} keywords are present: " . implode(', ', $this->keywords);
    }
}
