<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\MarkdownValidMetric;

test('valid markdown returns high score', function (): void {
    $metric = new MarkdownValidMetric;

    $markdown = '# Title

## Section

- List item 1
- List item 2

```php
echo "code";
```

[Link](https://example.com)';

    expect($metric->calculate('', $markdown, null))->toBeGreaterThan(0.7);
});

test('no structure returns low score', function (): void {
    $metric = new MarkdownValidMetric;

    expect($metric->calculate('', 'Just plain text', null))->toBeLessThan(0.5);
});

test('headers detected correctly', function (): void {
    $metric = new MarkdownValidMetric;

    $withHeaders = '# Title
Some text';

    $withoutHeaders = 'Just text without headers';

    expect($metric->calculate('', $withHeaders, null))->toBeGreaterThan(0.0)
        ->and($metric->calculate('', $withoutHeaders, null))->toBeLessThan(1.0);
});

test('lists detected correctly', function (): void {
    $metric = new MarkdownValidMetric;

    $withLists = '- Item 1
- Item 2';

    $numberedList = '1. First
2. Second';

    expect($metric->calculate('', $withLists, null))->toBeGreaterThan(0.0)
        ->and($metric->calculate('', $numberedList, null))->toBeGreaterThan(0.0);
});

test('code blocks detected correctly', function (): void {
    $metric = new MarkdownValidMetric;

    $withCodeBlock = '```
code here
```';

    $withIndentedCode = '    indented code';

    expect($metric->calculate('', $withCodeBlock, null))->toBeGreaterThan(0.0)
        ->and($metric->calculate('', $withIndentedCode, null))->toBeGreaterThan(0.0);
});

test('broken links penalized', function (): void {
    $metric = new MarkdownValidMetric;

    $goodLink = '[Text](https://example.com)';
    $brokenLink1 = '[Text]( space after paren)';
    $brokenLink2 = '[Text without closing paren';

    expect($metric->calculate('', $goodLink, null))->toBeGreaterThan(
        $metric->calculate('', $brokenLink1, null)
    );
});

test('require headers mode works', function (): void {
    $metric = new MarkdownValidMetric(requireHeaders: true);

    $withHeaders = '# Title';
    $withoutHeaders = 'Just text';

    expect($metric->calculate('', $withHeaders, null))->toBe(1.0)
        ->and($metric->calculate('', $withoutHeaders, null))->toBe(0.0);
});

test('require lists mode works', function (): void {
    $metric = new MarkdownValidMetric(requireLists: true);

    $withLists = '- Item';
    $withoutLists = 'Just text';

    expect($metric->calculate('', $withLists, null))->toBe(1.0)
        ->and($metric->calculate('', $withoutLists, null))->toBe(0.0);
});

test('require code blocks mode works', function (): void {
    $metric = new MarkdownValidMetric(requireCodeBlocks: true);

    $withCode = '```code```';
    $withoutCode = 'Just text';

    expect($metric->calculate('', $withCode, null))->toBe(1.0)
        ->and($metric->calculate('', $withoutCode, null))->toBe(0.0);
});

test('empty string returns 0.0', function (): void {
    $metric = new MarkdownValidMetric;

    expect($metric->calculate('', '', null))->toBe(0.0);
});

test('has correct name and description', function (): void {
    $metric = new MarkdownValidMetric;

    expect($metric->getName())->toBe('markdown_valid')
        ->and($metric->getDescription())->toContain('Markdown');
});
