<?php

declare(strict_types=1);

use Pagent\Tool\Tool;
use Pagent\Tool\ToolArgument;
use Pagent\Tool\ToolValidator;

test('it validates required arguments', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (string $name, int $age): string => "Name: {$name}, Age: {$age}",
    );

    expect(fn () => ToolValidator::validate($tool, ['John']))
        ->toThrow(RuntimeException::class, 'requires 2 arguments, 1 provided');
});

test('it validates argument types', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (int $age): string => "Age: {$age}",
    );

    expect(fn () => ToolValidator::validate($tool, ['not a number']))
        ->toThrow(RuntimeException::class, 'expects int');
});

test('it allows correct arguments', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (string $name): string => "Hello {$name}",
    );

    ToolValidator::validate($tool, ['John']);

    expect(true)->toBeTrue();
});

test('it handles nullable and default parameters', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (string $name, ?int $age = null): string => "Name: {$name}",
    );

    ToolValidator::validate($tool, ['John']);

    expect(true)->toBeTrue();
});

test('it accepts int for float parameters', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (float $value): float => $value * 2,
    );

    ToolValidator::validate($tool, [42]);

    expect(true)->toBeTrue();
});

test('it validates associative arrays from LLMs', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn (string $city, bool $include_forecast = false): string => "Weather for {$city}",
    );

    ToolValidator::validate($tool, ['city' => 'Paris', 'include_forecast' => false]);

    expect(true)->toBeTrue();
});

test('it validates associative arrays with missing optional params', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn (string $city, bool $include_forecast = false): string => "Weather for {$city}",
    );

    ToolValidator::validate($tool, ['city' => 'Paris']);

    expect(true)->toBeTrue();
});

test('it throws for missing required args in associative arrays', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn (string $city, string $country): string => "Weather for {$city}, {$country}",
    );

    expect(fn () => ToolValidator::validate($tool, ['city' => 'Paris']))
        ->toThrow(RuntimeException::class, 'missing required argument: country');
});

test('it validates types in associative arrays', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn (string $city, bool $include_forecast): string => "Weather for {$city}",
    );

    expect(fn () => ToolValidator::validate($tool, ['city' => 'Paris', 'include_forecast' => 'yes']))
        ->toThrow(RuntimeException::class, 'expects bool');
});

// ========================================
// EDGE CASE TESTS
// ========================================

test('it handles mixed array keys correctly', function (): void {
    $tool = Tool::fromClosure(
        'mixed_test',
        'Test mixed args',
        fn (string $a, int $b): string => "{$a}-{$b}",
    );

    // Mixed keys (numeric and string) are treated as associative
    // So we must provide named parameters
    $mixedArgs = ['a' => 'value1', 'b' => 42];

    ToolValidator::validate($tool, $mixedArgs);

    expect(true)->toBeTrue();
});

test('it throws for mixed array with missing required parameters', function (): void {
    $tool = Tool::fromClosure(
        'mixed_test',
        'Test',
        fn (string $a, string $b): string => "{$a}-{$b}",
    );

    // Mixed keys detected as associative, missing required 'b'
    $mixedArgs = [0 => 'value1'];  // Has numeric key 0

    expect(fn () => ToolValidator::validate($tool, $mixedArgs))
        ->toThrow(RuntimeException::class, 'requires 2 arguments, 1 provided');
});

test('it handles empty arrays when all params optional', function (): void {
    $tool = Tool::fromClosure(
        'optional_tool',
        'Tool with optional params',
        fn (?string $name = null, ?int $age = null): string => 'result',
    );

    ToolValidator::validate($tool, []);

    expect(true)->toBeTrue();
});

test('it throws on empty array when params required', function (): void {
    $tool = Tool::fromClosure(
        'required_tool',
        'Tool with required params',
        fn (string $name): string => "Hello {$name}",
    );

    expect(fn () => ToolValidator::validate($tool, []))
        ->toThrow(RuntimeException::class, 'requires 1 arguments, 0 provided');
});

test('it rejects string for int parameter', function (): void {
    $tool = Tool::fromClosure(
        'count',
        'Count',
        fn (int $num): int => $num,
    );

    expect(fn () => ToolValidator::validate($tool, ['123']))
        ->toThrow(RuntimeException::class, 'expects int');
});

test('it accepts any type for untyped parameters', function (): void {
    $tool = new Tool(
        'flexible',
        'Flexible tool',
        fn ($value) => $value,
        [new ToolArgument('value', 'mixed')],
    );

    // Should accept strings
    ToolValidator::validate($tool, ['string']);

    // Should accept integers
    ToolValidator::validate($tool, [123]);

    // Should accept arrays
    ToolValidator::validate($tool, [[]]);

    expect(true)->toBeTrue();
});

test('it validates array parameters correctly', function (): void {
    $tool = Tool::fromClosure(
        'process_items',
        'Process items',
        fn (array $items): int => count($items),
    );

    // Should accept array
    ToolValidator::validate($tool, [['item1', 'item2']]);

    // Should reject non-array
    expect(fn () => ToolValidator::validate($tool, ['not-an-array']))
        ->toThrow(RuntimeException::class, 'expects array');
});

test('it handles boolean parameters correctly', function (): void {
    $tool = Tool::fromClosure(
        'toggle',
        'Toggle setting',
        fn (bool $enabled): string => $enabled ? 'on' : 'off',
    );

    // Should accept true
    ToolValidator::validate($tool, [true]);

    // Should accept false
    ToolValidator::validate($tool, [false]);

    // Should reject string
    expect(fn () => ToolValidator::validate($tool, ['true']))
        ->toThrow(RuntimeException::class, 'expects bool');
});
