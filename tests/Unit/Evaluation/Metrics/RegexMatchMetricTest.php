<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\RegexMatchMetric;

test('pattern found returns 1.0', function (): void {
    $metric = new RegexMatchMetric('/\b\d{3}-\d{2}-\d{4}\b/', 'ssn_check');

    expect($metric->calculate('', 'SSN: 123-45-6789', null))->toBe(1.0);
});

test('pattern not found returns 0.0', function (): void {
    $metric = new RegexMatchMetric('/\b\d{3}-\d{2}-\d{4}\b/', 'ssn_check');

    expect($metric->calculate('', 'No SSN here', null))->toBe(0.0);
});

test('inverse mode works correctly', function (): void {
    $metric = new RegexMatchMetric('/https?:\/\//', 'no_urls', inverse: true);

    expect($metric->calculate('', 'No URLs here', null))->toBe(1.0)
        ->and($metric->calculate('', 'Visit https://example.com', null))->toBe(0.0);
});

test('full match mode works correctly', function (): void {
    $metric = new RegexMatchMetric('/\d{4}-\d{2}-\d{2}/', 'date_format', fullMatch: true);

    expect($metric->calculate('', '2025-01-15', null))->toBe(1.0)
        ->and($metric->calculate('', 'Date: 2025-01-15', null))->toBe(0.0);
});

test('empty string with normal mode returns 0.0', function (): void {
    $metric = new RegexMatchMetric('/test/', 'test_check');

    expect($metric->calculate('', '', null))->toBe(0.0);
});

test('empty string with inverse mode returns 1.0', function (): void {
    $metric = new RegexMatchMetric('/test/', 'test_check', inverse: true);

    expect($metric->calculate('', '', null))->toBe(1.0);
});

test('email pattern works', function (): void {
    $metric = new RegexMatchMetric('/[\w\.-]+@[\w\.-]+\.\w{2,}/', 'email_check');

    expect($metric->calculate('', 'Contact: john@example.com', null))->toBe(1.0)
        ->and($metric->calculate('', 'No email here', null))->toBe(0.0);
});

test('has correct name and description', function (): void {
    $metric = new RegexMatchMetric('/test/', 'test_metric');

    expect($metric->getName())->toBe('test_metric')
        ->and($metric->getDescription())->toContain('pattern');
});
