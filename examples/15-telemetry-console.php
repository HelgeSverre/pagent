<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "\n[Telemetry: Console Output (Basic)]\n";

// Enable console telemetry for debugging
// This will output all spans to the console with detailed information
telemetry_console(verbose: true);

echo "Telemetry enabled with console exporter (verbose mode)\n";
echo "Watch for span output after the agent response\n\n";

// Create agent with telemetry enabled
agent('assistant')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful assistant. Be concise.')
    ->telemetry(true);

// Example 1: Simple prompt
echo "\n[Example 1: Simple Prompt]\n";

$response = agent('assistant')->prompt('What is 2+2?');
echo "Input: What is 2+2?\n";
echo "Output: {$response->content}\n\n";

echo "[Console Telemetry Output Above]\n";
echo "You should see spans showing:\n";
echo "- agent.prompt operation\n";
echo "- llm.request with provider and model\n";
echo "- Duration, status, and attributes\n\n";

// Example 2: Multiple turns
echo "\n[Example 2: Multi-Turn Conversation]\n";

$agent = agent('assistant');

$response1 = $agent->prompt('My name is Alice');
echo "Turn 1: My name is Alice\n";
echo "Response: {$response1->content}\n\n";

$response2 = $agent->prompt('What is my name?');
echo "Turn 2: What is my name?\n";
echo "Response: {$response2->content}\n\n";

echo "[Console Telemetry Output Above]\n";
echo "You should see separate spans for each turn\n";
echo "Each turn creates its own agent.prompt → llm.request trace\n\n";

// Example 3: Different verbose settings
echo "\n[Example 3: Switching to Non-Verbose Mode]\n";

telemetry_console(verbose: false);

$response3 = $agent->prompt('Tell me a fun fact');
echo "Input: Tell me a fun fact\n";
echo "Output: {$response3->content}\n\n";

echo "[Console Telemetry Output Above]\n";
echo "Non-verbose mode shows:\n";
echo "- Span names and durations\n";
echo "- Less detailed attribute information\n";
echo "- Better for production monitoring\n\n";

echo "Key points:\n";
echo "- telemetry_console() enables console output\n";
echo "- verbose: true shows all span attributes\n";
echo "- verbose: false shows minimal information\n";
echo "- Great for debugging and understanding trace structure\n";
echo "- Use console exporter for local development\n";

echo "\nDone.\n";
