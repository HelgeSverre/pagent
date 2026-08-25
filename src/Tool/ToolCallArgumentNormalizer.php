<?php

declare(strict_types=1);

namespace Pagent\Tool;

use RuntimeException;

/**
 * Normalizes provider tool-call arguments at every response boundary.
 *
 * Keeping this strict and shared prevents one provider from silently turning
 * malformed model output into an empty tool invocation while another throws.
 */
final class ToolCallArgumentNormalizer
{
    /** @return array<string, mixed> */
    public static function normalize(mixed $arguments, string $subject = 'Tool call'): array
    {
        if ($arguments === null) {
            return [];
        }

        if (is_array($arguments)) {
            return $arguments;
        }

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            throw new RuntimeException(sprintf(
                '%s arguments must be a valid JSON object: %s',
                $subject,
                json_last_error() === JSON_ERROR_NONE
                    ? 'decoded value is not an object'
                    : json_last_error_msg(),
            ));
        }

        throw new RuntimeException(sprintf(
            '%s arguments must be an object or JSON object string, got %s',
            $subject,
            get_debug_type($arguments),
        ));
    }
}
