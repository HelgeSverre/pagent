<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use Pagent\Contracts\Metric;

use function count;
use function max;
use function min;
use function preg_match_all;

/**
 * Validates presence of code blocks in output.
 *
 * Checks for triple-backtick code blocks (```code```).
 * Optionally filters by programming language.
 * Essential for code generation and technical documentation tasks.
 */
final readonly class HasCodeBlockMetric implements Metric
{
    public function __construct(
        private string $language = '',
        private int $minBlocks = 1,
    ) {}

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        if ($output === '') {
            return 0.0;
        }

        // Pattern to match code blocks
        if ($this->language !== '') {
            // Match specific language: ```language\n...\n```
            $pattern = '/```'.preg_quote($this->language, '/').'[\s\S]*?```/';
        } else {
            // Match any code block: ```...\n...\n```
            $pattern = '/```[\s\S]*?```/';
        }

        preg_match_all($pattern, $output, $matches);
        $blockCount = count($matches[0]);

        if ($blockCount === 0) {
            return 0.0;
        }

        if ($blockCount >= $this->minBlocks) {
            return 1.0;
        }

        // Partial score if some blocks present but not enough
        return max(0.0, min(1.0, $blockCount / $this->minBlocks));
    }

    public function getName(): string
    {
        return $this->language !== ''
            ? "has_code_block_{$this->language}"
            : 'has_code_block';
    }

    public function getDescription(): string
    {
        $lang = $this->language !== '' ? " in {$this->language}" : '';
        $count = $this->minBlocks > 1 ? " (minimum {$this->minBlocks})" : '';

        return "Validates presence of code blocks{$lang}{$count}. Returns 1.0 if found, 0.0 if not.";
    }
}
