<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

/**
 * Example 1: Comparing Providers.
 */
echo "\n[Example 1: Comparing Providers]\n";

$prompt = 'Write a very short poem about PHP (max 2 lines)';

// OpenAI
$gpt = agent('gpt-poet')
    ->provider('openai')
    ->model('gpt-3.5-turbo')
    ->temperature(0.8);

echo "OpenAI (GPT-3.5):\n";
$gptResponse = agent('gpt-poet')->prompt($prompt);
echo "{$gptResponse->content}\n";
echo "Tokens: {$gptResponse->tokens}\n\n";

// Anthropic
if (! empty($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'))) {
    $claude = agent('claude-poet')
        ->provider('anthropic')
        ->model('claude-sonnet-4-6')
        ->temperature(0.8)
        ->maxTokens(100);

    echo "Anthropic (Claude):\n";
    $claudeResponse = agent('claude-poet')->prompt($prompt);
    echo "{$claudeResponse->content}\n";
    echo "Tokens: {$claudeResponse->tokens}\n\n";
}

// Mock
$mock = agent('mock-poet')
    ->provider('mock')
    ->temperature(0.8);

echo "Mock Provider:\n";
$mockResponse = agent('mock-poet')->prompt($prompt);
echo "{$mockResponse->content}\n\n";

/**
 * Example 2: Provider-Specific Features.
 */
echo "\n[Example 2: Provider-Specific Features]\n";

// OpenAI with specific parameters
$gptAdvanced = agent('gpt-advanced')
    ->provider('openai')
    ->model('gpt-4')
    ->temperature(0.5)
    ->maxTokens(150);

echo "OpenAI GPT-4 with specific config:\n";
$response = agent('gpt-advanced')->prompt('Explain quantum computing in one sentence.');
echo "{$response->content}\n";
echo "Model: {$response->model}\n\n";

/**
 * Example 3: Different Models for Different Tasks.
 */
echo "\n[Example 3: Task-Specific Agents]\n";

// Fast agent for simple tasks
$quickBot = agent('quick-bot')
    ->provider('openai')
    ->model('gpt-3.5-turbo')
    ->temperature(0.3)
    ->maxTokens(50);

echo "Quick Bot (fast, simple):\n";
$r1 = agent('quick-bot')->prompt('What is 2+2?');
echo "{$r1->content}\n\n";

// Creative agent
$creativeBot = agent('creative-bot')
    ->provider('openai')
    ->model('gpt-3.5-turbo')
    ->temperature(0.9)
    ->maxTokens(200);

echo "Creative Bot (high temperature):\n";
$r2 = agent('creative-bot')->prompt('Invent a creative name for a PHP package.');
echo "{$r2->content}\n\n";

// Precise agent
$preciseBot = agent('precise-bot')
    ->provider('openai')
    ->model('gpt-3.5-turbo')
    ->temperature(0.1)
    ->maxTokens(100);

echo "Precise Bot (low temperature):\n";
$r3 = agent('precise-bot')->prompt('What is the capital of France?');
echo "{$r3->content}\n\n";

/**
 * Example 4: Switching Providers at Runtime.
 */
echo "\n[Example 4: Runtime Provider Selection]\n";

function createAgent(string $provider): void
{
    $config = match ($provider) {
        'openai' => ['provider' => 'openai', 'model' => 'gpt-3.5-turbo'],
        'anthropic' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        default => ['provider' => 'mock'],
    };

    agent('dynamic-agent')
        ->provider($config['provider'])
        ->system('You are a helpful assistant');

    if (isset($config['model'])) {
        agent('dynamic-agent')->model($config['model']);
    }
}

// Use OpenAI
createAgent('openai');
$response = agent('dynamic-agent')->prompt('Hello!');
echo "Provider: {$response->provider}\n";
echo "Response: {$response->content}\n\n";

echo "\nDone.\n";
