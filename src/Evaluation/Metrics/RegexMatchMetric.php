<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use Pagent\Contracts\Metric;

use function preg_match;

/**
 * Generic pattern matching metric using regular expressions.
 *
 * This is a power tool that can validate emails, phone numbers, dates,
 * UUIDs, or any custom pattern. Supports both positive matching
 * (pattern should be present) and inverse matching (pattern should NOT be present).
 */
final readonly class RegexMatchMetric implements Metric
{
    public function __construct(
        private string $pattern,
        private string $name,
        private bool $inverse = false,
        private bool $fullMatch = false,
    ) {}

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        if ($output === '') {
            return $this->inverse ? 1.0 : 0.0;
        }

        // For full match, wrap pattern to match entire string
        $pattern = $this->fullMatch
            ? '/^'.trim($this->pattern, '/').'$/'
            : $this->pattern;

        $matches = preg_match($pattern, $output);

        // If inverse mode, flip the result
        if ($this->inverse) {
            return $matches ? 0.0 : 1.0;
        }

        return $matches ? 1.0 : 0.0;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        $mode = $this->inverse ? 'does NOT match' : 'matches';
        $matchType = $this->fullMatch ? 'full match' : 'contains match';

        return "Validates that output {$mode} pattern '{$this->pattern}' ({$matchType})";
    }
}
