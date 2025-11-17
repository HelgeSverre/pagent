<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\LengthMetric;

test('it passes when output meets minimum length', function (): void {
    $metric = new LengthMetric(minLength: 10);

    $score = $metric->calculate('input', 'This is at least 10 characters');

    expect($score)->toBe(1.0);
});

test('it fails when output is below minimum length', function (): void {
    $metric = new LengthMetric(minLength: 50);

    $score = $metric->calculate('input', 'Short');

    expect($score)->toBe(0.0);
});

test('it passes when output is within range', function (): void {
    $metric = new LengthMetric(minLength: 10, maxLength: 20);

    $score = $metric->calculate('input', 'Fifteen chars!!');  // 15 chars

    expect($score)->toBeGreaterThan(0.0)
        ->and($score)->toBeLessThanOrEqual(1.0);
});

test('it fails when output exceeds maximum length', function (): void {
    $metric = new LengthMetric(minLength: 5, maxLength: 10);

    $score = $metric->calculate('input', 'This is way too long for the limit');

    expect($score)->toBe(0.0);
});

test('it uses mb_strlen for multibyte characters', function (): void {
    $metric = new LengthMetric(minLength: 5, maxLength: 10);

    $score = $metric->calculate('input', 'Hello 世界');  // 8 multibyte chars

    expect($score)->toBeGreaterThan(0.0);
});

test('it calculates proportional score within range', function (): void {
    $metric = new LengthMetric(minLength: 0, maxLength: 100);

    $score50 = $metric->calculate('input', str_repeat('x', 50));
    $score25 = $metric->calculate('input', str_repeat('x', 25));

    expect($score50)->toBeGreaterThan($score25);
});

test('it returns 1.0 at exact maximum length', function (): void {
    $metric = new LengthMetric(minLength: 0, maxLength: 10);

    $score = $metric->calculate('input', str_repeat('x', 10));

    expect($score)->toBe(1.0);
});

test('it returns score at exact minimum length', function (): void {
    $metric = new LengthMetric(minLength: 10, maxLength: 20);

    $score = $metric->calculate('input', str_repeat('x', 10));

    expect($score)->toBeGreaterThanOrEqual(0.0);
});

test('it handles empty output with min length zero', function (): void {
    $metric = new LengthMetric(minLength: 0, maxLength: 100);

    $score = $metric->calculate('input', '');

    expect($score)->toBe(0.0);
});

test('it handles empty output with non-zero min length', function (): void {
    $metric = new LengthMetric(minLength: 10);

    $score = $metric->calculate('input', '');

    expect($score)->toBe(0.0);
});

test('it has correct name', function (): void {
    $metric = new LengthMetric;

    expect($metric->getName())->toBe('length_check');
});

test('it has correct description for min length only', function (): void {
    $metric = new LengthMetric(minLength: 50);

    expect($metric->getDescription())->toBe('Checks if output is at least 50 characters');
});

test('it has correct description for range', function (): void {
    $metric = new LengthMetric(minLength: 10, maxLength: 100);

    expect($metric->getDescription())->toBe('Checks if output is between 10 and 100 characters');
});

test('it handles max length equals min length', function (): void {
    $metric = new LengthMetric(minLength: 10, maxLength: 10);

    $scoreExact = $metric->calculate('input', str_repeat('x', 10));
    $scoreTooShort = $metric->calculate('input', str_repeat('x', 9));
    $scoreTooLong = $metric->calculate('input', str_repeat('x', 11));

    expect($scoreExact)->toBe(1.0)
        ->and($scoreTooShort)->toBe(0.0)
        ->and($scoreTooLong)->toBe(0.0);
});

test('it handles very long strings', function (): void {
    $metric = new LengthMetric(minLength: 1000, maxLength: 2000);

    $score = $metric->calculate('input', str_repeat('x', 1500));

    expect($score)->toBeGreaterThan(0.0)
        ->and($score)->toBeLessThanOrEqual(1.0);
});

test('it clamps score to 1.0', function (): void {
    $metric = new LengthMetric(minLength: 0, maxLength: 10);

    // Even if calculation exceeds 1.0, it should be clamped
    $score = $metric->calculate('input', str_repeat('x', 100));

    expect($score)->toBeLessThanOrEqual(1.0);
});
