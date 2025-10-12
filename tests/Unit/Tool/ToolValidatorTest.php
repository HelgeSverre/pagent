<?php

declare(strict_types=1);

use Pagent\Tool\Tool;
use Pagent\Tool\ToolValidator;

\test('it validates required arguments', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn(string $name, int $age): string => "Name: {$name}, Age: {$age}",
    );

    \expect(fn() => ToolValidator::validate($tool, ['John']))
        ->toThrow(RuntimeException::class, 'requires 2 arguments, 1 provided');
});

\test('it validates argument types', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn(int $age): string => "Age: {$age}",
    );

    \expect(fn() => ToolValidator::validate($tool, ['not a number']))
        ->toThrow(RuntimeException::class, 'expects int');
});

\test('it allows correct arguments', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn(string $name): string => "Hello {$name}",
    );

    ToolValidator::validate($tool, ['John']);

    \expect(true)->toBeTrue();
});

\test('it handles nullable and default parameters', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn(string $name, ?int $age = null): string => "Name: {$name}",
    );

    ToolValidator::validate($tool, ['John']);

    \expect(true)->toBeTrue();
});

\test('it accepts int for float parameters', function (): void {
    $tool = Tool::fromClosure(
        'test',
        'Test tool',
        fn(float $value): float => $value * 2,
    );

    ToolValidator::validate($tool, [42]);

    \expect(true)->toBeTrue();
});

\test('it validates associative arrays from LLMs', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn(string $city, bool $include_forecast = false): string => "Weather for {$city}",
    );

    ToolValidator::validate($tool, ['city' => 'Paris', 'include_forecast' => false]);

    \expect(true)->toBeTrue();
});

\test('it validates associative arrays with missing optional params', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn(string $city, bool $include_forecast = false): string => "Weather for {$city}",
    );

    ToolValidator::validate($tool, ['city' => 'Paris']);

    \expect(true)->toBeTrue();
});

\test('it throws for missing required args in associative arrays', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn(string $city, string $country): string => "Weather for {$city}, {$country}",
    );

    \expect(fn() => ToolValidator::validate($tool, ['city' => 'Paris']))
        ->toThrow(RuntimeException::class, 'missing required argument: country');
});

\test('it validates types in associative arrays', function (): void {
    $tool = Tool::fromClosure(
        'weather',
        'Get weather',
        fn(string $city, bool $include_forecast): string => "Weather for {$city}",
    );

    \expect(fn() => ToolValidator::validate($tool, ['city' => 'Paris', 'include_forecast' => 'yes']))
        ->toThrow(RuntimeException::class, 'expects bool');
});
