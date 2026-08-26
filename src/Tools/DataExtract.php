<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Contracts\Provider;
use Pagent\Exceptions\ConfigurationException;
use Pagent\Exceptions\RuntimeException;
use Pagent\Providers\OpenAI;

/**
 * Extract structured data from text via an LLM with JSON-schema output.
 *
 * NOTE: When no provider is passed, this tool defaults to the OpenAI provider
 * with the 'gpt-4o-mini' model, which requires an OPENAI_API_KEY. Apps using
 * other providers (e.g. Anthropic-only setups) must pass a Provider explicitly;
 * the provider must support OpenAI-style 'response_format' structured output.
 */
final class DataExtract extends Tool
{
    private Provider $provider;

    public function __construct(
        ?Provider $provider = null,
        private string $model = 'gpt-4o-mini',
    ) {
        if ($provider === null && ! self::hasOpenAiKey()) {
            throw new ConfigurationException(
                'DataExtract defaults to the OpenAI provider (gpt-4o-mini), but no OPENAI_API_KEY '
                .'is configured. Either set OPENAI_API_KEY or pass a Provider explicitly: '
                .'new DataExtract(provider: $yourProvider, model: ...).'
            );
        }

        $this->provider = $provider ?? new OpenAI;
    }

    private static function hasOpenAiKey(): bool
    {
        $key = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');

        return is_string($key) && $key !== '';
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

        // Call the provider with structured output
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

        // Parse response via its typed shape (Response exposes a public string $content)
        $content = $response->content ?? null;
        if (! is_string($content)) {
            throw new RuntimeException('Provider response did not contain string content');
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse extracted data: '.json_last_error_msg());
        }

        return [
            'data' => $data,
            'schema' => $schema,
        ];
    }
}
