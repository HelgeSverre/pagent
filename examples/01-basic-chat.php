<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

if (\file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "=== Example 1: Basic Chat ===\n\n";

\agent('assistant')
    ->provider('openai')
    ->system('You are a helpful assistant. Be concise.')
    ->temperature(0.7);

$response = \agent('assistant')->prompt('What is PHP?');
echo "Q: What is PHP?\n";
echo "A: {$response->content}\n\n";

echo "=== Example 2: Conversation with Memory ===\n\n";

\agent('chatbot')
    ->provider('openai')
    ->system('You are a friendly chatbot.')
    ->temperature(0.8);

$bot = \agent('chatbot');

echo "User: Hi, my name is Alice\n";
$response1 = $bot->prompt('Hi, my name is Alice');
echo "Bot: {$response1->content}\n\n";

echo "User: What's my name?\n";
$response2 = $bot->prompt("What's my name?");
echo "Bot: {$response2->content}\n\n";

echo "=== Example 3: Using Anthropic ===\n\n";

if (! empty($_ENV['ANTHROPIC_API_KEY'] ?? \getenv('ANTHROPIC_API_KEY'))) {
    \agent('claude')
        ->provider('anthropic')
        ->system('You are Claude, an AI assistant.')
        ->temperature(0.7);

    $response = \agent('claude')->prompt('Write a haiku about PHP');
    echo "Claude's haiku:\n{$response->content}\n\n";
} else {
    echo "Skipped (no ANTHROPIC_API_KEY)\n\n";
}

echo "=== Example 4: Mock Provider ===\n\n";

\agent('mock-bot')
    ->provider('mock')
    ->system('You are a test bot');

$response = \agent('mock-bot')->prompt('Hello!');
echo "Mock response: {$response->content}\n\n";

echo "✅ All examples completed!\n";
