<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\JsonValidMetric;

test('valid JSON returns 1.0', function (): void {
    $metric = new JsonValidMetric;

    expect($metric->calculate('', '{"name": "John"}', null))->toBe(1.0)
        ->and($metric->calculate('', '[]', null))->toBe(1.0)
        ->and($metric->calculate('', '123', null))->toBe(1.0)
        ->and($metric->calculate('', '"string"', null))->toBe(1.0)
        ->and($metric->calculate('', 'true', null))->toBe(1.0)
        ->and($metric->calculate('', 'null', null))->toBe(1.0);
});

test('invalid JSON returns 0.0', function (): void {
    $metric = new JsonValidMetric;

    expect($metric->calculate('', '{invalid}', null))->toBe(0.0)
        ->and($metric->calculate('', 'not json', null))->toBe(0.0)
        ->and($metric->calculate('', '{"unclosed": ', null))->toBe(0.0)
        ->and($metric->calculate('', "{'single': 'quotes'}", null))->toBe(0.0);
});

test('empty string returns 0.0', function (): void {
    $metric = new JsonValidMetric;

    expect($metric->calculate('', '', null))->toBe(0.0);
});

test('has correct name and description', function (): void {
    $metric = new JsonValidMetric;

    expect($metric->getName())->toBe('json_valid')
        ->and($metric->getDescription())->toContain('JSON');
});
