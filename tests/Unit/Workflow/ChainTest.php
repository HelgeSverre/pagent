<?php

declare(strict_types=1);

use Pagent\Contracts\Provider;
use Pagent\Workflow\Chain;

test('chain executes steps sequentially', function () {
    $step1 = \mock(['Hello' => 'Processed: Hello']);
    $step2 = \mock(['Processed: Hello' => 'Step 2: Processed: Hello']);
    $step3 = \mock(['Step 2: Processed: Hello' => 'Final: Step 2: Processed: Hello']);

    $result = Chain::create()
        ->add($step1)
        ->add($step2)
        ->add($step3)
        ->run('Hello');

    expect($result->final)->toBe('Final: Step 2: Processed: Hello');
    expect($result->steps)->toHaveCount(3);
    expect($result->meta->stepsExecuted)->toBe(3);
});

test('chain tracks metadata for each step', function () {
    $step1 = mock(['Input' => 'Output 1']);
    $step2 = mock(['Output 1' => 'Output 2']);

    $result = Chain::create()
        ->add($step1)
        ->add($step2)
        ->run('Input');

    expect($result->meta->totalTokens)->toBeGreaterThanOrEqual(0);
    expect($result->meta->duration)->toBeGreaterThan(0);
    expect($result->meta->stepsExecuted)->toBe(2);
});

test('chain aggregates usage arrays returned by providers', function () {
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => 'done',
                'usage' => ['total_tokens' => 17],
            ];
        }
    };

    $result = Chain::create()->add($provider)->run('input');

    expect($result->meta->totalTokens)->toBe(17)
        ->and($result->steps[0]->meta->tokens)->toBe(17);
});

test('chain step results contain input and output', function () {
    $step1 = mock(['A' => 'B']);
    $step2 = mock(['B' => 'C']);

    $result = Chain::create()
        ->add($step1)
        ->add($step2)
        ->run('A');

    $firstStep = $result->steps[0];
    expect($firstStep->input)->toBe('A');
    expect($firstStep->output)->toBe('B');
    expect($firstStep->name)->toBe('step_0');

    $secondStep = $result->steps[1];
    expect($secondStep->input)->toBe('B');
    expect($secondStep->output)->toBe('C');
    expect($secondStep->name)->toBe('step_1');
});

test('chain works with single step', function () {
    $step = mock(['Hello' => 'World']);

    $result = Chain::create()
        ->add($step)
        ->run('Hello');

    expect($result->final)->toBe('World');
    expect($result->steps)->toHaveCount(1);
});

test('chain result can be exported to array', function () {
    $step1 = mock(['A' => 'B']);
    $step2 = mock(['B' => 'C']);

    $result = Chain::create()
        ->add($step1)
        ->add($step2)
        ->run('A');

    $exported = $result->toArray();

    expect($exported)->toHaveKeys(['final', 'steps', 'metadata']);
    expect($exported['final'])->toBe('C');
    expect($exported['steps'])->toHaveCount(2);
    expect($exported['metadata'])->toHaveKeys(['total_tokens', 'duration', 'steps_executed']);
});

test('chain handles complex data flow', function () {
    $extractor = mock(['Invoice #123, Amount: $500' => '{"number": "123", "amount": 500}']);
    $validator = mock(['{"number": "123", "amount": 500}' => '{"valid": true, "errors": []}']);
    $processor = mock(['{"valid": true, "errors": []}' => 'Invoice processed successfully']);

    $result = Chain::create()
        ->add($extractor)
        ->add($validator)
        ->add($processor)
        ->run('Invoice #123, Amount: $500');

    expect($result->final)->toBe('Invoice processed successfully');
    expect($result->steps[0]->output)->toBe('{"number": "123", "amount": 500}');
    expect($result->steps[1]->output)->toBe('{"valid": true, "errors": []}');
});
