<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;
use Pagent\Tools\FileRead;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Ollama Tool Calling Examples ===\n\n";
echo "Prerequisites: Ollama server running with qwen3:8b model\n";
echo "Note: Tool calling requires compatible models (qwen3:8b, llama3.1, mistral)\n";
echo "Run: ollama pull qwen3:8b && ollama serve\n\n";

// Check if Ollama is available
function checkOllama(): bool
{
    $ch = curl_init('http://localhost:11434/api/version');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $response !== false && $httpCode === 200;
}

if (! checkOllama()) {
    echo "❌ Ollama server not available at http://localhost:11434\n";
    exit(1);
}

echo "✅ Ollama server is running\n\n";

// Example 1: Simple calculator tool
echo "=== Example 1: Simple Calculator Tool ===\n\n";

agent('ollama-calculator')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a helpful assistant. Use the provided tools to answer questions.')
    ->tool('add', 'Add two numbers together', fn (int $a, int $b): int => $a + $b);

$response = agent('ollama-calculator')->prompt('What is 42 + 15?');
echo "Q: What is 42 + 15?\n";
echo "A: {$response->content}\n\n";

// Example 2: Multiple math tools
echo "=== Example 2: Multiple Math Tools ===\n\n";

agent('ollama-math')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a math assistant. Use the tools to perform calculations.')
    ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b)
    ->tool('subtract', 'Subtract two numbers', fn (int $a, int $b): int => $a - $b)
    ->tool('multiply', 'Multiply two numbers', fn (int $a, int $b): int => $a * $b)
    ->tool('divide', 'Divide two numbers', function (int $a, int $b): float {
        if ($b === 0) {
            throw new \RuntimeException('Cannot divide by zero');
        }

        return $a / $b;
    });

$response = agent('ollama-math')->prompt('Calculate (10 + 5) * 3');
echo "Q: Calculate (10 + 5) * 3\n";
echo "A: {$response->content}\n\n";

// Example 3: Data retrieval tool
echo "=== Example 3: Data Retrieval Tool ===\n\n";

agent('ollama-data')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a data assistant. Use tools to retrieve information.')
    ->tool('get_user', 'Get user information by ID', function (int $id): array {
        // Simulate database lookup
        $users = [
            1 => ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'Admin'],
            2 => ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'User'],
            3 => ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com', 'role' => 'Editor'],
        ];

        return $users[$id] ?? ['error' => 'User not found'];
    });

$response = agent('ollama-data')->prompt('Get the details for user ID 2');
echo "Q: Get the details for user ID 2\n";
echo "A: {$response->content}\n\n";

// Example 4: Weather tool with optional parameters
echo "=== Example 4: Tool with Optional Parameters ===\n\n";

agent('ollama-weather')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a weather assistant.')
    ->tool('get_weather', 'Get weather for a city', function (string $city, bool $include_forecast = false): array {
        // Simulate weather API
        $weather = [
            'Paris' => ['temp' => 18, 'condition' => 'Sunny'],
            'London' => ['temp' => 12, 'condition' => 'Cloudy'],
            'Tokyo' => ['temp' => 22, 'condition' => 'Clear'],
        ];

        $result = $weather[$city] ?? ['temp' => 20, 'condition' => 'Unknown'];

        if ($include_forecast) {
            $result['forecast'] = ['Tomorrow: '.($result['temp'] + 2).'°C', 'Next day: '.($result['temp'] - 1).'°C'];
        }

        return $result;
    });

$response = agent('ollama-weather')->prompt('What is the weather in Tokyo with forecast?');
echo "Q: What is the weather in Tokyo with forecast?\n";
echo "A: {$response->content}\n\n";

// Example 5: Built-in FileRead tool
echo "=== Example 5: Built-in FileRead Tool ===\n\n";

// Create a test file
$testFile = sys_get_temp_dir().'/ollama_test.txt';
file_put_contents($testFile, "This is a test file for Ollama tool calling examples.\nIt demonstrates reading files using the built-in FileRead tool.");

agent('ollama-file-reader')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a file assistant. Use tools to read files.')
    ->tool(new FileRead);

$response = agent('ollama-file-reader')->prompt("Read the file at {$testFile} and tell me what it contains");
echo "Q: Read the file and tell me what it contains\n";
echo "A: {$response->content}\n\n";

// Cleanup
@unlink($testFile);

// Example 6: Multi-turn conversation with tools
echo "=== Example 6: Multi-Turn Conversation with Tools ===\n\n";

agent('ollama-assistant')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a helpful assistant with access to calculation tools.')
    ->tool('add', 'Add numbers', fn (int $a, int $b): int => $a + $b)
    ->tool('multiply', 'Multiply numbers', fn (int $a, int $b): int => $a * $b);

$agent = agent('ollama-assistant');

echo "Turn 1:\n";
$response1 = $agent->prompt('What is 5 + 3?');
echo "User: What is 5 + 3?\n";
echo "Agent: {$response1->content}\n\n";

echo "Turn 2:\n";
$response2 = $agent->prompt('Now multiply that result by 2');
echo "User: Now multiply that result by 2\n";
echo "Agent: {$response2->content}\n\n";

// Example 7: Error handling in tools
echo "=== Example 7: Tool Error Handling ===\n\n";

agent('ollama-safe-divide')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a math assistant.')
    ->tool('divide', 'Divide two numbers', function (int $a, int $b): float {
        if ($b === 0) {
            throw new \RuntimeException('Error: Cannot divide by zero!');
        }

        return $a / $b;
    });

try {
    $response = agent('ollama-safe-divide')->prompt('What is 10 divided by 0?');
    echo "Q: What is 10 divided by 0?\n";
    echo "A: {$response->content}\n\n";
} catch (\RuntimeException $e) {
    echo "Tool error was handled: {$e->getMessage()}\n\n";
}

echo "✅ All tool calling examples completed!\n";
echo "\nKey points:\n";
echo "- Ollama uses OpenAI-compatible tool schema format\n";
echo "- Compatible models: qwen3:8b (recommended), llama3.1, mistral\n";
echo "- Tools can return strings, arrays, or throw exceptions\n";
echo "- Multi-turn conversations maintain context with tool results\n";
