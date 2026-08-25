<?php

declare(strict_types=1);

namespace Pagent\Tool;

use Pagent\Contracts\Tool;

/**
 * Converts provider-neutral tool metadata into provider request payloads.
 *
 * Tools describe themselves once with JSON Schema. Providers own the final
 * protocol shape through this serializer rather than tools carrying provider
 * specific methods.
 */
final class ToolSchemaSerializer
{
    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function anthropic(Tool $tool): array
    {
        return [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'input_schema' => $tool->getInputSchema(),
        ];
    }

    /**
     * @return array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public static function openAI(Tool $tool): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getInputSchema(),
            ],
        ];
    }
}
