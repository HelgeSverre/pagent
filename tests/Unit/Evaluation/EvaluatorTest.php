<?php

declare(strict_types=1);

use Pagent\Evaluation\Dataset;
use Pagent\Evaluation\Evaluator;
use Pagent\Evaluation\Metrics\KeywordMetric;
use Pagent\Evaluation\Metrics\LengthMetric;

test('it creates evaluator for agent', function (): void {
    $evaluator = evaluate('test-agent');

    expect($evaluator)->toBeInstanceOf(Evaluator::class);
});

test('it runs evaluation on dataset', function (): void {
    agent('eval-test')
        ->provider('mock')
        ->system('You are a test bot');

    $dataset = Dataset::fromArray([
        ['input' => 'Hello', 'expected' => 'Hi'],
        ['input' => 'Goodbye', 'expected' => 'Bye'],
    ]);

    $result = evaluate('eval-test')
        ->dataset($dataset)
        ->metric('keyword', new KeywordMetric(['hello', 'hi', 'mock']))
        ->metric('length', new LengthMetric(minLength: 5))
        ->run();

    expect($result->datasetSize)->toBe(2)
        ->and($result->results)->toHaveCount(2)
        ->and($result->results[0])->toHaveKeys(['input', 'output', 'expected', 'metrics']);
});

test('it calculates average scores', function (): void {
    agent('score-test')
        ->provider('mock')
        ->system('Test bot');

    $dataset = Dataset::fromArray([
        ['input' => 'Test 1'],
        ['input' => 'Test 2'],
    ]);

    $result = evaluate('score-test')
        ->dataset($dataset)
        ->metric('length', new LengthMetric(minLength: 10))
        ->run();

    $avgScore = $result->getAverageScore('length');

    expect($avgScore)->toBeFloat();
});

test('it supports custom metrics via closure', function (): void {
    agent('custom-metric-test')
        ->provider('mock')
        ->system('Test bot');

    $dataset = Dataset::fromArray([
        ['input' => 'Test'],
    ]);

    $result = evaluate('custom-metric-test')
        ->dataset($dataset)
        ->metric('always_one', fn ($input, $output, $expected) => 1.0)
        ->run();

    expect($result->getAverageScore('always_one'))->toBe(1.0);
});

test('it generates summary', function (): void {
    agent('summary-test')
        ->provider('mock')
        ->system('Test bot');

    $dataset = Dataset::fromArray([
        ['input' => 'Test'],
    ]);

    $result = evaluate('summary-test')
        ->dataset($dataset)
        ->metric('test_metric', fn () => 0.75)
        ->run();

    $summary = $result->getSummary();

    expect($summary)->toHaveKeys(['agent', 'dataset_size', 'metrics'])
        ->and($summary['metrics'])->toHaveKey('test_metric');
});

test('it loads dataset from file path', function (): void {
    $json = __DIR__.'/../../Fixtures/eval_test.json';
    file_put_contents($json, json_encode([
        ['input' => 'Test'],
    ]));

    agent('file-test')
        ->provider('mock')
        ->system('Test bot');

    $result = evaluate('file-test')
        ->dataset($json)
        ->metric('length', new LengthMetric)
        ->run();

    expect($result->datasetSize)->toBe(1);

    unlink($json);
});
