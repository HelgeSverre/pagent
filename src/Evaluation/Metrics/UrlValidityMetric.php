<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use const FILTER_VALIDATE_URL;

use Pagent\Contracts\Metric;

use function count;
use function filter_var;
use function preg_match_all;

/**
 * Validates URL format correctness in output.
 *
 * Extracts all URLs from the output and validates their format.
 * Returns a score based on the percentage of valid URLs.
 * If no URLs are found, returns 1.0 (no broken links).
 */
final readonly class UrlValidityMetric implements Metric
{
    public function __construct(
        private bool $checkReachable = false,
    ) {}

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        if ($output === '') {
            return 1.0;
        }

        // Extract all URL-like patterns (including potentially broken ones)
        // Match: protocol://domain where protocol is alphanumeric
        preg_match_all('#\b[a-z]+://[^\s<>"\']+#i', $output, $matches);

        if (empty($matches[0])) {
            // No URLs found = no broken links
            return 1.0;
        }

        $validCount = 0;
        $totalUrls = count($matches[0]);

        foreach ($matches[0] as $url) {
            // Remove trailing punctuation that's likely not part of URL
            $url = rtrim($url, '.,;:!?)');

            // Validate URL format AND protocol (must be http/https)
            if (filter_var($url, FILTER_VALIDATE_URL) !== false
                && preg_match('#^https?://#i', $url)) {
                $validCount++;
            }
        }

        return $totalUrls > 0 ? $validCount / $totalUrls : 1.0;
    }

    public function getName(): string
    {
        return 'url_validity';
    }

    public function getDescription(): string
    {
        $check = $this->checkReachable ? ' (format only)' : '';

        return "Validates URL format correctness{$check}. Returns percentage of valid URLs (1.0 if no URLs found).";
    }
}
