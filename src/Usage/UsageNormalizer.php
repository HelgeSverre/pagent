<?php

declare(strict_types=1);

namespace Pagent\Usage;

/**
 * Normalizes provider token counters at the provider boundary while retaining
 * the original fields for callers that need provider-specific metadata.
 */
final class UsageNormalizer
{
    /**
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        $hasInput = array_key_exists('input_tokens', $usage) || array_key_exists('prompt_tokens', $usage);
        $hasOutput = array_key_exists('output_tokens', $usage) || array_key_exists('completion_tokens', $usage);
        $input = self::integer($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null);
        $output = self::integer($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null);

        if ($hasInput) {
            $usage['input_tokens'] = $input ?? 0;
        }
        if ($hasOutput) {
            $usage['output_tokens'] = $output ?? 0;
        }
        if (array_key_exists('total_tokens', $usage)) {
            $usage['total_tokens'] = self::integer($usage['total_tokens']) ?? 0;
        } elseif ($hasInput || $hasOutput) {
            $usage['total_tokens'] = ($input ?? 0) + ($output ?? 0);
        }

        $promptDetails = is_array($usage['prompt_tokens_details'] ?? null)
            ? $usage['prompt_tokens_details']
            : [];
        $cached = self::integer(
            $usage['cache_read_input_tokens']
                ?? $usage['cached_input_tokens']
                ?? $promptDetails['cached_tokens']
                ?? null,
        );
        if ($cached !== null) {
            $usage['cache_read_input_tokens'] = $cached;
        }

        return $usage;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
