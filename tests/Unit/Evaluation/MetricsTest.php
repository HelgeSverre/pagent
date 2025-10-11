<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\KeywordMetric;
use Pagent\Evaluation\Metrics\LengthMetric;
use Pagent\Evaluation\Metrics\SimilarityMetric;

test('keyword metric finds all keywords', function (): void {
    $metric = new KeywordMetric(['hello', 'world'], requireAll: true);

    expect($metric->calculate('', 'Hello world', null))->toBe(1.0)
        ->and($metric->calculate('', 'Hello there', null))->toBe(0.0);
});

test('keyword metric finds any keywords', function (): void {
    $metric = new KeywordMetric(['hello', 'world'], requireAll: false);

    expect($metric->calculate('', 'Hello there', null))->toBe(0.5)
        ->and($metric->calculate('', 'Goodbye', null))->toBe(0.0);
});

test('length metric checks minimum length', function (): void {
    $metric = new LengthMetric(minLength: 10);

    expect($metric->calculate('', 'Short', null))->toBe(0.0)
        ->and($metric->calculate('', 'This is long enough', null))->toBe(1.0);
});

test('length metric checks range', function (): void {
    $metric = new LengthMetric(minLength: 5, maxLength: 15);

    expect($metric->calculate('', 'Hi', null))->toBe(0.0)
        ->and($metric->calculate('', 'Just right', null))->toBeGreaterThan(0.0)
        ->and($metric->calculate('', 'This is way too long for the range', null))->toBe(0.0);
});

test('similarity metric compares strings', function (): void {
    $metric = new SimilarityMetric();

    expect($metric->calculate('', 'hello world', 'hello world'))->toBe(1.0)
        ->and($metric->calculate('', 'hello', 'goodbye'))->toBeLessThan(0.5)
        ->and($metric->calculate('', 'test', null))->toBe(0.0);
});

test('metrics have correct metadata', function (): void {
    $keyword = new KeywordMetric(['test']);
    $length = new LengthMetric(10);
    $similarity = new SimilarityMetric();

    expect($keyword->getName())->toBe('keyword_match')
        ->and($length->getName())->toBe('length_check')
        ->and($similarity->getName())->toBe('similarity');
});
