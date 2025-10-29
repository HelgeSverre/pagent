<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use const JSON_ERROR_NONE;

use Pagent\Contracts\Metric;

use function json_decode;
use function json_last_error;

/**
 * Validates that the output is parseable JSON.
 *
 * This is a foundational metric for structured data validation.
 * Use this before JsonSchemaMetric to ensure JSON is parseable.
 */
final class JsonValidMetric implements Metric
{
    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        if ($output === '') {
            return 0.0;
        }

        json_decode($output);

        return json_last_error() === JSON_ERROR_NONE ? 1.0 : 0.0;
    }

    public function getName(): string
    {
        return 'json_valid';
    }

    public function getDescription(): string
    {
        return 'Validates that output is parseable JSON. Returns 1.0 if valid, 0.0 if invalid.';
    }
}
