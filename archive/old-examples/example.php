<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Example 1: Basic agent with mock provider
agent('customer-support')
    ->provider('mock', ['responses' => [
        'hello' => 'Hello! How can I help you today?',
        'refund' => 'I can help with your refund. Please provide your order number.',
    ]])
    ->system('You are a helpful customer support agent')
    ->temperature(0.7);

// Example 2: Use the agent
$response = agent('customer-support')->prompt('hello');
echo "Response: {$response->content}\n";
echo "Tokens: {$response->tokens}\n\n";

// Example 3: Direct provider usage (leaky abstraction)
$mock = mock([
    'What is 2+2?' => '4',
    'What is the capital of France?' => 'Paris',
]);

$response = $mock->prompt('What is 2+2?');
echo "Direct mock: {$response->content}\n\n";

// Example 4: Agent with conversation history
agent('chat-bot')
    ->provider('mock')
    ->system('You are a friendly chatbot');

// Get the agent after it's registered
$chatAgent = agent('chat-bot');

// Messages are tracked automatically
$chatAgent->prompt('Hi there!');
$chatAgent->prompt('What is your name?');
$chatAgent->prompt('Nice to meet you!');

echo "Conversation history:\n";
foreach ($chatAgent->messages as $msg) {
    echo "  [{$msg['role']}]: {$msg['content']}\n";
}
echo "\n";

// Example 5: OpenAI-style agent (would use real API in production)
agent('writer')
    ->provider('openai', ['api_key' => 'your-key-here'])
    ->model('gpt-4')
    ->temperature(0.8)
    ->maxTokens(2000);

// Example 6: Anthropic-style agent (would use real API in production)
agent('analyst')
    ->provider('anthropic', ['api_key' => 'your-key-here'])
    ->model('claude-3-opus')
    ->system('You are a data analyst expert')
    ->temperature(0.3);

// Example 7: List all registered agents
echo "Registered agents:\n";
foreach (agents() as $name => $agent) {
    echo "  - {$name}\n";
}
echo "\n";

// Example 8: Provider-specific features (intentionally leaky)
// In real usage, providers can expose their unique features
$anthropic = anthropic(['api_key' => getenv('ANTHROPIC_API_KEY') ?: 'test']);
$response = $anthropic->prompt('Hello', [
    'system' => 'You are Claude',
    'model' => 'claude-3-opus',
    'max_tokens' => 1000,
    'temperature' => 0.5,
    // Anthropic-specific options would go here
]);

echo "Anthropic response: {$response->content}\n";

// Clear all agents when done
clearAgents();
echo "All agents cleared!\n";
