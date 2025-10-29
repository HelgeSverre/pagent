<?php

declare(strict_types=1);

namespace Pagent\Evaluation\Metrics;

use const JSON_ERROR_NONE;

use Pagent\Contracts\Metric;
use Swaggest\JsonSchema\Exception\StringException;
use Swaggest\JsonSchema\Schema;

use function count;
use function is_string;
use function json_decode;
use function json_last_error;
use function max;

/**
 * Validates AI-generated JSON output against a JSON Schema.
 *
 * This metric ensures that structured data has the correct shape,
 * required fields, and data types. Uses the swaggest/json-schema library
 * for validation.
 */
final class JsonSchemaMetric implements Metric
{
    private readonly Schema $schema;

    /**
     * @param  string  $name  Metric name (e.g., 'user_profile_schema')
     * @param  array|string  $schema  JSON Schema as array or JSON string
     * @param  bool  $strictMode  If true, any validation error returns 0.0. If false, score based on error count.
     */
    public function __construct(
        private readonly string $name,
        array|string $schema,
        private readonly bool $strictMode = true,
    ) {
        // Convert to JSON object if array
        if (is_array($schema)) {
            $schema = json_decode(json_encode($schema));
        } elseif (is_string($schema)) {
            $schema = json_decode($schema);
        }

        $this->schema = Schema::import($schema);
    }

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        try {
            // Parse the output as JSON
            $data = json_decode($output, false);

            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                // Invalid JSON - cannot validate schema
                return 0.0;
            }

            // Validate against schema
            $this->schema->in($data);

            // Validation passed - return 1.0 (100%)
            return 1.0;

        } catch (StringException $e) {
            // Validation failed
            if ($this->strictMode) {
                // Strict mode: any validation error = 0
                return 0.0;
            }

            // Lenient mode: score based on number of errors
            // Fewer errors = higher score
            $errorCount = count($e->getTrace());

            return max(0.0, 1.0 - ($errorCount * 0.1));
        } catch (\Throwable $e) {
            // Any other error (e.g., malformed JSON, invalid schema)
            return 0.0;
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        $mode = $this->strictMode ? 'strict' : 'lenient';

        return "Validates JSON output against a JSON Schema ({$mode} mode). Returns 1.0 if valid, 0.0 if invalid.";
    }
}
