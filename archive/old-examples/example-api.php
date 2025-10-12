<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

echo "Pagent - Real API Example\n";
echo "========================\n\n";

// Check for API keys
$hasAnthropic = (bool) getenv('ANTHROPIC_API_KEY');
$hasOpenAI = (bool) getenv('OPENAI_API_KEY');

if (! $hasAnthropic && ! $hasOpenAI) {
    echo "⚠️  No API keys found!\n\n";
    echo "To run this example, set one or both environment variables:\n";
    echo "  export ANTHROPIC_API_KEY=\"your-key-here\"\n";
    echo "  export OPENAI_API_KEY=\"your-key-here\"\n\n";
    exit(1);
}

echo "Available providers:\n";
if ($hasAnthropic) {
    echo "  ✓ Anthropic (Claude)\n";
}
if ($hasOpenAI) {
    echo "  ✓ OpenAI (GPT)\n";
}
echo "\n";

// Example 1: Simple prompt with Anthropic
if ($hasAnthropic) {
    echo "1. Simple Anthropic Example\n";
    echo "----------------------------\n";

    try {
        $claude = anthropic();
        $response = $claude->prompt('Say hello in a friendly way!', [
            'max_tokens' => 50,
            'temperature' => 0.7,
        ]);

        echo "Claude says: {$response->content}\n";
        echo "Model: {$response->model}\n";
        echo "Tokens used: {$response->tokens}\n\n";
    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n\n";
    }
}

// Example 2: Simple prompt with OpenAI
if ($hasOpenAI) {
    echo "2. Simple OpenAI Example\n";
    echo "------------------------\n";

    try {
        $gpt = openai();
        $response = $gpt->prompt('Say hello in a friendly way!', [
            'max_tokens' => 50,
            'temperature' => 0.7,
        ]);

        echo "GPT says: {$response->content}\n";
        echo "Model: {$response->model}\n";
        echo "Tokens used: {$response->tokens}\n\n";
    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n\n";
    }
}

// Example 3: Using agents with conversation history
echo "3. Agent with Conversation History\n";
echo "----------------------------------\n";

$provider = $hasAnthropic ? 'anthropic' : 'openai';
$model = $hasAnthropic ? 'claude-3-haiku-20240307' : 'gpt-3.5-turbo';

agent('assistant')
    ->provider($provider)
    ->model($model)
    ->system('You are a helpful math tutor')
    ->temperature(0.3)
    ->maxTokens(100);

$assistant = agent('assistant');

try {
    echo "User: What is 15 + 27?\n";
    $response1 = $assistant->prompt('What is 15 + 27?');
    echo "Assistant: {$response1->content}\n\n";

    echo "User: Now multiply that result by 2\n";
    $response2 = $assistant->prompt('Now multiply that result by 2');
    echo "Assistant: {$response2->content}\n\n";

    echo "Conversation history ({$provider}):\n";
    foreach ($assistant->messages as $i => $msg) {
        echo "  [{$msg['role']}]: " . mb_substr($msg['content'], 0, 50) . "...\n";
    }
} catch (Exception $e) {
    echo "Error: {$e->getMessage()}\n";
}

echo "\n";

// Example 4: Provider-specific features
if ($hasAnthropic && $hasOpenAI) {
    echo "4. Provider Comparison\n";
    echo "----------------------\n";

    $prompt = "Write a haiku about coding";

    try {
        // Anthropic version
        $claude_response = anthropic()->prompt($prompt, [
            'model' => 'claude-3-haiku-20240307', // Fast model
            'max_tokens' => 100,
            'temperature' => 0.8,
        ]);

        // OpenAI version
        $gpt_response = openai()->prompt($prompt, [
            'model' => 'gpt-3.5-turbo',
            'max_tokens' => 100,
            'temperature' => 0.8,
        ]);

        echo "Claude's haiku:\n{$claude_response->content}\n\n";
        echo "GPT's haiku:\n{$gpt_response->content}\n\n";

    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n";
    }
}

echo "Done! 🎉\n";
