<?php

declare(strict_types=1);

use Pagent\Evaluation\Dataset;

test('it creates dataset from array', function (): void {
    $dataset = Dataset::fromArray([
        ['input' => 'Hello', 'expected' => 'Hi'],
        ['input' => 'Goodbye', 'expected' => 'Bye'],
    ]);

    expect($dataset->count())->toBe(2)
        ->and($dataset->items())->toHaveCount(2);
});

test('it loads dataset from json', function (): void {
    $json = __DIR__ . '/../../Fixtures/test_dataset.json';
    file_put_contents($json, json_encode([
        ['input' => 'Test 1', 'expected' => 'Response 1'],
        ['input' => 'Test 2', 'expected' => 'Response 2'],
    ]));

    $dataset = Dataset::fromJson($json);

    expect($dataset->count())->toBe(2);

    unlink($json);
});

test('it loads dataset from csv', function (): void {
    $csv = __DIR__ . '/../../Fixtures/test_dataset.csv';
    file_put_contents($csv, "input,expected\nHello,Hi\nBye,Goodbye");

    $dataset = Dataset::fromCsv($csv);

    expect($dataset->count())->toBe(2);

    unlink($csv);
});

test('it filters dataset', function (): void {
    $dataset = Dataset::fromArray([
        ['input' => 'Short'],
        ['input' => 'This is a longer input'],
    ]);

    $filtered = $dataset->filter(fn($item) => mb_strlen($item['input']) > 10);

    expect($filtered->count())->toBe(1);
});

test('it maps dataset', function (): void {
    $dataset = Dataset::fromArray([
        ['input' => 'hello'],
        ['input' => 'world'],
    ]);

    $mapped = $dataset->map(fn($item) => ['input' => mb_strtoupper($item['input'])]);

    expect($mapped->items()[0]['input'])->toBe('HELLO');
});
