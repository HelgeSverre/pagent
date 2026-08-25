<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Contracts\Provider;
use Pagent\Providers\OpenAI;
use RuntimeException;

final class DataExtract extends Tool
{
    private Provider $provider;

    public function __construct(
        ?Provider $provider = null,
        private string $model = 'gpt-4o-mini',
    ) {
        $this->provider = $provider ?? new OpenAI;
    }

    public function name(): string
    {
        return 'data_extract';
    }

    public function description(): string
    {
        return 'Extract structured data from text using a JSON schema. Returns parsed data matching the schema.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'description' => 'Text to extract data from',
                ],
                'schema' => [
                    'type' => 'object',
                    'description' => 'JSON Schema defining the structure to extract',
                ],
                'instructions' => [
                    'type' => 'string',
                    'description' => 'Optional additional instructions for extraction',
                ],
            ],
            'required' => ['text', 'schema'],
        ];
    }

    public function execute(array $params): mixed
    {
        $text = $this->requiredString($params, 'text');
        $schema = $this->requiredArray($params, 'schema');
        $instructions = $this->optionalString($params, 'instructions', 'Extract the requested data from the text.');

        // Validate schema
        if (! is_string($schema['type'] ?? null) || ! is_array($schema['properties'] ?? null)) {
            throw new RuntimeException('Schema must have "type" and "properties" fields');
        }

        // Build prompt
        $prompt = $instructions."\n\nText to analyze:\n".$text;

        // Call OpenAI with structured output
        $response = $this->provider->prompt($prompt, [
            'model' => $this->model,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'data_extraction',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ]);

        // Parse response
        $data = json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse extracted data: '.json_last_error_msg());
        }

        return [
            'data' => $data,
            'schema' => $schema,
        ];
    }
}
