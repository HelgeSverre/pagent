<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Contracts\Provider;
use Pagent\Evaluation\Dataset;
use Pagent\Evaluation\Evaluator;
use Pagent\Evaluation\Metrics\KeywordMetric;
use Pagent\Evaluation\Metrics\LengthMetric;
use Pagent\Registry;
use Pagent\Response;

test('it creates evaluator for agent', function (): void {
    $evaluator = evaluate('test-agent');

    expect($evaluator)->toBeInstanceOf(Evaluator::class);
});

test('it reports an unregistered evaluator agent without creating one', function (): void {
    $dataset = Dataset::fromArray([
        ['input' => 'Hello'],
    ]);

    expect(fn () => evaluate('missing-evaluator-agent')->dataset($dataset)->run())
        ->toThrow(RuntimeException::class, "Agent 'missing-evaluator-agent' not found");
    expect(getAgent('missing-evaluator-agent'))->toBeNull();
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

test('it continues past failing dataset items and records errors', function (): void {
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            if (str_contains($message, 'BOOM')) {
                throw new RuntimeException('rate limited');
            }

            return new Response(content: "ok: {$message}");
        }
    };

    $agent = new Agent('flaky-eval');
    $agent->provider($provider);
    Registry::set('flaky-eval', $agent);

    $dataset = Dataset::fromArray([
        ['input' => 'fine'],
        ['input' => 'BOOM'],
        ['input' => 'also fine'],
    ]);

    $result = evaluate('flaky-eval')
        ->dataset($dataset)
        ->metric('always_one', fn () => 1.0)
        ->run();

    expect($result->results)->toHaveCount(3)
        ->and($result->getFailureCount())->toBe(1)
        ->and($result->errors[0]['index'])->toBe(1)
        ->and($result->errors[0]['error'])->toContain('rate limited')
        ->and($result->results[1]['output'])->toBeNull()
        ->and($result->results[1]['metrics']['always_one'])->toBe(0.0);
});

test('it compares against a baseline agent', function (): void {
    agent('candidate-agent')
        ->provider('mock')
        ->system('Candidate');

    agent('baseline-agent')
        ->provider('mock')
        ->system('Baseline');

    $dataset = Dataset::fromArray([
        ['input' => 'Test 1'],
        ['input' => 'Test 2'],
    ]);

    $result = evaluate('candidate-agent')
        ->dataset($dataset)
        ->metric('score', fn ($input, $output) => str_contains($output, 'Mock') ? 1.0 : 0.0)
        ->baseline('baseline-agent')
        ->run();

    expect($result->baseline)->not->toBeNull()
        ->and($result->baseline['agent'])->toBe('baseline-agent')
        ->and($result->baseline['averages'])->toHaveKey('score')
        ->and($result->baseline['deltas']['score'])->toBe(0.0)
        ->and($result->getSummary())->toHaveKey('baseline');
});

test('dataset rows use isolated conversations without mutating the registered agent', function (): void {
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return new Response(content: (string) count($options['messages'] ?? []));
        }
    };

    $agent = new Agent('isolated-eval');
    $agent->provider($provider);
    Registry::set('isolated-eval', $agent);

    $result = evaluate('isolated-eval')
        ->dataset(Dataset::fromArray([['input' => 'one'], ['input' => 'two']]))
        ->run();

    expect(array_column($result->results, 'output'))->toBe(['1', '1'])
        ->and($agent->getMessages())->toBeEmpty();
});

test('stateful evaluation is explicit and still leaves the registered agent untouched', function (): void {
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return new Response(content: (string) count($options['messages'] ?? []));
        }
    };

    $agent = new Agent('stateful-eval');
    $agent->provider($provider);
    Registry::set('stateful-eval', $agent);

    $result = evaluate('stateful-eval')
        ->dataset(Dataset::fromArray([['input' => 'one'], ['input' => 'two']]))
        ->stateful()
        ->run();

    expect(array_column($result->results, 'output'))->toBe(['1', '3'])
        ->and($agent->getMessages())->toBeEmpty();
});
