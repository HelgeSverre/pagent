<?php

declare(strict_types=1);

use Pagent\Contracts\Provider;
use Pagent\Workflow\Pipeline;

test('pipeline executes named steps', function () {
    $step1 = \mock(['Input' => 'Step 1 output']);
    $step2 = \mock(['Step 1 output' => 'Step 2 output']);
    $step3 = \mock(['Step 2 output' => 'Final output']);

    $result = Pipeline::create()
        ->step('extract', $step1)
        ->step('validate', $step2)
        ->step('process', $step3)
        ->run('Input');

    expect($result->final)->toBe('Final output');
    expect($result->steps)->toHaveCount(3);
    expect($result->step('extract')->output)->toBe('Step 1 output');
    expect($result->step('validate')->output)->toBe('Step 2 output');
    expect($result->step('process')->output)->toBe('Final output');
});

test('pipeline can access intermediate results by name', function () {
    $extractor = \mock(['Invoice text' => '{"number": "123", "amount": 500}']);
    $validator = \mock(['{"number": "123", "amount": 500}' => '{"valid": true}']);

    $result = Pipeline::create()
        ->step('extract', $extractor)
        ->step('validate', $validator)
        ->run('Invoice text');

    expect($result->has('extract'))->toBeTrue();
    expect($result->has('validate'))->toBeTrue();
    expect($result->has('nonexistent'))->toBeFalse();

    $extractStep = $result->step('extract');
    expect($extractStep->name)->toBe('extract');
    expect($extractStep->output)->toBe('{"number": "123", "amount": 500}');
});

test('pipeline supports transform steps', function () {
    $extractor = \mock(['Text' => '{"value": "100"}']);

    $result = Pipeline::create()
        ->step('extract', $extractor)
        ->transform('parse', fn ($json) => json_decode($json, true))
        ->transform('double', fn ($data) => ['value' => $data['value'] * 2])
        ->run('Text');

    expect($result->step('extract')->output)->toBe('{"value": "100"}');
    expect($result->step('parse')->output)->toBe(['value' => '100']);
    expect($result->step('double')->output)->toBe(['value' => 200]);
    expect($result->final)->toBe(['value' => 200]);
});

test('pipeline preserves the value each transform received', function () {
    $result = Pipeline::create()
        ->transform('first', fn (string $value) => strtoupper($value))
        ->transform('second', fn (string $value) => $value.'!')
        ->run('hello');

    expect($result->step('first')->input)->toBe('hello')
        ->and($result->step('first')->output)->toBe('HELLO')
        ->and($result->step('second')->input)->toBe('HELLO')
        ->and($result->step('second')->output)->toBe('HELLO!');
});

test('pipeline aggregates usage arrays returned by providers', function () {
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => 'done',
                'usage' => ['total_tokens' => 42],
            ];
        }
    };

    $result = Pipeline::create()->step('provider', $provider)->run('input');

    expect($result->meta->totalTokens)->toBe(42)
        ->and($result->step('provider')->meta->tokens)->toBe(42);
});

test('pipeline tracks metadata', function () {
    $step1 = \mock(['A' => 'B']);
    $step2 = \mock(['B' => 'C']);

    $result = Pipeline::create()
        ->step('first', $step1)
        ->step('second', $step2)
        ->run('A');

    expect($result->meta->totalTokens)->toBeGreaterThanOrEqual(0);
    expect($result->meta->duration)->toBeGreaterThan(0);
    expect($result->meta->stepsExecuted)->toBe(2);
});

test('pipeline get helper parses JSON from final output', function () {
    $agent = \mock(['Input' => '{"status": "success", "count": 42}']);

    $result = Pipeline::create()
        ->step('process', $agent)
        ->run('Input');

    expect($result->get('status'))->toBe('success');
    expect($result->get('count'))->toBe(42);
    expect($result->get('missing', 'default'))->toBe('default');
});

test('pipeline step result json helper', function () {
    $agent = \mock(['Input' => '{"key": "value"}']);

    $result = Pipeline::create()
        ->step('extract', $agent)
        ->run('Input');

    $step = $result->step('extract');
    expect($step->json())->toBe(['key' => 'value']);
    expect($step->get('key'))->toBe('value');
    expect($step->get('missing', 'fallback'))->toBe('fallback');
});

test('pipeline can mix agents and transforms', function () {
    $agent1 = \mock(['Start' => 'RAW DATA']);
    $agent2 = \mock(['raw data' => 'FINAL']); // Transform outputs 'raw data'

    $result = Pipeline::create()
        ->step('fetch', $agent1)
        ->transform('lowercase', fn ($text) => strtolower($text))
        ->step('analyze', $agent2)
        ->run('Start');

    expect($result->step('fetch')->output)->toBe('RAW DATA');
    expect($result->step('lowercase')->output)->toBe('raw data');
    expect($result->step('analyze')->output)->toBe('FINAL');
    expect($result->final)->toBe('FINAL');
});

test('pipeline exports to array', function () {
    $step1 = \mock(['A' => 'B']);

    $result = Pipeline::create()
        ->step('test', $step1)
        ->run('A');

    $exported = $result->export();

    expect($exported)->toHaveKeys(['final', 'steps', 'metadata']);
    expect($exported['steps'][0]['name'])->toBe('test');
    expect($exported['steps'][0]['output'])->toBe('B');
});
