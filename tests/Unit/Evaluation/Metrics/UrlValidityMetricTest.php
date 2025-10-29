<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\UrlValidityMetric;

test('valid URLs return 1.0', function (): void {
    $metric = new UrlValidityMetric;

    $output = 'Visit https://example.com and http://test.org for more info';

    expect($metric->calculate('', $output, null))->toBe(1.0);
});

test('invalid URLs return 0.0', function (): void {
    $metric = new UrlValidityMetric;

    $output = 'Visit htp://broken.url and invalid://test';

    expect($metric->calculate('', $output, null))->toBe(0.0);
});

test('no URLs returns 1.0', function (): void {
    $metric = new UrlValidityMetric;

    expect($metric->calculate('', 'No URLs here', null))->toBe(1.0);
});

test('mixed valid and invalid scored proportionally', function (): void {
    $metric = new UrlValidityMetric;

    // 2 valid, 0 invalid = 1.0
    $allValid = 'Visit https://example.com and http://test.org';
    expect($metric->calculate('', $allValid, null))->toBe(1.0);

    // 1 valid, 1 invalid = 0.5
    $mixed = 'Visit https://example.com and htp://broken';
    expect($metric->calculate('', $mixed, null))->toBe(0.5);
});

test('trailing punctuation removed', function (): void {
    $metric = new UrlValidityMetric;

    $output = 'Visit https://example.com, https://test.org. More at https://site.com!';

    expect($metric->calculate('', $output, null))->toBe(1.0);
});

test('URL extraction works correctly', function (): void {
    $metric = new UrlValidityMetric;

    $output = 'HTTPS://EXAMPLE.COM and http://test.org';

    expect($metric->calculate('', $output, null))->toBe(1.0);
});

test('empty string returns 1.0', function (): void {
    $metric = new UrlValidityMetric;

    expect($metric->calculate('', '', null))->toBe(1.0);
});

test('URLs in markdown links', function (): void {
    $metric = new UrlValidityMetric;

    $markdown = '[Link](https://example.com) and [Another](http://test.org)';

    expect($metric->calculate('', $markdown, null))->toBe(1.0);
});

test('has correct name and description', function (): void {
    $metric = new UrlValidityMetric;

    expect($metric->getName())->toBe('url_validity')
        ->and($metric->getDescription())->toContain('URL');
});
