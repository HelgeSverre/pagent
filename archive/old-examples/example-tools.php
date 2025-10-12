<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pagent\Tool\Tool;

// Example 1: Creating a simple tool from a closure
$weatherTool = Tool::fromClosure(
    'get_weather',
    'Get the current weather for a location',
    fn (string $location, bool $include_forecast = false) => "The weather in {$location} is sunny. Forecast: " . ($include_forecast ? 'Clear skies ahead!' : 'N/A'),
);

echo "Tool: {$weatherTool->name}\n";
echo "Description: {$weatherTool->description}\n";
echo "\nExecuting tool:\n";
echo $weatherTool->execute(['Oslo', true]) . "\n";

// Example 2: Anthropic schema generation
echo "\n--- Anthropic Schema ---\n";
print_r($weatherTool->toAnthropicSchema());

// Example 3: OpenAI schema generation
echo "\n--- OpenAI Schema ---\n";
print_r($weatherTool->toOpenAISchema());

// Example 4: Adding tools to an agent
echo "\n--- Agent with Tools ---\n";

$agent = agent('assistant')
    ->provider('mock')
    ->tool(
        'calculate',
        'Perform mathematical calculations',
        fn (int $a, int $b, string $operation = 'add') => match ($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => 0 !== $b ? $a / $b : 'Error: Division by zero',
            default => 'Unknown operation',
        },
    )
    ->tool(
        'get_time',
        'Get the current time',
        fn (string $timezone = 'UTC') => "Current time in {$timezone}: " . date('Y-m-d H:i:s'),
    );

// Don't call build() - just continue with the builder
$finalAgent = $agent->build();

echo "Agent '{$finalAgent->getName()}' has " . count($finalAgent->getTools()) . " tools:\n";

foreach ($finalAgent->getTools() as $tool) {
    echo "  - {$tool->name}: {$tool->description}\n";
}

// Example 5: Executing tools
echo "\n--- Tool Execution ---\n";
echo "Calculate 10 + 5: " . $finalAgent->executeTool('calculate', [10, 5, 'add']) . "\n";
echo "Calculate 10 * 5: " . $finalAgent->executeTool('calculate', [10, 5, 'multiply']) . "\n";
echo $finalAgent->executeTool('get_time', ['Europe/Oslo']) . "\n";

// Example 6: Complex tool with multiple types
$processTool = Tool::fromClosure(
    'process_user',
    'Process user data and generate a report',
    fn (string $name, int $age, float $score, bool $active, array $tags) => [
        'user' => $name,
        'age' => $age,
        'score' => $score,
        'status' => $active ? 'active' : 'inactive',
        'tags' => implode(', ', $tags),
        'processed_at' => date('Y-m-d H:i:s'),
    ],
);

echo "\n--- Complex Tool Schema (Anthropic) ---\n";
print_r($processTool->toAnthropicSchema());

echo "\n--- Executing Complex Tool ---\n";
$result = $processTool->execute(['John Doe', 30, 95.5, true, ['admin', 'developer']]);
print_r($result);
