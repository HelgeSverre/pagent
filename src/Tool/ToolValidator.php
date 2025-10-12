<?php

declare(strict_types=1);

namespace Pagent\Tool;

use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function range;

final class ToolValidator
{
    public static function validate(Tool $tool, array $arguments): void
    {
        $isAssociative = self::isAssociativeArray($arguments);
        $providedCount = count($arguments);

        $requiredArgs = array_filter(
            $tool->arguments,
            fn($arg) => ! $arg->nullable && $arg->default === null,
        );

        if ($isAssociative) {
            foreach ($requiredArgs as $requiredArg) {
                if (! array_key_exists($requiredArg->name, $arguments)) {
                    throw new RuntimeException(
                        "Tool '{$tool->name}' missing required argument: {$requiredArg->name}",
                    );
                }
            }
        } else {
            if ($providedCount < count($requiredArgs)) {
                throw new RuntimeException(
                    "Tool '{$tool->name}' requires " . count($requiredArgs)
                    . " arguments, {$providedCount} provided",
                );
            }
        }

        foreach ($tool->arguments as $i => $expectedArg) {
            $value = null;
            $hasValue = false;

            if ($isAssociative) {
                if (array_key_exists($expectedArg->name, $arguments)) {
                    $value = $arguments[$expectedArg->name];
                    $hasValue = true;
                }
            } else {
                if (isset($arguments[$i])) {
                    $value = $arguments[$i];
                    $hasValue = true;
                }
            }

            if (! $hasValue) {
                if (! $expectedArg->nullable && $expectedArg->default === null) {
                    throw new RuntimeException(
                        "Tool '{$tool->name}' missing required argument: {$expectedArg->name}",
                    );
                }

                continue;
            }

            self::validateType($tool->name, $expectedArg->name, $expectedArg->type, $value);
        }
    }

    private static function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
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
