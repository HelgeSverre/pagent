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
 * Example 1: File Adapter with Context Window
 *
 * This example demonstrates using the File adapter for persistence
 * combined with a context window to limit memory usage.
 * Useful for long conversations where you want to keep only recent context.
 */
echo "\n[Example 1: File Adapter with Context Window]\n";

// Configure agent with File memory adapter and context window
agent('windowed-bot')
    ->provider('mock')
    ->system('You are a helpful assistant.')
    ->memory('file', ['path' => 'storage/sessions'])
    ->sessionId('conversation-1')
    ->contextWindow(200, 'sliding');

$bot = agent('windowed-bot');

echo "Configuration:\n";
echo "- Memory: File adapter (storage/sessions)\n";
echo "- Context window: 200 tokens\n";
echo "- Strategy: sliding (keeps most recent)\n";
echo "- Session ID: conversation-1\n\n";

/**
 * Example 2: Long Conversation Exceeding Window
 *
 * We'll have a conversation that exceeds the token limit.
 * The context window automatically prunes old messages to stay within limits.
 */
echo "\n[Example 2: Context Window in Action]\n";

// Helper function to display message count
$displayStats = function () use ($bot) {
    echo sprintf(
        "Messages in context: %d\n",
        count($bot->messages)
    );
};

// Turn 1
echo "Turn 1:\n";
echo "User: I have a cat named Whiskers who is 3 years old\n";
$r1 = $bot->prompt('I have a cat named Whiskers who is 3 years old');
echo "Bot: {$r1->content}\n";
$displayStats();
echo "\n";

// Turn 2
echo "Turn 2:\n";
echo "User: I also have a dog named Max who loves to play fetch\n";
$r2 = $bot->prompt('I also have a dog named Max who loves to play fetch');
echo "Bot: {$r2->content}\n";
$displayStats();
echo "\n";

// Turn 3
echo "Turn 3:\n";
echo "User: My favorite hobby is photography, especially landscape photography\n";
$r3 = $bot->prompt('My favorite hobby is photography, especially landscape photography');
echo "Bot: {$r3->content}\n";
$displayStats();
echo "\n";

// Turn 4
echo "Turn 4:\n";
echo "User: I work as a software engineer specializing in PHP and Laravel\n";
$r4 = $bot->prompt('I work as a software engineer specializing in PHP and Laravel');
echo "Bot: {$r4->content}\n";
$displayStats();
echo "\n";

// Turn 5
echo "Turn 5:\n";
echo "User: I'm planning a vacation to Japan next month for cherry blossom season\n";
$r5 = $bot->prompt("I'm planning a vacation to Japan next month for cherry blossom season");
echo "Bot: {$r5->content}\n";
$displayStats();
echo "\n";

// Turn 6
echo "Turn 6:\n";
echo "User: I've been learning to play guitar for about six months now\n";
$r6 = $bot->prompt("I've been learning to play guitar for about six months now");
echo "Bot: {$r6->content}\n";
$displayStats();
echo "\n";

/**
 * Example 3: Observing Pruning Effects
 *
 * Ask about earlier information - it may have been pruned.
 */
echo "\n[Example 3: Observing Pruning Effects]\n";

echo "Asking about information from early turns...\n\n";

echo "User: What's my cat's name?\n";
$r7 = $bot->prompt("What's my cat's name?");
echo "Bot: {$r7->content}\n";
echo "(Earlier messages may have been pruned to fit context window)\n\n";

$displayStats();
echo "\n";

/**
 * Example 4: Inspecting Current Window
 *
 * Let's see what messages are currently in the context window.
 */
echo "\n[Example 4: Current Context Window]\n";

echo "Messages currently in memory:\n";
echo str_repeat('-', 70)."\n";

foreach ($bot->messages as $i => $message) {
    $role = $message['role'];
    $content = is_string($message['content'])
        ? $message['content']
        : json_encode($message['content']);

    // Truncate for display
    $truncated = mb_strlen($content) > 50
        ? mb_substr($content, 0, 50).'...'
        : $content;

    echo sprintf("[%d] %s: %s\n", $i + 1, ucfirst($role), $truncated);
}

echo str_repeat('-', 70)."\n";
echo 'Total: '.count($bot->messages)." messages\n";
echo "(Older messages were pruned to stay within 200 token limit)\n\n";

/**
 * Example 5: Persistence with File Adapter
 *
 * Verify that messages are saved to disk.
 */
echo "\n[Example 5: File Persistence]\n";

$sessionFile = 'storage/sessions/'.hash('sha256', 'conversation-1').'.json';
echo "Session file: {$sessionFile}\n";

if (file_exists($sessionFile)) {
    $fileSize = filesize($sessionFile);
    echo "File size: {$fileSize} bytes\n";
    echo "File exists: Yes\n\n";

    // Create new instance to test loading
    agent('reload-test')
        ->provider('mock')
        ->system('You are a helpful assistant.')
        ->memory('file', ['path' => 'storage/sessions'])
        ->sessionId('conversation-1')
        ->contextWindow(200, 'sliding');

    $reloadedBot = agent('reload-test');

    echo "Created new agent instance with same session ID\n";
    echo 'Messages auto-loaded: '.count($reloadedBot->messages)."\n\n";

    echo "Context successfully persisted and reloaded!\n\n";
} else {
    echo "File not found (storage directory may not exist)\n\n";
}

/**
 * Example 6: Different Pruning Strategy
 *
 * Demonstrate the 'oldest' strategy which removes oldest messages first.
 */
echo "\n[Example 6: Alternative Pruning Strategy]\n";

agent('oldest-strategy-bot')
    ->provider('mock')
    ->system('You are a test assistant.')
    ->memory('file', ['path' => 'storage/sessions'])
    ->sessionId('conversation-2')
    ->contextWindow(150, 'oldest');

$strategyBot = agent('oldest-strategy-bot');

echo "Strategy: oldest (removes oldest messages first)\n";
echo "Max tokens: 150\n\n";

// Add several messages
$strategyBot->prompt('First message about computers');
$strategyBot->prompt('Second message about programming');
$strategyBot->prompt('Third message about databases');
$strategyBot->prompt('Fourth message about web development');
$strategyBot->prompt('Fifth message about testing');

echo "After 5 turns:\n";
echo 'Messages: '.count($strategyBot->messages)."\n\n";

echo "Oldest messages (after system) are removed first to stay under limit.\n\n";

echo "\nNote: Session files stored in storage/sessions/\n";

echo "\nDone.\n";
