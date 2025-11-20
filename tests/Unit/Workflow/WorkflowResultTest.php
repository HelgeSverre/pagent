<?php

declare(strict_types=1);

use Pagent\Workflow\Metadata;
use Pagent\Workflow\StepMetadata;
use Pagent\Workflow\StepResult;
use Pagent\Workflow\WorkflowResult;

/**
 * WorkflowResult Edge Case Tests
 *
 * Tests edge cases for step access, JSON parsing, and array conversion.
 */
test('it creates workflow result with basic data', function () {
    $meta = Metadata::create(100, 1.5, 1);
    $result = new WorkflowResult(
        final: 'final output',
        steps: [],
        meta: $meta
    );

    expect($result->final)->toBe('final output')
        ->and($result->steps)->toBe([])
        ->and($result->meta)->toBe($meta);
});

test('step() retrieves step by name', function () {
    $stepMeta = StepMetadata::create(50, 0.5);
    $step1 = new StepResult('step1', 'output1', 'input1', 'agent1', $stepMeta);
    $step2 = new StepResult('step2', 'output2', 'input2', 'agent2', $stepMeta);

    $meta = Metadata::create(100, 1.0, 2);
    $result = new WorkflowResult(
        final: 'done',
        steps: [$step1, $step2],
        meta: $meta
    );

    expect($result->step('step1'))->toBe($step1)
        ->and($result->step('step2'))->toBe($step2);
});

test('step() returns null for non-existent step', function () {
    $stepMeta = StepMetadata::create(50, 0.5);
    $step = new StepResult('existing_step', 'output', 'input', 'agent', $stepMeta);

    $meta = Metadata::create(50, 0.5, 1);
    $result = new WorkflowResult(
        final: 'done',
        steps: [$step],
        meta: $meta
    );

    expect($result->step('nonexistent'))->toBeNull();
});

test('step() returns null for empty steps', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: 'done',
        steps: [],
        meta: $meta
    );

    expect($result->step('any_name'))->toBeNull();
});

test('has() checks step existence', function () {
    $stepMeta = StepMetadata::create(50, 0.5);
    $step1 = new StepResult('step1', 'output1', 'input1', 'agent1', $stepMeta);
    $step2 = new StepResult('step2', 'output2', 'input2', 'agent2', $stepMeta);

    $meta = Metadata::create(100, 1.0, 2);
    $result = new WorkflowResult(
        final: 'done',
        steps: [$step1, $step2],
        meta: $meta
    );

    expect($result->has('step1'))->toBeTrue()
        ->and($result->has('step2'))->toBeTrue()
        ->and($result->has('step3'))->toBeFalse()
        ->and($result->has('nonexistent'))->toBeFalse();
});

test('has() returns false for empty steps', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: 'done',
        steps: [],
        meta: $meta
    );

    expect($result->has('any'))->toBeFalse();
});

test('get() retrieves key from JSON final', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: '{"status":"success","code":200}',
        steps: [],
        meta: $meta
    );

    expect($result->get('status'))->toBe('success')
        ->and($result->get('code'))->toBe(200);
});

test('get() returns default for missing key in JSON final', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: '{"status":"success"}',
        steps: [],
        meta: $meta
    );

    expect($result->get('missing'))->toBeNull()
        ->and($result->get('missing', 'default'))->toBe('default');
});

test('get() handles invalid JSON in final', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: '{invalid json',
        steps: [],
        meta: $meta
    );

    expect($result->get('any_key'))->toBeNull()
        ->and($result->get('any_key', 'fallback'))->toBe('fallback');
});

test('get() works with array final', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: ['result' => 'success', 'count' => 5],
        steps: [],
        meta: $meta
    );

    expect($result->get('result'))->toBe('success')
        ->and($result->get('count'))->toBe(5)
        ->and($result->get('missing', 'default'))->toBe('default');
});

test('get() returns default for non-string non-array final', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: 42,  // Integer
        steps: [],
        meta: $meta
    );

    expect($result->get('any_key'))->toBeNull()
        ->and($result->get('any_key', 'default'))->toBe('default');
});

test('get() handles null final', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: null,
        steps: [],
        meta: $meta
    );

    expect($result->get('key'))->toBeNull()
        ->and($result->get('key', 'fallback'))->toBe('fallback');
});

test('get() handles boolean final', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: true,
        steps: [],
        meta: $meta
    );

    expect($result->get('key'))->toBeNull()
        ->and($result->get('key', 'default'))->toBe('default');
});

test('get() handles object final', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $obj = new stdClass;
    $obj->key = 'value';

    $result = new WorkflowResult(
        final: $obj,
        steps: [],
        meta: $meta
    );

    expect($result->get('key'))->toBeNull()
        ->and($result->get('key', 'default'))->toBe('default');
});

test('toArray() converts to array structure', function () {
    $stepMeta = StepMetadata::create(50, 0.5);
    $step = new StepResult('step1', 'output', 'input', 'agent', $stepMeta);

    $meta = Metadata::create(50, 0.5, 1);
    $result = new WorkflowResult(
        final: 'final output',
        steps: [$step],
        meta: $meta
    );

    $array = $result->toArray();

    expect($array)->toBeArray()
        ->and($array['final'])->toBe('final output')
        ->and($array['steps'])->toBeArray()
        ->and($array['steps'])->toHaveCount(1)
        ->and($array['steps'][0]['name'])->toBe('step1')
        ->and($array['metadata'])->toBeArray()
        ->and($array['metadata']['total_tokens'])->toBe(50);
});

test('toArray() handles empty steps', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: 'done',
        steps: [],
        meta: $meta
    );

    $array = $result->toArray();

    expect($array['steps'])->toBe([])
        ->and($array['final'])->toBe('done');
});

test('toArray() handles multiple steps', function () {
    $stepMeta = StepMetadata::create(50, 0.5);
    $steps = [
        new StepResult('step1', 'out1', 'in1', 'agent1', $stepMeta),
        new StepResult('step2', 'out2', 'in2', 'agent2', $stepMeta),
        new StepResult('step3', 'out3', 'in3', 'agent3', $stepMeta),
    ];

    $meta = Metadata::create(150, 1.5, 3);
    $result = new WorkflowResult(
        final: 'completed',
        steps: $steps,
        meta: $meta
    );

    $array = $result->toArray();

    expect($array['steps'])->toHaveCount(3)
        ->and($array['steps'][0]['name'])->toBe('step1')
        ->and($array['steps'][1]['name'])->toBe('step2')
        ->and($array['steps'][2]['name'])->toBe('step3');
});

test('export() is alias for toArray()', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: 'done',
        steps: [],
        meta: $meta
    );

    expect($result->export())->toBe($result->toArray());
});

test('step() handles duplicate step names returns first match', function () {
    $stepMeta = StepMetadata::create(50, 0.5);
    $step1 = new StepResult('duplicate', 'first', 'input1', 'agent1', $stepMeta);
    $step2 = new StepResult('duplicate', 'second', 'input2', 'agent2', $stepMeta);

    $meta = Metadata::create(100, 1.0, 2);
    $result = new WorkflowResult(
        final: 'done',
        steps: [$step1, $step2],
        meta: $meta
    );

    $found = $result->step('duplicate');

    expect($found)->toBe($step1)  // Returns first match
        ->and($found->output)->toBe('first');
});

test('get() handles empty JSON object', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: '{}',
        steps: [],
        meta: $meta
    );

    expect($result->get('any'))->toBeNull()
        ->and($result->get('any', 'default'))->toBe('default');
});

test('get() handles empty array final', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: [],
        steps: [],
        meta: $meta
    );

    expect($result->get('any'))->toBeNull()
        ->and($result->get('any', 'default'))->toBe('default');
});

test('get() handles JSON with nested structures', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: '{"data":{"users":[{"id":1}]}}',
        steps: [],
        meta: $meta
    );

    // get() only does one level
    $data = $result->get('data');
    expect($data)->toBeArray()
        ->and($data['users'])->toBeArray()
        ->and($data['users'][0]['id'])->toBe(1);
});

test('get() handles JSON that decodes to non-array', function () {
    $meta = Metadata::create(0, 0.0, 0);
    $result = new WorkflowResult(
        final: '"just a string"',  // Valid JSON but not an object
        steps: [],
        meta: $meta
    );

    expect($result->get('any'))->toBeNull()
        ->and($result->get('any', 'default'))->toBe('default');
});

test('get() handles unicode in JSON final', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $result = new WorkflowResult(
        final: '{"emoji":"🚀","chinese":"你好"}',
        steps: [],
        meta: $meta
    );

    expect($result->get('emoji'))->toBe('🚀')
        ->and($result->get('chinese'))->toBe('你好');
});

test('toArray() handles complex nested final output', function () {
    $meta = Metadata::create(100, 1.0, 1);
    $complexFinal = [
        'results' => [
            ['id' => 1, 'status' => 'success'],
            ['id' => 2, 'status' => 'pending'],
        ],
        'summary' => ['total' => 2, 'success' => 1],
    ];

    $result = new WorkflowResult(
        final: $complexFinal,
        steps: [],
        meta: $meta
    );

    $array = $result->toArray();

    expect($array['final'])->toBe($complexFinal)
        ->and($array['final']['results'])->toHaveCount(2)
        ->and($array['final']['summary']['total'])->toBe(2);
});
