<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Ollama Basic Usage Examples ===\n\n";
echo "Prerequisites: Ollama server running with qwen3:8b model\n";
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
    echo "Please start Ollama server with: ollama serve\n";
    exit(1);
}

echo "✅ Ollama server is running\n\n";

// Example 1: Simple question
echo "=== Example 1: Simple Question ===\n\n";

agent('ollama-assistant')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a helpful AI assistant. Be concise and clear.')
    ->temperature(0.7);

$response = agent('ollama-assistant')->prompt('What is dependency injection in PHP?');

echo "Q: What is dependency injection in PHP?\n";
echo "A: {$response->content}\n";
echo "Tokens used: {$response->tokens}\n\n";

// Example 2: Conversation with context
echo "=== Example 2: Multi-Turn Conversation ===\n\n";

agent('ollama-chat')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a friendly chatbot.');

$chat = agent('ollama-chat');

echo "User: Hello, my name is Bob.\n";
$response1 = $chat->prompt('Hello, my name is Bob.');
echo "Bot: {$response1->content}\n\n";

echo "User: What's my name?\n";
$response2 = $chat->prompt("What's my name?");
echo "Bot: {$response2->content}\n\n";

// Example 3: Temperature control
echo "=== Example 3: Temperature Control ===\n\n";

echo "Low temperature (0.0) - deterministic:\n";
agent('ollama-temp-low')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->temperature(0);

$response = agent('ollama-temp-low')->prompt('Count from 1 to 5');
echo "Response: {$response->content}\n\n";

echo "High temperature (1.0) - creative:\n";
agent('ollama-temp-high')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->temperature(1);

$response = agent('ollama-temp-high')->prompt('Write a creative sentence about coding');
echo "Response: {$response->content}\n\n";

// Example 4: Custom configuration
echo "=== Example 4: Custom Configuration ===\n\n";

$provider = ollama([
    'base_url' => 'http://localhost:11434',
    'timeout' => 120,
]);

agent('ollama-custom')
    ->provider($provider)
    ->model('qwen3:8b')
    ->maxTokens(100);

$response = agent('ollama-custom')->prompt('Explain PHP in one sentence');
echo "Response: {$response->content}\n";
echo "Model: {$response->model}\n";
echo "Provider: {$response->provider}\n\n";

echo "✅ All examples completed!\n";
echo "\nNext steps:\n";
echo "- Try examples/13-ollama-streaming.php for streaming examples\n";
echo "- Try examples/14-ollama-tools.php for tool calling examples\n";
