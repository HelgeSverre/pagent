<?php

declare(strict_types=1);

use Pagent\Tool\Tool;

\test('it creates tool from closure', function (): void {
    $tool = Tool::fromClosure(
        'get_weather',
        'Get the weather for a location',
        fn(string $location) => "The weather in {$location} is sunny",
    );

    \expect($tool->name)->toBe('get_weather')
        ->and($tool->description)->toBe('Get the weather for a location')
        ->and($tool->arguments)->toHaveCount(1)
        ->and($tool->arguments[0]->name)->toBe('location')
        ->and($tool->arguments[0]->type)->toBe('string');
});

\test('it executes tool callable', function (): void {
    $tool = Tool::fromClosure(
        'add',
        'Add two numbers',
        fn(int $a, int $b) => $a + $b,
    );

    $result = $tool->execute([5, 3]);

    \expect($result)->toBe(8);
});

\test('it generates anthropic schema', function (): void {
    $tool = Tool::fromClosure(
        'get_weather',
        'Get the current weather for a location',
        fn(string $location, bool $include_forecast = false) => 'Weather data',
    );

    $schema = $tool->toAnthropicSchema();

    \expect($schema)->toBeArray()
        ->and($schema['name'])->toBe('get_weather')
        ->and($schema['description'])->toBe('Get the current weather for a location')
        ->and($schema['input_schema']['type'])->toBe('object')
        ->and($schema['input_schema']['properties'])->toHaveKey('location')
        ->and($schema['input_schema']['properties'])->toHaveKey('include_forecast')
        ->and($schema['input_schema']['properties']['location']['type'])->toBe('string')
        ->and($schema['input_schema']['properties']['include_forecast']['type'])->toBe('boolean')
        ->and($schema['input_schema']['required'])->toBe(['location']);
});

\test('it generates openai schema', function (): void {
    $tool = Tool::fromClosure(
        'calculate',
        'Perform a calculation',
        fn(int $x, int $y) => $x * $y,
    );

    $schema = $tool->toOpenAISchema();

    \expect($schema)->toBeArray()
        ->and($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('calculate')
        ->and($schema['function']['description'])->toBe('Perform a calculation')
        ->and($schema['function']['parameters']['type'])->toBe('object')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('x')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('y')
        ->and($schema['function']['parameters']['properties']['x']['type'])->toBe('integer')
        ->and($schema['function']['parameters']['properties']['y']['type'])->toBe('integer')
        ->and($schema['function']['parameters']['required'])->toBe(['x', 'y']);
});

\test('it handles multiple argument types', function (): void {
    $tool = Tool::fromClosure(
        'process_data',
        'Process some data',
        fn(string $name, int $age, float $score, bool $active, array $tags) => 'processed',
    );

    $schema = $tool->toAnthropicSchema();
    $props = $schema['input_schema']['properties'];

    \expect($props['name']['type'])->toBe('string')
        ->and($props['age']['type'])->toBe('integer')
        ->and($props['score']['type'])->toBe('number')
        ->and($props['active']['type'])->toBe('boolean')
        ->and($props['tags']['type'])->toBe('array');
});

\test('it handles nullable and default parameters', function (): void {
    $tool = Tool::fromClosure(
        'greet',
        'Greet someone',
        fn(string $name, ?string $title = null) => "Hello {$title} {$name}",
    );

    $schema = $tool->toAnthropicSchema();

    \expect($schema['input_schema']['required'])->toBe(['name'])
        ->and($tool->arguments[0]->nullable)->toBeFalse()
        ->and($tool->arguments[1]->nullable)->toBeTrue();
});
