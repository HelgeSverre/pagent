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
 * Example 1: Basic SQLite Persistence
 *
 * This example demonstrates how to persist conversation history using SQLite.
 * Messages are automatically saved and loaded based on the session ID.
 */
echo "=== Example 1: Basic SQLite Persistence ===\n\n";

// Configure agent with SQLite memory adapter
agent('persistent-bot')
    ->provider('mock')
    ->system('You are a helpful assistant with memory.')
    ->memory('sqlite', ['path' => 'storage/examples.db'])
    ->sessionId('user-123');

$bot = agent('persistent-bot');

echo "Starting new conversation...\n";
echo "Session ID: user-123\n";
echo 'Messages before: '.count($bot->messages)."\n\n";

// Turn 1: Introduce yourself
echo "Turn 1:\n";
echo "User: Hi, my name is Alice and I love PHP programming\n";
$r1 = $bot->prompt('Hi, my name is Alice and I love PHP programming');
echo "Bot: {$r1->content}\n";
echo 'Messages after turn 1: '.count($bot->messages)."\n\n";

// Turn 2: Ask a follow-up question
echo "Turn 2:\n";
echo "User: What programming language do I like?\n";
$r2 = $bot->prompt('What programming language do I like?');
echo "Bot: {$r2->content}\n";
echo 'Messages after turn 2: '.count($bot->messages)."\n\n";

// Turn 3: Add more context
echo "Turn 3:\n";
echo "User: I'm working on a project using Laravel\n";
$r3 = $bot->prompt("I'm working on a project using Laravel");
echo "Bot: {$r3->content}\n";
echo 'Messages after turn 3: '.count($bot->messages)."\n\n";

/**
 * Example 2: Simulating Script Restart
 *
 * This demonstrates persistence across script runs.
 * When we create a new agent instance with the same session ID,
 * the previous conversation is automatically loaded.
 */
echo "=== Example 2: Simulating Script Restart ===\n\n";

echo "Simulating script restart (creating new agent instance)...\n\n";

// Create a completely new agent instance
agent('reloaded-bot')
    ->provider('mock')
    ->system('You are a helpful assistant with memory.')
    ->memory('sqlite', ['path' => 'storage/examples.db'])
    ->sessionId('user-123');  // Same session ID

$reloadedBot = agent('reloaded-bot');

echo "New agent instance created\n";
echo "Session ID: user-123\n";
echo 'Messages loaded: '.count($reloadedBot->messages)."\n\n";

// The conversation history is automatically loaded!
// We can continue the conversation where we left off
echo "Turn 4 (after restart):\n";
echo "User: What's my name and what project am I working on?\n";
$r4 = $reloadedBot->prompt("What's my name and what project am I working on?");
echo "Bot: {$r4->content}\n";
echo 'Messages after turn 4: '.count($reloadedBot->messages)."\n\n";

/**
 * Example 3: Inspecting Message History
 *
 * Let's examine what was actually persisted in the database.
 */
echo "=== Example 3: Inspecting Message History ===\n\n";

echo "Complete conversation history:\n";
echo str_repeat('-', 70)."\n";

foreach ($reloadedBot->messages as $i => $message) {
    $role = $message['role'];
    $content = is_string($message['content'])
        ? $message['content']
        : json_encode($message['content']);

    // Truncate long messages for display
    $truncated = mb_strlen($content) > 60
        ? mb_substr($content, 0, 60).'...'
        : $content;

    echo sprintf("[%d] %s: %s\n", $i + 1, ucfirst($role), $truncated);
}

echo str_repeat('-', 70)."\n";
echo 'Total messages persisted: '.count($reloadedBot->messages)."\n\n";

/**
 * Example 4: Starting Fresh Session
 *
 * Using a different session ID creates an independent conversation.
 */
echo "=== Example 4: Starting Fresh Session ===\n\n";

agent('fresh-bot')
    ->provider('mock')
    ->system('You are a helpful assistant.')
    ->memory('sqlite', ['path' => 'storage/examples.db'])
    ->sessionId('user-456');  // Different session ID

$freshBot = agent('fresh-bot');

echo "New session started\n";
echo "Session ID: user-456\n";
echo 'Messages: '.count($freshBot->messages)." (fresh start)\n\n";

echo "User: What's my name?\n";
$r5 = $freshBot->prompt("What's my name?");
echo "Bot: {$r5->content}\n";
echo "(Bot doesn't know - different session!)\n\n";

/**
 * Example 5: Context Preservation
 *
 * Verify that the original session still has its context intact.
 */
echo "=== Example 5: Context Preservation ===\n\n";

agent('verify-bot')
    ->provider('mock')
    ->system('You are a helpful assistant with memory.')
    ->memory('sqlite', ['path' => 'storage/examples.db'])
    ->sessionId('user-123');  // Back to original session

$verifyBot = agent('verify-bot');

echo "Switched back to original session\n";
echo "Session ID: user-123\n";
echo 'Messages loaded: '.count($verifyBot->messages)."\n\n";

echo "User: Remind me what we talked about\n";
$r6 = $verifyBot->prompt('Remind me what we talked about');
echo "Bot: {$r6->content}\n\n";

echo 'Context is preserved! All '.(count($verifyBot->messages) - 1)." previous messages are available.\n\n";

echo "✅ All SQLite persistence examples completed!\n";
echo "\nNote: Conversation data persisted in storage/examples.db\n";
