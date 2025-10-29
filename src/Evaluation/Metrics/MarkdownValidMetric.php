<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use Pagent\Contracts\Metric;

use function preg_match;

/**
 * Validates Markdown document structure and formatting.
 *
 * Checks for proper Markdown elements like headers, lists, code blocks,
 * and well-formed links. Useful for documentation generation and
 * content quality assessment.
 */
final readonly class MarkdownValidMetric implements Metric
{
    public function __construct(
        private bool $requireHeaders = false,
        private bool $requireLists = false,
        private bool $requireCodeBlocks = false,
    ) {}

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        if ($output === '') {
            return 0.0;
        }

        // Check for headers (# syntax)
        $hasHeaders = preg_match('/^#{1,6}\s+.+$/m', $output);
        if ($this->requireHeaders && ! $hasHeaders) {
            return 0.0;
        }

        // Check for lists (- * + or numbered)
        $hasLists = preg_match('/^[\*\-\+]\s+/m', $output) || preg_match('/^\d+\.\s+/m', $output);
        if ($this->requireLists && ! $hasLists) {
            return 0.0;
        }

        // Check for code blocks
        $hasCodeBlocks = preg_match('/```[\s\S]*?```/', $output) || preg_match('/^    .+$/m', $output);
        if ($this->requireCodeBlocks && ! $hasCodeBlocks) {
            return 0.0;
        }

        // If all requirements are set and we passed them, return 1.0
        if ($this->requireHeaders || $this->requireLists || $this->requireCodeBlocks) {
            return 1.0;
        }

        // Otherwise, score based on optional checks
        $score = 0.0;
        $checks = 0;

        $checks++;
        if ($hasHeaders) {
            $score += 1.0;
        }

        $checks++;
        if ($hasLists) {
            $score += 1.0;
        }

        $checks++;
        if ($hasCodeBlocks) {
            $score += 1.0;
        }

        // Check for well-formed links [text](url)
        $checks++;
        $hasBrokenLinks = preg_match('/\[.+?\]\(\s+|\[.+?\]$/', $output);
        if (! $hasBrokenLinks) {
            $score += 1.0;
        }

        return $checks > 0 ? $score / $checks : 0.0;
    }

    public function getName(): string
    {
        return 'markdown_valid';
    }

    public function getDescription(): string
    {
        $requirements = [];
        if ($this->requireHeaders) {
            $requirements[] = 'headers';
        }
        if ($this->requireLists) {
            $requirements[] = 'lists';
        }
        if ($this->requireCodeBlocks) {
            $requirements[] = 'code blocks';
        }

        if (! empty($requirements)) {
            return 'Validates Markdown structure (requires: '.implode(', ', $requirements).')';
        }

        return 'Validates Markdown structure (checks headers, lists, code blocks, and links)';
    }
}
