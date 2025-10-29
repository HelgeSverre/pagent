<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\JsonSchemaMetric;

test('valid JSON against schema returns 1.0', function (): void {
    $schema = [
        'type' => 'object',
        'required' => ['name', 'age'],
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ],
    ];

    $metric = new JsonSchemaMetric('user_schema', $schema);

    expect($metric->calculate('', '{"name": "John", "age": 30}', null))->toBe(1.0);
});

test('missing required field returns 0.0 in strict mode', function (): void {
    $schema = [
        'type' => 'object',
        'required' => ['name', 'age'],
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ],
    ];

    $metric = new JsonSchemaMetric('user_schema', $schema, strictMode: true);

    expect($metric->calculate('', '{"name": "John"}', null))->toBe(0.0);
});

test('wrong type returns 0.0', function (): void {
    $schema = [
        'type' => 'object',
        'required' => ['age'],
        'properties' => [
            'age' => ['type' => 'integer'],
        ],
    ];

    $metric = new JsonSchemaMetric('user_schema', $schema);

    expect($metric->calculate('', '{"age": "thirty"}', null))->toBe(0.0);
});

test('invalid JSON returns 0.0', function (): void {
    $schema = ['type' => 'object'];

    $metric = new JsonSchemaMetric('user_schema', $schema);

    expect($metric->calculate('', '{invalid json}', null))->toBe(0.0);
});

test('lenient mode scores based on error count', function (): void {
    $schema = [
        'type' => 'object',
        'required' => ['name', 'age', 'email'],
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'email' => ['type' => 'string'],
        ],
    ];

    $metric = new JsonSchemaMetric('user_schema', $schema, strictMode: false);

    // Missing 2 fields = 2 errors = score should be less than 1.0 but greater than 0
    $score = $metric->calculate('', '{"name": "John"}', null);
    expect($score)->toBeGreaterThanOrEqual(0.0)
        ->and($score)->toBeLessThan(1.0);
});

test('accepts schema as JSON string', function (): void {
    $schemaJson = json_encode([
        'type' => 'object',
        'required' => ['name'],
        'properties' => [
            'name' => ['type' => 'string'],
        ],
    ]);

    $metric = new JsonSchemaMetric('user_schema', $schemaJson);

    expect($metric->calculate('', '{"name": "John"}', null))->toBe(1.0);
});

test('has correct name and description', function (): void {
    $schema = ['type' => 'object'];

    $metric = new JsonSchemaMetric('test_schema', $schema);

    expect($metric->getName())->toBe('test_schema')
        ->and($metric->getDescription())->toContain('JSON Schema');
});
