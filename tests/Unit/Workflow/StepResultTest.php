<?php

declare(strict_types=1);

use Pagent\Workflow\StepMetadata;
use Pagent\Workflow\StepResult;

/**
 * StepResult Edge Case Tests
 *
 * Tests edge cases for JSON parsing, array conversion, and data access.
 */
test('it creates step result with basic data', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'test_step',
        output: 'test output',
        input: 'test input',
        agent: 'test_agent',
        meta: $meta
    );

    expect($result->name)->toBe('test_step')
        ->and($result->output)->toBe('test output')
        ->and($result->input)->toBe('test input')
        ->and($result->agent)->toBe('test_agent')
        ->and($result->meta)->toBe($meta);
});

test('json() parses valid JSON string', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{"name":"John","age":30}',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe(['name' => 'John', 'age' => 30]);
});

test('json() handles invalid JSON string', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{invalid json',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() handles non-JSON string', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: 'just a plain string',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() returns array output as-is', function () {
    $meta = StepMetadata::create(100, 1.5);
    $data = ['key' => 'value', 'nested' => ['a' => 1]];
    $result = new StepResult(
        name: 'step',
        output: $data,
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe($data);
});

test('json() handles integer output', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: 42,
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() handles boolean output', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: true,
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() handles null output', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: null,
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() handles object output', function () {
    $meta = StepMetadata::create(100, 1.5);
    $obj = new stdClass;
    $obj->key = 'value';

    $result = new StepResult(
        name: 'step',
        output: $obj,
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() handles JSON that decodes to non-array', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '"just a string"',  // Valid JSON but not an object
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() handles empty JSON object', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{}',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('json() handles empty array output', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: [],
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json)->toBe([]);
});

test('get() retrieves existing key', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{"name":"Alice","age":25}',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    expect($result->get('name'))->toBe('Alice')
        ->and($result->get('age'))->toBe(25);
});

test('get() returns default for missing key', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{"name":"Alice"}',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    expect($result->get('missing'))->toBeNull()
        ->and($result->get('missing', 'default'))->toBe('default');
});

test('get() returns default for non-JSON output', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: 'plain text',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    expect($result->get('any_key'))->toBeNull()
        ->and($result->get('any_key', 'fallback'))->toBe('fallback');
});

test('get() works with array output', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: ['status' => 'success', 'code' => 200],
        input: '',
        agent: 'agent',
        meta: $meta
    );

    expect($result->get('status'))->toBe('success')
        ->and($result->get('code'))->toBe(200)
        ->and($result->get('missing', 'default'))->toBe('default');
});

test('toArray() converts to array structure', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'test_step',
        output: 'output data',
        input: 'input data',
        agent: 'test_agent',
        meta: $meta
    );

    $array = $result->toArray();

    expect($array)->toBeArray()
        ->and($array['name'])->toBe('test_step')
        ->and($array['output'])->toBe('output data')
        ->and($array['input'])->toBe('input data')
        ->and($array['agent'])->toBe('test_agent')
        ->and($array['metadata'])->toBeArray()
        ->and($array['metadata']['tokens'])->toBe(100)
        ->and($array['metadata']['duration'])->toBe(1.5);
});

test('toArray() handles complex nested output', function () {
    $meta = StepMetadata::create(200, 2.5);
    $complexOutput = [
        'users' => [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ],
        'metadata' => ['count' => 2],
    ];

    $result = new StepResult(
        name: 'step',
        output: $complexOutput,
        input: 'fetch users',
        agent: 'data_agent',
        meta: $meta
    );

    $array = $result->toArray();

    expect($array['output'])->toBe($complexOutput)
        ->and($array['output']['users'])->toHaveCount(2)
        ->and($array['output']['metadata']['count'])->toBe(2);
});

test('toArray() handles null values', function () {
    $meta = StepMetadata::create(0, 0.0);
    $result = new StepResult(
        name: 'step',
        output: null,
        input: null,
        agent: 'agent',
        meta: $meta
    );

    $array = $result->toArray();

    expect($array['output'])->toBeNull()
        ->and($array['input'])->toBeNull();
});

test('json() handles nested JSON structures', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{"user":{"name":"Alice","settings":{"theme":"dark"}}}',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json['user']['name'])->toBe('Alice')
        ->and($json['user']['settings']['theme'])->toBe('dark');
});

test('json() handles JSON with special characters', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{"message":"Hello \"World\"","newline":"line1\\nline2"}',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json['message'])->toBe('Hello "World"')
        ->and($json['newline'])->toBe("line1\nline2");
});

test('json() handles JSON with unicode characters', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: '{"emoji":"🚀","chinese":"你好"}',
        input: '',
        agent: 'agent',
        meta: $meta
    );

    $json = $result->json();

    expect($json['emoji'])->toBe('🚀')
        ->and($json['chinese'])->toBe('你好');
});

test('get() handles deeply nested keys', function () {
    $meta = StepMetadata::create(100, 1.5);
    $result = new StepResult(
        name: 'step',
        output: ['level1' => ['level2' => ['level3' => 'deep value']]],
        input: '',
        agent: 'agent',
        meta: $meta
    );

    // get() only does one level, not deep access
    expect($result->get('level1'))->toBe(['level2' => ['level3' => 'deep value']])
        ->and($result->get('nonexistent'))->toBeNull();
});
