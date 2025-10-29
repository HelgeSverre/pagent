<?php

declare(strict_types=1);

use Pagent\Tools\DataExtract;

test('data extract has correct metadata', function () {
    $tool = new DataExtract;

    expect($tool->name())->toBe('data_extract');
    expect($tool->description())->toContain('Extract structured data');
    expect($tool->parameters())->toHaveKey('properties');
});

test('data extract throws when text missing', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute(['schema' => ['type' => 'object', 'properties' => []]]))
        ->toThrow(RuntimeException::class, 'Text parameter is required');
});

test('data extract throws when schema missing', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute(['text' => 'test']))
        ->toThrow(RuntimeException::class, 'Schema parameter is required');
});

test('data extract throws for invalid schema', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute([
        'text' => 'test',
        'schema' => ['invalid' => 'schema'],
    ]))->toThrow(RuntimeException::class, 'Schema must have');
});

// ========================================
// COMPREHENSIVE SCHEMA VALIDATION TESTS
// ========================================

test('it accepts schema with nested properties', function () {
    $tool = new DataExtract;
    $schema = [
        'type' => 'object',
        'properties' => [
            'user' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'age' => ['type' => 'integer'],
                ],
            ],
        ],
    ];

    // Schema validation should pass (execution will fail without real API key)
    try {
        $tool->execute([
            'text' => 'John Doe is 30 years old',
            'schema' => $schema,
        ]);
    } catch (RuntimeException $e) {
        // Expected to fail on OpenAI call, not schema validation
        expect($e->getMessage())->not->toContain('Schema must have');
    }
});

test('it rejects schema missing type field', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute([
        'text' => 'test',
        'schema' => ['properties' => ['name' => ['type' => 'string']]],
    ]))->toThrow(RuntimeException::class, 'Schema must have');
});

test('it rejects schema missing properties field', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute([
        'text' => 'test',
        'schema' => ['type' => 'object'],
    ]))->toThrow(RuntimeException::class, 'Schema must have');
});

test('it uses default instructions when not provided', function () {
    $tool = new DataExtract;

    // Should use default instructions without throwing
    try {
        $tool->execute([
            'text' => 'Test content',
            'schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'string']]],
        ]);
    } catch (RuntimeException $e) {
        // Expected to fail on OpenAI call, not on missing instructions
        expect($e->getMessage())->not->toContain('instructions');
    }
});

test('it accepts custom instructions parameter', function () {
    $tool = new DataExtract;

    // Should accept instructions without throwing
    try {
        $tool->execute([
            'text' => 'Test content',
            'schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'string']]],
            'instructions' => 'Extract only phone numbers',
        ]);
    } catch (RuntimeException $e) {
        // Expected to fail on OpenAI call, not parameter validation
        expect($e->getMessage())->not->toContain('parameter');
    }
});

test('it has configurable model', function () {
    $tool = new DataExtract(model: 'gpt-4o');

    // Verify constructor accepts model parameter
    expect($tool)->toBeInstanceOf(DataExtract::class);
});

// ========================================
// JSON PARSING & PROVIDER INTEGRATION (DOCUMENTED)
// ========================================

test('it handles malformed JSON responses', function () {
    $mockProvider = new class implements \Pagent\Contracts\Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => 'invalid{json}]',
                'model' => 'mock',
                'tokens' => 50,
                'provider' => 'mock',
            ];
        }
    };

    $tool = new DataExtract($mockProvider);

    expect(fn () => $tool->execute([
        'text' => 'John Doe is 30 years old',
        'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ]))->toThrow(RuntimeException::class, 'Failed to parse extracted data');
});

test('it returns parsed data on valid JSON', function () {
    $mockProvider = new class implements \Pagent\Contracts\Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => '{"name":"John Doe","age":30}',
                'model' => 'mock',
                'tokens' => 50,
                'provider' => 'mock',
            ];
        }
    };

    $tool = new DataExtract($mockProvider);

    $result = $tool->execute([
        'text' => 'John Doe is 30 years old',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ],
    ]);

    expect($result)->toHaveKey('data');
    expect($result)->toHaveKey('schema');
    expect($result['data'])->toBe(['name' => 'John Doe', 'age' => 30]);
});
