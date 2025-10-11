<?php

declare(strict_types=1);

test('it adds tool to agent', function (): void {
    $agent = testAgent('weather-agent');

    $agent->tool(
        'get_weather',
        'Get the weather for a location',
        fn(string $location) => "The weather in {$location} is sunny",
    );

    $tools = $agent->getTools();

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name)->toBe('get_weather')
        ->and($tools[0]->description)->toBe('Get the weather for a location');
});

test('it executes tool by name', function (): void {
    $agent = testAgent('calc-agent');

    $agent->tool(
        'add',
        'Add two numbers',
        fn(int $a, int $b) => $a + $b,
    );

    $result = $agent->executeTool('add', [10, 5]);

    expect($result)->toBe(15);
});

test('it throws exception for unknown tool', function (): void {
    $agent = testAgent();

    expect(fn() => $agent->executeTool('unknown_tool', []))
        ->toThrow(RuntimeException::class, "Tool 'unknown_tool' not found");
});

test('it supports multiple tools', function (): void {
    $agent = testAgent('multi-tool-agent');

    $agent->tool('add', 'Add numbers', fn(int $a, int $b) => $a + $b);
    $agent->tool('multiply', 'Multiply numbers', fn(int $a, int $b) => $a * $b);
    $agent->tool('greet', 'Greet someone', fn(string $name) => "Hello, {$name}!");

    $tools = $agent->getTools();

    expect($tools)->toHaveCount(3)
        ->and($agent->executeTool('add', [2, 3]))->toBe(5)
        ->and($agent->executeTool('multiply', [4, 5]))->toBe(20)
        ->and($agent->executeTool('greet', ['World']))->toBe('Hello, World!');
});

test('it chains tool configuration', function (): void {
    $agent = testAgent('chained-agent');

    $agent->tool('add', 'Add', fn(int $a, int $b) => $a + $b)
        ->tool('subtract', 'Subtract', fn(int $a, int $b) => $a - $b);

    expect($agent->getTools())->toHaveCount(2);
});
