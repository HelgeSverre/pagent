<?php

declare(strict_types=1);

namespace Pagent\Tool;

use Pagent\Exceptions\RuntimeException;

use function array_key_exists;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

final class ToolValidator
{
    /**
     * Validate named arguments against the tool's declared parameters.
     *
     * Provider tool calls are always named JSON objects, so arguments are
     * validated by parameter name only.
     */
    public static function validate(Tool $tool, array $arguments): void
    {
        foreach ($tool->arguments as $expectedArg) {
            if (! array_key_exists($expectedArg->name, $arguments)) {
                if (! $expectedArg->nullable && $expectedArg->default === null) {
                    throw new RuntimeException(
                        "Tool '{$tool->name}' missing required argument: {$expectedArg->name}",
                    );
                }

                continue;
            }

            self::validateType($tool->name, $expectedArg->name, $expectedArg->type, $arguments[$expectedArg->name]);
        }
    }

    private static function validateType(string $toolName, string $argName, string $expectedType, mixed $value): void
    {
        $actualType = get_debug_type($value);

        $valid = match ($expectedType) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => true,
        };

        if (! $valid) {
            throw new RuntimeException(
                "Tool '{$toolName}' argument '{$argName}' expects {$expectedType}, got {$actualType}",
            );
        }
    }
}
