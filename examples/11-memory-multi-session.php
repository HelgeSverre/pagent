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
 * Example 1: Multiple Concurrent Sessions
 *
 * This example demonstrates managing multiple independent conversations
 * simultaneously. Each session maintains its own isolated context.
 * Perfect for multi-user applications where each user has their own chat history.
 */
echo "\n[Example 1: Multiple Concurrent Sessions]\n";

echo "Setting up 3 independent user sessions...\n\n";

// Session 1: Alice (Customer Support)
agent('support-alice')
    ->provider('mock')
    ->system('You are a customer support agent. Be helpful and professional.')
    ->memory('sqlite', ['path' => 'storage/multi-session.db'])
    ->sessionId('support-alice-001');

// Session 2: Bob (Technical Questions)
agent('support-bob')
    ->provider('mock')
    ->system('You are a technical support specialist. Provide detailed answers.')
    ->memory('sqlite', ['path' => 'storage/multi-session.db'])
    ->sessionId('support-bob-002');

// Session 3: Carol (Sales Inquiry)
agent('support-carol')
    ->provider('mock')
    ->system('You are a sales assistant. Be enthusiastic and informative.')
    ->memory('sqlite', ['path' => 'storage/multi-session.db'])
    ->sessionId('support-carol-003');

/**
 * Example 2: Parallel Conversations
 *
 * Simulate conversations happening simultaneously across different sessions.
 */
echo "\n[Example 2: Parallel Conversations]\n";

$alice = agent('support-alice');
$bob = agent('support-bob');
$carol = agent('support-carol');

// Round 1: Initial messages
echo "[Round 1: Initial Contact]\n";

echo "[Alice's Session - support-alice-001]\n";
echo "Alice: I need help with my order\n";
$alice1 = $alice->prompt('I need help with my order');
echo "Agent: {$alice1->content}\n";
echo 'Messages: '.count($alice->messages)."\n\n";

echo "[Bob's Session - support-bob-002]\n";
echo "Bob: How do I configure the API settings?\n";
$bob1 = $bob->prompt('How do I configure the API settings?');
echo "Agent: {$bob1->content}\n";
echo 'Messages: '.count($bob->messages)."\n\n";

echo "[Carol's Session - support-carol-003]\n";
echo "Carol: What pricing plans do you offer?\n";
$carol1 = $carol->prompt('What pricing plans do you offer?');
echo "Agent: {$carol1->content}\n";
echo 'Messages: '.count($carol->messages)."\n\n";

// Round 2: Follow-up messages
echo "[Round 2: Follow-up Questions]\n";

echo "[Alice's Session]\n";
echo "Alice: My order number is #12345\n";
$alice2 = $alice->prompt('My order number is #12345');
echo "Agent: {$alice2->content}\n";
echo 'Messages: '.count($alice->messages)."\n\n";

echo "[Bob's Session]\n";
echo "Bob: I'm getting a 401 authentication error\n";
$bob2 = $bob->prompt("I'm getting a 401 authentication error");
echo "Agent: {$bob2->content}\n";
echo 'Messages: '.count($bob->messages)."\n\n";

echo "[Carol's Session]\n";
echo "Carol: Do you offer enterprise solutions?\n";
$carol2 = $carol->prompt('Do you offer enterprise solutions?');
echo "Agent: {$carol2->content}\n";
echo 'Messages: '.count($carol->messages)."\n\n";

// Round 3: More context
echo "[Round 3: Adding More Context]\n";

echo "[Alice's Session]\n";
echo "Alice: I ordered it 2 weeks ago\n";
$alice3 = $alice->prompt('I ordered it 2 weeks ago');
echo "Agent: {$alice3->content}\n";
echo 'Messages: '.count($alice->messages)."\n\n";

echo "[Bob's Session]\n";
echo "Bob: I'm using PHP with the cURL library\n";
$bob3 = $bob->prompt("I'm using PHP with the cURL library");
echo "Agent: {$bob3->content}\n";
echo 'Messages: '.count($bob->messages)."\n\n";

echo "[Carol's Session]\n";
echo "Carol: We have about 500 users\n";
$carol3 = $carol->prompt('We have about 500 users');
echo "Agent: {$carol3->content}\n";
echo 'Messages: '.count($carol->messages)."\n\n";

/**
 * Example 3: Simulating System Restart
 *
 * Demonstrate that all sessions persist independently and can be
 * restored after a system restart.
 */
echo "\n[Example 3: Simulating System Restart]\n";

echo "Simulating server restart...\n";
echo "Creating new agent instances with same session IDs...\n\n";

// Create fresh instances - messages will auto-load
agent('reloaded-alice')
    ->provider('mock')
    ->system('You are a customer support agent. Be helpful and professional.')
    ->memory('sqlite', ['path' => 'storage/multi-session.db'])
    ->sessionId('support-alice-001');

agent('reloaded-bob')
    ->provider('mock')
    ->system('You are a technical support specialist. Provide detailed answers.')
    ->memory('sqlite', ['path' => 'storage/multi-session.db'])
    ->sessionId('support-bob-002');

agent('reloaded-carol')
    ->provider('mock')
    ->system('You are a sales assistant. Be enthusiastic and informative.')
    ->memory('sqlite', ['path' => 'storage/multi-session.db'])
    ->sessionId('support-carol-003');

$aliceReloaded = agent('reloaded-alice');
$bobReloaded = agent('reloaded-bob');
$carolReloaded = agent('reloaded-carol');

echo "Sessions Restored:\n";
echo str_repeat('-', 70)."\n";
echo sprintf("Alice (support-alice-001): %d messages loaded\n", count($aliceReloaded->messages));
echo sprintf("Bob (support-bob-002): %d messages loaded\n", count($bobReloaded->messages));
echo sprintf("Carol (support-carol-003): %d messages loaded\n", count($carolReloaded->messages));
echo str_repeat('-', 70)."\n\n";

/**
 * Example 4: Continuing Conversations
 *
 * Continue each conversation from where it left off.
 */
echo "\n[Example 4: Continuing Conversations After Restart]\n";

echo "[Alice's Session - Continued]\n";
echo "Alice: Can you help me track it?\n";
$alice4 = $aliceReloaded->prompt('Can you help me track it?');
echo "Agent: {$alice4->content}\n";
echo "(Context preserved: Agent remembers order #12345)\n\n";

echo "[Bob's Session - Continued]\n";
echo "Bob: Should I check my API key?\n";
$bob4 = $bobReloaded->prompt('Should I check my API key?');
echo "Agent: {$bob4->content}\n";
echo "(Context preserved: Agent remembers 401 error and cURL)\n\n";

echo "[Carol's Session - Continued]\n";
echo "Carol: What would be the cost?\n";
$carol4 = $carolReloaded->prompt('What would be the cost?');
echo "Agent: {$carol4->content}\n";
echo "(Context preserved: Agent remembers 500 users)\n\n";

/**
 * Example 5: Session Isolation
 *
 * Verify that sessions don't leak information to each other.
 */
echo "\n[Example 5: Session Isolation]\n";

echo "Testing session isolation...\n\n";

echo "[Bob's Session]\n";
echo "Bob: What's Alice's order number?\n";
$isolation1 = $bobReloaded->prompt("What's Alice's order number?");
echo "Agent: {$isolation1->content}\n";
echo "(Bob's agent has no knowledge of Alice's conversation)\n\n";

echo "[Carol's Session]\n";
echo "Carol: What error is Bob getting?\n";
$isolation2 = $carolReloaded->prompt('What error is Bob getting?');
echo "Agent: {$isolation2->content}\n";
echo "(Carol's agent has no knowledge of Bob's conversation)\n\n";

/**
 * Example 6: Session Overview
 *
 * Display a summary of all active sessions.
 */
echo "\n[Example 6: Session Overview]\n";

$sessions = [
    ['id' => 'support-alice-001', 'user' => 'Alice', 'agent' => $aliceReloaded],
    ['id' => 'support-bob-002', 'user' => 'Bob', 'agent' => $bobReloaded],
    ['id' => 'support-carol-003', 'user' => 'Carol', 'agent' => $carolReloaded],
];

echo "Active Sessions Summary:\n";

foreach ($sessions as $session) {
    echo "\nUser: {$session['user']}\n";
    echo "Session ID: {$session['id']}\n";
    echo 'Total Messages: '.count($session['agent']->messages)."\n";
    echo "Last Messages:\n";

    // Show last 3 messages
    $lastMessages = array_slice($session['agent']->messages, -3);
    foreach ($lastMessages as $msg) {
        $role = $msg['role'];
        $content = is_string($msg['content']) ? $msg['content'] : json_encode($msg['content']);
        $truncated = mb_strlen($content) > 40 ? mb_substr($content, 0, 40).'...' : $content;
        echo "  - {$role}: {$truncated}\n";
    }
}

echo "\n";

/**
 * Example 7: Creating a New Session
 *
 * Show how easy it is to add a new independent session.
 */
echo "\n[Example 7: Adding New Session]\n";

agent('support-dave')
    ->provider('mock')
    ->system('You are a billing specialist.')
    ->memory('sqlite', ['path' => 'storage/multi-session.db'])
    ->sessionId('support-dave-004');

$dave = agent('support-dave');

echo "[Dave's Session - support-dave-004]\n";
echo "Dave: I have a billing question\n";
$dave1 = $dave->prompt('I have a billing question');
echo "Agent: {$dave1->content}\n";
echo 'Messages: '.count($dave->messages)." (new session)\n\n";

echo "New session created independently of existing ones!\n\n";

/**
 * Example 8: Final Statistics
 */
echo "\n[Example 8: Final Statistics]\n";

echo "Total Active Sessions: 4\n";
echo '- Alice: '.count($aliceReloaded->messages)." messages\n";
echo '- Bob: '.count($bobReloaded->messages)." messages\n";
echo '- Carol: '.count($carolReloaded->messages)." messages\n";
echo '- Dave: '.count($dave->messages)." messages\n\n";

$totalMessages = count($aliceReloaded->messages)
    + count($bobReloaded->messages)
    + count($carolReloaded->messages)
    + count($dave->messages);

echo "Total Messages Across All Sessions: {$totalMessages}\n\n";

echo "\nKey Takeaways:\n";
echo "- Each session maintains independent conversation history\n";
echo "- Sessions persist across application restarts\n";
echo "- No information leakage between sessions\n";
echo "- Easy to manage multiple concurrent users\n";
echo "\nNote: All sessions stored in storage/multi-session.db\n";

echo "\nDone.\n";
