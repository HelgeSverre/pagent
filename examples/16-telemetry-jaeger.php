<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Telemetry: Jaeger Integration ===\n\n";

echo "Prerequisites:\n";
echo "  Start Jaeger with Docker:\n";
echo "  docker run -d --name jaeger \\\n";
echo "    -p 16686:16686 \\\n";
echo "    -p 4318:4318 \\\n";
echo "    jaegertracing/all-in-one:latest\n\n";

// Configure Jaeger telemetry
// This sends traces to Jaeger via OTLP protocol
telemetry_jaeger('http://localhost:4318/v1/traces', 'pagent-examples');

echo "Telemetry configured:\n";
echo "  Exporter: Jaeger\n";
echo "  Endpoint: http://localhost:4318/v1/traces\n";
echo "  Service: pagent-examples\n\n";

// Example 1: Basic agent with tools
echo "=== Example 1: Agent with Math Tools ===\n\n";

agent('calculator')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a helpful calculator. Use the provided tools to perform calculations.')
    ->telemetry(true)
    ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b)
    ->tool('multiply', 'Multiply two numbers', fn (int $a, int $b): int => $a * $b)
    ->tool('subtract', 'Subtract two numbers', fn (int $a, int $b): int => $a - $b);

echo "Q: What is (5 + 3) * 4?\n";
$response = agent('calculator')->prompt('What is (5 + 3) * 4?');
echo "A: {$response->content}\n\n";

// Example 2: Multiple operations
echo "=== Example 2: Complex Calculation ===\n\n";

echo "Q: Calculate (10 + 5) * 3 - 7\n";
$response = agent('calculator')->prompt('Calculate (10 + 5) * 3 - 7');
echo "A: {$response->content}\n\n";

// Example 3: Different agent
echo "=== Example 3: Data Retrieval Agent ===\n\n";

agent('data-agent')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a data retrieval assistant.')
    ->telemetry(true)
    ->tool('get_user', 'Get user information by ID', function (int $id): array {
        // Simulate database lookup
        $users = [
            1 => ['id' => 1, 'name' => 'Alice', 'role' => 'Admin'],
            2 => ['id' => 2, 'name' => 'Bob', 'role' => 'User'],
            3 => ['id' => 3, 'name' => 'Charlie', 'role' => 'Editor'],
        ];

        return $users[$id] ?? ['error' => 'User not found'];
    })
    ->tool('list_users', 'List all users', function (): array {
        return [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
        ];
    });

echo "Q: Get details for user ID 2\n";
$response = agent('data-agent')->prompt('Get details for user ID 2');
echo "A: {$response->content}\n\n";

echo "✅ Traces sent to Jaeger!\n\n";
echo "View traces:\n";
echo "  1. Open http://localhost:16686 in your browser\n";
echo "  2. Select service: pagent-examples\n";
echo "  3. Click 'Find Traces'\n\n";
echo "What to look for:\n";
echo "  - agent.prompt spans with agent.name attribute\n";
echo "  - llm.request spans with provider and model\n";
echo "  - tool.execute spans showing tool usage\n";
echo "  - Parent-child relationships between spans\n";
echo "  - Duration and timing information\n";
