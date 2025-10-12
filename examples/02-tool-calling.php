<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (\file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Example 1: Calculator Tool ===\n\n";

\agent('math-assistant')
    ->provider('openai')
    ->system('You are a helpful math assistant.')
    ->tool('calculate', 'Perform basic arithmetic. Operation can be: add, subtract, multiply, or divide', function (string $operation, int $a, int $b): int|float {
        return match (\mb_strtolower($operation)) {
            'add', 'addition' => $a + $b,
            'subtract', 'subtraction' => $a - $b,
            'multiply', 'multiplication' => $a * $b,
            'divide', 'division' => $b !== 0 ? $a / $b : throw new RuntimeException('Division by zero'),
            default => throw new RuntimeException("Unknown operation: {$operation}. Use add, subtract, multiply, or divide."),
        };
    });

$response = \agent('math-assistant')->prompt('What is 127 times 43?');
echo "Q: What is 127 times 43?\n";
echo "A: {$response->content}\n\n";

echo "=== Example 2: Weather Tool ===\n\n";

\agent('weather-assistant')
    ->provider('openai')
    ->system('You are a helpful weather assistant.')
    ->tool('get_weather', 'Get current weather for a city', function (string $city, bool $include_forecast = false): string {
        $weather = [
            'Oslo' => ['temp' => 15, 'condition' => 'Cloudy'],
            'London' => ['temp' => 18, 'condition' => 'Rainy'],
            'Tokyo' => ['temp' => 25, 'condition' => 'Sunny'],
        ];

        $data = $weather[$city] ?? ['temp' => 20, 'condition' => 'Clear'];
        $result = "Current weather in {$city}: {$data['temp']}°C, {$data['condition']}";

        if ($include_forecast) {
            $result .= "\nForecast: Partly cloudy for the next 3 days.";
        }

        return $result;
    });

$response = \agent('weather-assistant')->prompt('What is the weather like in Oslo?');
echo "Q: What is the weather like in Oslo?\n";
echo "A: {$response->content}\n\n";

echo "=== Example 3: Multiple Tools ===\n\n";

\agent('multi-tool-assistant')
    ->provider('openai')
    ->system('You are a helpful assistant with access to various tools.')
    ->tool('get_time', 'Get current time', fn (string $timezone = 'UTC'): string => "Current time in {$timezone}: ".\date('H:i:s'))
    ->tool('random_number', 'Generate random number', fn (int $min = 1, int $max = 100): int => \rand($min, $max))
    ->tool('reverse_string', 'Reverse a string', fn (string $text): string => \strrev($text));

$response = \agent('multi-tool-assistant')->prompt('Generate a random number between 1 and 50, then tell me the current time.');
echo "Q: Generate a random number between 1 and 50, then tell me the current time.\n";
echo "A: {$response->content}\n\n";

echo "=== Example 4: Anthropic Tool Calling ===\n\n";

if (! empty($_ENV['ANTHROPIC_API_KEY'] ?? \getenv('ANTHROPIC_API_KEY'))) {
    \agent('claude-calculator')
        ->provider('anthropic')
        ->system('You are a helpful assistant.')
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b)
        ->tool('multiply', 'Multiply two numbers', fn (int $a, int $b): int => $a * $b);

    $response = \agent('claude-calculator')->prompt('What is 25 + 17, and then multiply the result by 3?');
    echo "Q: What is 25 + 17, and then multiply the result by 3?\n";
    echo "A: {$response->content}\n\n";
} else {
    echo "Skipped (no ANTHROPIC_API_KEY)\n\n";
}

echo "✅ All tool calling examples completed!\n";
