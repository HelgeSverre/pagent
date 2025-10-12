<?php

declare(strict_types=1);

use Pagent\Tools\DataExtract;

test('data extract has correct metadata', function () {
    $tool = new DataExtract;

    expect($tool->name())->toBe('data_extract');
    expect($tool->description())->toContain('Extract structured data');
    expect($tool->parameters())->toHaveKey('properties');
});

test('data extract throws when text missing', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute(['schema' => ['type' => 'object', 'properties' => []]]))
        ->toThrow(RuntimeException::class, 'Text parameter is required');
});

test('data extract throws when schema missing', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute(['text' => 'test']))
        ->toThrow(RuntimeException::class, 'Schema parameter is required');
});

test('data extract throws for invalid schema', function () {
    $tool = new DataExtract;

    expect(fn () => $tool->execute([
        'text' => 'test',
        'schema' => ['invalid' => 'schema'],
    ]))->toThrow(RuntimeException::class, 'Schema must have');
});
