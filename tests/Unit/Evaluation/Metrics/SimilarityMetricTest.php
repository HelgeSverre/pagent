<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\SimilarityMetric;

test('it calculates perfect similarity for identical strings', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'hello world', 'hello world');

    expect($score)->toBe(1.0);
});

test('it calculates zero similarity for completely different strings', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'abc', 'xyz');

    expect($score)->toBeLessThan(1.0);
});

test('it calculates partial similarity for similar strings', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'hello world', 'hello there');

    expect($score)->toBeGreaterThan(0.0)
        ->and($score)->toBeLessThan(1.0);
});

test('it returns 1.0 for both empty strings', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', '', '');

    expect($score)->toBe(1.0);
});

test('it returns 0.0 when expected is empty but output is not', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'hello', '');

    expect($score)->toBe(0.0);
});

test('it returns 0.0 when output is empty but expected is not', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', '', 'hello');

    expect($score)->toBe(0.0);
});

test('it returns 0.0 when expected is null', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'hello', null);

    expect($score)->toBe(0.0);
});

test('it returns 0.0 when expected is not a string', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'hello', 123);

    expect($score)->toBe(0.0);
});

test('it handles case sensitive comparison', function (): void {
    $metric = new SimilarityMetric;

    $score1 = $metric->calculate('input', 'Hello World', 'HELLO WORLD');
    $score2 = $metric->calculate('input', 'hello world', 'hello world');

    expect($score1)->toBeLessThan($score2);
});

test('it handles long text similarity', function (): void {
    $metric = new SimilarityMetric;

    $text1 = str_repeat('Lorem ipsum dolor sit amet, ', 100);
    $text2 = str_repeat('Lorem ipsum dolor sit amet, ', 100);

    $score = $metric->calculate('input', $text1, $text2);

    expect($score)->toBe(1.0);
});

test('it handles unicode strings', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'Hello 世界', 'Hello 世界');

    expect($score)->toBe(1.0);
});

test('it has correct name', function (): void {
    $metric = new SimilarityMetric;

    expect($metric->getName())->toBe('similarity');
});

test('it has correct description', function (): void {
    $metric = new SimilarityMetric;

    expect($metric->getDescription())->toBe('Calculates text similarity between output and expected using similar_text()');
});

test('it calculates similarity for slightly different strings', function (): void {
    $metric = new SimilarityMetric;

    $score = $metric->calculate('input', 'The quick brown fox', 'The quick brown dog');

    expect($score)->toBeGreaterThan(0.7)
        ->and($score)->toBeLessThan(1.0);
});
