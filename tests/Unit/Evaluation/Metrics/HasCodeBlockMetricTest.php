<?php

declare(strict_types=1);

use Pagent\Evaluation\Metrics\HasCodeBlockMetric;

test('code block present returns 1.0', function (): void {
    $metric = new HasCodeBlockMetric;

    $output = 'Here is some code:

```php
echo "Hello World";
```';

    expect($metric->calculate('', $output, null))->toBe(1.0);
});

test('no code block returns 0.0', function (): void {
    $metric = new HasCodeBlockMetric;

    expect($metric->calculate('', 'Just plain text', null))->toBe(0.0);
});

test('language-specific detection works', function (): void {
    $phpMetric = new HasCodeBlockMetric(language: 'php');
    $jsMetric = new HasCodeBlockMetric(language: 'javascript');

    $phpCode = '```php
echo "test";
```';

    expect($phpMetric->calculate('', $phpCode, null))->toBe(1.0)
        ->and($jsMetric->calculate('', $phpCode, null))->toBe(0.0);
});

test('multiple blocks counting', function (): void {
    $metric = new HasCodeBlockMetric(minBlocks: 2);

    $oneBlock = '```
code
```';

    $twoBlocks = '```
code1
```

Some text

```
code2
```';

    expect($metric->calculate('', $oneBlock, null))->toBeLessThan(1.0)
        ->and($metric->calculate('', $twoBlocks, null))->toBe(1.0);
});

test('empty string returns 0.0', function (): void {
    $metric = new HasCodeBlockMetric;

    expect($metric->calculate('', '', null))->toBe(0.0);
});

test('inline code vs code blocks', function (): void {
    $metric = new HasCodeBlockMetric;

    $inlineCode = 'Use `echo "test"` for output';
    $codeBlock = '```
echo "test"
```';

    expect($metric->calculate('', $inlineCode, null))->toBe(0.0)
        ->and($metric->calculate('', $codeBlock, null))->toBe(1.0);
});

test('has correct name and description', function (): void {
    $metric = new HasCodeBlockMetric;

    expect($metric->getName())->toBe('has_code_block')
        ->and($metric->getDescription())->toContain('code block');
});

test('language-specific name includes language', function (): void {
    $metric = new HasCodeBlockMetric(language: 'php');

    expect($metric->getName())->toBe('has_code_block_php');
});
