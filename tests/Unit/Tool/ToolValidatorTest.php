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

    expect(fn () => ToolValidator::validate($tool, ['name' => 'John']))
        ->toThrow(RuntimeException::class, 'missing required argument: age');
});

test('it validates argument types', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (int $age): string => "Age: {$age}",
    );

    expect(fn () => ToolValidator::validate($tool, ['age' => 'not a number']))
        ->toThrow(RuntimeException::class, 'expects int');
});

test('it allows correct arguments', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (string $name): string => "Hello {$name}",
    );

    ToolValidator::validate($tool, ['name' => 'John']);

    expect(true)->toBeTrue();
});

test('it handles nullable and default parameters', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (string $name, ?int $age = null): string => "Name: {$name}",
    );

    ToolValidator::validate($tool, ['name' => 'John']);

    expect(true)->toBeTrue();
});

test('it accepts int for float parameters', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn (float $value): float => $value * 2,
    );

    ToolValidator::validate($tool, ['value' => 42]);

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

test('it ignores unknown extra keys during validation', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test',
        fn (string $name): string => "Hello {$name}",
    );

    ToolValidator::validate($tool, ['name' => 'John', 'hallucinated' => 'value']);

    expect(true)->toBeTrue();
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
        ->toThrow(RuntimeException::class, 'missing required argument: name');
});

test('it rejects string for int parameter', function (): void {
    $tool = Tool::fromClosure(
        'count',
        'Count',
        fn (int $num): int => $num,
    );

    expect(fn () => ToolValidator::validate($tool, ['num' => '123']))
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
    ToolValidator::validate($tool, ['value' => 'string']);

    // Should accept integers
    ToolValidator::validate($tool, ['value' => 123]);

    // Should accept arrays
    ToolValidator::validate($tool, ['value' => []]);

    expect(true)->toBeTrue();
});

test('it validates array parameters correctly', function (): void {
    $tool = Tool::fromClosure(
        'process_items',
        'Process items',
        fn (array $items): int => count($items),
    );

    // Should accept array
    ToolValidator::validate($tool, ['items' => ['item1', 'item2']]);

    // Should reject non-array
    expect(fn () => ToolValidator::validate($tool, ['items' => 'not-an-array']))
        ->toThrow(RuntimeException::class, 'expects array');
});

test('it handles boolean parameters correctly', function (): void {
    $tool = Tool::fromClosure(
        'toggle',
        'Toggle setting',
        fn (bool $enabled): string => $enabled ? 'on' : 'off',
    );

    // Should accept true
    ToolValidator::validate($tool, ['enabled' => true]);

    // Should accept false
    ToolValidator::validate($tool, ['enabled' => false]);

    // Should reject string
    expect(fn () => ToolValidator::validate($tool, ['enabled' => 'true']))
        ->toThrow(RuntimeException::class, 'expects bool');
});
