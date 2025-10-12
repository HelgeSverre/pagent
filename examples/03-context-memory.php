<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
if (\file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

/**
 * Example 1: Conversation with Context.
 */
echo "=== Example 1: Conversation with Context ===\n\n";

$agent = \agent('contextual-assistant')
    ->provider('openai')
    ->system('You are a helpful assistant that remembers conversation context.')
    ->temperature(0.7);

$bot = \agent('contextual-assistant');

echo "Turn 1:\n";
echo "User: My favorite color is blue\n";
$r1 = $bot->prompt('My favorite color is blue');
echo "Bot: {$r1->content}\n\n";

echo "Turn 2:\n";
echo "User: What is my favorite color?\n";
$r2 = $bot->prompt('What is my favorite color?');
echo "Bot: {$r2->content}\n\n";

echo "Turn 3:\n";
echo "User: I also like programming in PHP\n";
$r3 = $bot->prompt('I also like programming in PHP');
echo "Bot: {$r3->content}\n\n";

echo "Turn 4:\n";
echo "User: What do I like?\n";
$r4 = $bot->prompt('What do I like?');
echo "Bot: {$r4->content}\n\n";

/**
 * Example 2: Role-Playing with Context.
 */
echo "=== Example 2: Role-Playing Scenario ===\n\n";

$character = \agent('dungeon-master')
    ->provider('openai')
    ->system('You are a dungeon master running a fantasy adventure. Be creative and engaging.')
    ->temperature(0.9);

$dm = \agent('dungeon-master');

echo "Player: I enter the dark cave\n";
$r1 = $dm->prompt('I enter the dark cave');
echo "DM: {$r1->content}\n\n";

echo "Player: I light a torch\n";
$r2 = $dm->prompt('I light a torch');
echo "DM: {$r2->content}\n\n";

/**
 * Example 3: Customer Support with Context.
 */
echo "=== Example 3: Customer Support ===\n\n";

$support = \agent('support-bot')
    ->provider('openai')
    ->system('You are a customer support agent. Be helpful and empathetic.')
    ->temperature(0.6);

$agent = \agent('support-bot');

echo "Customer: My order hasn't arrived yet\n";
$r1 = $agent->prompt("My order hasn't arrived yet");
echo "Support: {$r1->content}\n\n";

echo "Customer: Order #12345\n";
$r2 = $agent->prompt('Order #12345');
echo "Support: {$r2->content}\n\n";

echo "Customer: When should I expect it?\n";
$r3 = $agent->prompt('When should I expect it?');
echo "Support: {$r3->content}\n\n";

/**
 * Example 4: Inspecting Message History.
 */
echo "=== Example 4: Message History ===\n\n";

$testAgent = \agent('history-test')
    ->provider('openai')
    ->system('You are a test assistant');

$agent = \agent('history-test');
$agent->prompt('Hello');
$agent->prompt('How are you?');
$agent->prompt('Goodbye');

echo "Conversation history:\n";
foreach ($agent->messages as $i => $message) {
    $role = $message['role'];
    $content = \is_string($message['content']) ? $message['content'] : \json_encode($message['content']);
    echo \sprintf("[%d] %s: %s\n", $i + 1, \ucfirst($role), \mb_substr($content, 0, 100));
}

echo "\nTotal messages: ".\count($agent->messages)."\n\n";

echo "✅ All context/memory examples completed!\n";
