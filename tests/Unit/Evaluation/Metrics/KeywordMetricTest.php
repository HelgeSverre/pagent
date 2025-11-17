<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\KeywordMetric;

test('it finds single keyword', function (): void {
    $metric = new KeywordMetric(['hello']);

    $score = $metric->calculate('input', 'hello world');

    expect($score)->toBe(1.0);
});

test('it finds all keywords', function (): void {
    $metric = new KeywordMetric(['hello', 'world']);

    $score = $metric->calculate('input', 'hello beautiful world');

    expect($score)->toBe(1.0);
});

test('it finds partial keywords', function (): void {
    $metric = new KeywordMetric(['hello', 'world', 'foo']);

    $score = $metric->calculate('input', 'hello world');

    expect($score)->toBe(2 / 3);  // 2 out of 3 found
});

test('it returns 0.0 when no keywords found', function (): void {
    $metric = new KeywordMetric(['foo', 'bar']);

    $score = $metric->calculate('input', 'hello world');

    expect($score)->toBe(0.0);
});

test('it is case insensitive', function (): void {
    $metric = new KeywordMetric(['HELLO', 'WORLD']);

    $score = $metric->calculate('input', 'hello world');

    expect($score)->toBe(1.0);
});

test('it handles unicode keywords', function (): void {
    $metric = new KeywordMetric(['世界', 'hello']);

    $score = $metric->calculate('input', 'hello 世界');

    expect($score)->toBe(1.0);
});

test('it requires all keywords when requireAll is true', function (): void {
    $metric = new KeywordMetric(['hello', 'world'], requireAll: true);

    $scoreAll = $metric->calculate('input', 'hello world');
    $scorePartial = $metric->calculate('input', 'hello there');

    expect($scoreAll)->toBe(1.0)
        ->and($scorePartial)->toBe(0.0);
});

test('it allows any keywords when requireAll is false', function (): void {
    $metric = new KeywordMetric(['hello', 'world', 'foo'], requireAll: false);

    $score = $metric->calculate('input', 'hello there');

    expect($score)->toBe(1 / 3);  // 1 out of 3 found
});

test('it handles empty keywords array', function (): void {
    $metric = new KeywordMetric([]);

    $score = $metric->calculate('input', 'hello world');

    expect($score)->toBe(0.0);
});

test('it handles empty output', function (): void {
    $metric = new KeywordMetric(['hello']);

    $score = $metric->calculate('input', '');

    expect($score)->toBe(0.0);
});

test('it handles keywords as substrings', function (): void {
    $metric = new KeywordMetric(['test']);

    $score = $metric->calculate('input', 'This is a testing string');

    expect($score)->toBe(1.0);  // 'test' found in 'testing'
});

test('it handles multiple occurrences of same keyword', function (): void {
    $metric = new KeywordMetric(['hello']);

    $score = $metric->calculate('input', 'hello hello hello');

    expect($score)->toBe(1.0);  // Still just 1/1
});

test('it has correct name', function (): void {
    $metric = new KeywordMetric(['test']);

    expect($metric->getName())->toBe('keyword_match');
});

test('it has correct description for any mode', function (): void {
    $metric = new KeywordMetric(['foo', 'bar'], requireAll: false);

    expect($metric->getDescription())->toBe('Checks if any keywords are present: foo, bar');
});

test('it has correct description for all mode', function (): void {
    $metric = new KeywordMetric(['foo', 'bar'], requireAll: true);

    expect($metric->getDescription())->toBe('Checks if all keywords are present: foo, bar');
});

test('it handles whitespace in keywords', function (): void {
    $metric = new KeywordMetric(['hello world']);

    $score = $metric->calculate('input', 'This is a hello world example');

    expect($score)->toBe(1.0);
});

test('it handles special characters in keywords', function (): void {
    $metric = new KeywordMetric(['@user', '#tag']);

    $score = $metric->calculate('input', 'Message from @user with #tag');

    expect($score)->toBe(1.0);
});

test('it calculates correct proportion for partial matches', function (): void {
    $metric = new KeywordMetric(['one', 'two', 'three', 'four', 'five']);

    $score = $metric->calculate('input', 'I have one, two, and three items');

    expect($score)->toBe(3 / 5);
});

test('it handles duplicate keywords in array', function (): void {
    $metric = new KeywordMetric(['hello', 'hello', 'world']);

    $score = $metric->calculate('input', 'hello world');

    // Should find 'hello' twice (counts as 2 found) and 'world' once
    expect($score)->toBe(1.0);
});

test('it handles mixed case keywords and output', function (): void {
    $metric = new KeywordMetric(['Hello', 'WORLD', 'TeSt']);

    $score = $metric->calculate('input', 'hello WORLD test');

    expect($score)->toBe(1.0);
});
