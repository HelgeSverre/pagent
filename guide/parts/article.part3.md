# Chapter 3: Messages and Conversations

In Chapter 1, we learned how to create agents and send single prompts. In Chapter 2, we explored the different providers that power those prompts. But real-world AI applications rarely work with isolated messages. They require conversations - multi-turn exchanges where the agent remembers what was said before and builds on that context.

This is where Pagent's conversation management shines. The framework automatically tracks message history, handles different message roles, and provides tools for managing conversation context. In this chapter, we'll explore how to build conversational agents that maintain context, export and import conversations, and manage long-running dialogues effectively.

## Understanding Message Structure

At the heart of every conversation is the message array. In Pagent, each agent maintains a public `$messages` property that stores the complete conversation history:

```php
$agent = agent('chat-bot')
    ->provider(anthropic())
    ->build();

// Initially empty
var_dump($agent->messages); // []

$agent->prompt('Hello!');

// Now contains 2 messages: user + assistant
print_r($agent->messages);
/*
[
    ['role' => 'user', 'content' => 'Hello!'],
    ['role' => 'assistant', 'content' => 'Hello! How can I help you today?']
]
*/
```

Every time you call `prompt()`, Pagent automatically adds two messages to the history: your user message and the assistant's response. This happens transparently - you don't need to manage the array yourself.

The message structure is deliberately simple. Each message is an array with two required keys:

- `role`: Either "user" or "assistant" (system messages are handled differently, as we'll see)
- `content`: The message text

This simplicity makes the message history easy to inspect, debug, and manipulate when needed.

## Building Multi-Turn Conversations

The real power of automatic history tracking becomes clear when you have multiple exchanges:

```php
$agent = agent('code-reviewer')
    ->provider(anthropic())
    ->system('You are a helpful code reviewer. Provide constructive feedback.')
    ->build();

// First exchange
$agent->prompt('Can you review this function?');
// Agent: "Sure, I'd be happy to help! Please share the function..."

// Second exchange - agent remembers the context
$agent->prompt('function calculateTotal($items) { return array_sum($items); }');
// Agent: "Looking at the function you shared earlier..."

// Third exchange - builds on previous feedback
$agent->prompt('How can I make it handle invalid input?');
// Agent: "Based on the calculateTotal function we discussed..."

// Check how many exchanges we've had
echo count($agent->messages); // 6 (3 user + 3 assistant)
```

Each prompt builds on the entire conversation history. The agent doesn't just see your latest message - it sees everything that came before. This enables natural dialogue where context flows naturally from one exchange to the next.

## System Messages and Roles

While user and assistant messages make up the conversation flow, system messages play a different role. They set the stage for the entire conversation, defining the agent's personality, constraints, and instructions.

In Pagent, system messages are configured through the `system()` method and live in the agent's configuration, not in the message history:

```php
$agent = agent('technical-writer')
    ->provider(anthropic())
    ->system('You are a technical writer. Explain concepts clearly with examples.')
    ->build();

// System message is NOT in the messages array
var_dump($agent->messages); // []

$agent->prompt('Explain dependency injection');
// The system message is sent with this prompt, but stored separately

// Messages array only contains user/assistant exchanges
count($agent->messages); // 2
```

The system message acts as a persistent instruction that applies to every prompt you send. It's like setting the rules of the game before play begins. The agent will reference these instructions throughout the conversation without them cluttering your message history.

## Managing Long Conversations

Conversations can grow long quickly. A customer service chat might span dozens of exchanges. A coding assistant session could include hundreds of messages. But LLMs have token limits - you can't send infinite history with every prompt.

This is where context window management becomes critical. Pagent provides the `contextWindow()` method to automatically prune conversation history:

```php
$agent = agent('support-bot')
    ->provider(anthropic())
    ->contextWindow(4000, 'oldest') // Keep within 4000 tokens
    ->build();

// Have a long conversation
for ($i = 0; $i < 50; $i++) {
    $agent->prompt("Question number {$i}");
}

// The messages array contains all 100 messages (50 user + 50 assistant)
echo count($agent->messages); // 100

// But when sending prompts, Pagent automatically prunes to fit 4000 tokens
// Older messages are removed first (oldest strategy)
```

The pruning happens transparently during the `prompt()` call. Your in-memory message history remains complete, but when communicating with the LLM provider, Pagent sends only what fits within your token budget. The 'oldest' strategy removes the earliest messages first, keeping the most recent context.

This automatic pruning means you can build long-running conversational agents without worrying about hitting context limits. The agent maintains a complete history for reference while efficiently managing what gets sent to the provider.

## Exporting and Importing Conversations

Real applications often need to persist conversations between sessions. A user might close your app and return later, expecting to pick up where they left off. Pagent makes this straightforward with `exportConversation()` and `importConversation()`:

```php
$agent = agent('persistent-bot')
    ->provider(anthropic())
    ->build();

$agent->prompt('Remember this: my favorite color is blue');
$agent->prompt('What should I wear to a summer wedding?');

// Export the conversation as JSON
$json = $agent->exportConversation();

// Save to database, file, session, etc.
file_put_contents('conversation.json', $json);

// Later, in a new session...
$newAgent = agent('persistent-bot')
    ->provider(anthropic())
    ->build();

// Import the conversation
$conversationData = file_get_contents('conversation.json');
$newAgent->importConversation($conversationData);

// Agent remembers previous context
$newAgent->prompt('What color should the outfit be?');
// Agent: "Based on our earlier conversation, since blue is your favorite color..."
```

The exported JSON contains the complete message history plus metadata like the agent name and export timestamp:

```json
{
  "agent": "persistent-bot",
  "messages": [
    { "role": "user", "content": "Remember this: my favorite color is blue" },
    { "role": "assistant", "content": "I'll remember that!" },
    { "role": "user", "content": "What should I wear to a summer wedding?" },
    { "role": "assistant", "content": "For a summer wedding..." }
  ],
  "exported_at": "2025-01-15T10:30:00+00:00"
}
```

This format is human-readable and easy to work with. You can inspect it, modify it if needed, or store it in any backend that handles JSON.

## Conversation Statistics

Understanding conversation patterns can be valuable for monitoring, debugging, and analytics. Pagent provides `getStats()` to give you insights into the conversation:

```php
$agent = agent('analytics-bot')
    ->provider(anthropic())
    ->tool('search', 'Search the web', fn($query) => "Results for: {$query}")
    ->build();

$agent->prompt('What is the weather?');
$agent->prompt('Tell me a joke');

$stats = $agent->getStats();

print_r($stats);
/*
[
    'agent' => 'analytics-bot',
    'total_messages' => 4,
    'user_messages' => 2,
    'assistant_messages' => 2,
    'tools_registered' => 1,
    'guards_active' => 0,
    'middleware_active' => 0
]
*/
```

This provides a quick snapshot of the agent's state. You can track conversation length, verify tool registration, and monitor the overall configuration. It's particularly useful when debugging why an agent might not be behaving as expected - you can quickly verify that tools are registered, guards are active, and the message history looks correct.

## Practical Example: Code Review Assistant

Let's bring these concepts together in a practical example - a code review assistant that maintains context across multiple review rounds:

```php
$reviewer = agent('code-reviewer')
    ->provider(anthropic())
    ->system(
        'You are a senior software engineer conducting code reviews. ' .
        'Provide specific, actionable feedback. Track issues across the review.'
    )
    ->contextWindow(8000, 'oldest')
    ->build();

// First submission
$response = $reviewer->prompt(
    "Please review this user registration function:\n\n" .
    "function registerUser(\$email, \$password) {\n" .
    "    \$db->query(\"INSERT INTO users VALUES ('\$email', '\$password')\");\n" .
    "    return true;\n" .
    "}"
);

echo $response->content;
// Agent identifies SQL injection, plaintext passwords, no validation, etc.

// Second round - user makes partial fixes
$reviewer->prompt(
    "I've updated the function:\n\n" .
    "function registerUser(\$email, \$password) {\n" .
    "    \$hashed = password_hash(\$password, PASSWORD_DEFAULT);\n" .
    "    \$stmt = \$db->prepare(\"INSERT INTO users VALUES (?, ?)\");\n" .
    "    \$stmt->execute([\$email, \$hashed]);\n" .
    "    return true;\n" .
    "}"
);
// Agent recognizes improvements but notes missing email validation, error handling

// Third round - user asks for clarification
$reviewer->prompt('What specific email validation should I add?');
// Agent references the earlier context and provides specific examples

// Export the review session
$reviewSession = $reviewer->exportConversation();
file_put_contents("reviews/user-registration-{$date}.json", $reviewSession);

// Get statistics
$stats = $reviewer->getStats();
echo "Review had {$stats['total_messages']} exchanges\n";
```

This example demonstrates several key patterns:

1. System message establishes the agent's role and approach
2. Context window ensures the conversation doesn't exceed token limits
3. Multi-turn dialogue allows iterative improvement
4. Export functionality preserves the review session
5. Statistics provide simple monitoring

## Practical Example: Customer Support Bot

Here's another real-world scenario - a customer support bot that handles multi-turn support tickets:

```php
$support = agent('support-bot')
    ->provider(anthropic())
    ->system(
        'You are a helpful customer support agent. ' .
        'Ask clarifying questions. Track customer information throughout the conversation.'
    )
    ->contextWindow(6000, 'oldest')
    ->build();

// Customer initiates contact
$support->prompt("I can't log into my account");
// Agent: "I can help with that! Can you tell me what error message you're seeing?"

$support->prompt("It says 'Invalid password'");
// Agent: "Let's reset your password. Can you confirm the email address on the account?"

$support->prompt("sure it's john@example.com");
// Agent remembers the login issue context and email

// Later - save conversation to support ticket system
$ticketData = $support->exportConversation();
$ticketId = saveToSupportSystem($ticketData);

// Next day - agent picks up conversation
$followUp = agent('support-bot')
    ->provider(anthropic())
    ->system('You are a helpful customer support agent...')
    ->build();

$followUp->importConversation(loadFromSupportSystem($ticketId));

// Continue where we left off
$followUp->prompt("I reset my password but still can't access my data");
// Agent has full context of the previous day's conversation
```

## Directly Manipulating Message History

While Pagent handles message tracking automatically, sometimes you need direct control. The `$messages` property is public for exactly this reason:

```php
$agent = agent('custom-bot')
    ->provider(anthropic())
    ->build();

// Start a conversation
$agent->prompt('Hello');

// Directly inspect messages
foreach ($agent->messages as $msg) {
    echo "{$msg['role']}: {$msg['content']}\n";
}

// Manually add a message (advanced use case)
$agent->messages[] = [
    'role' => 'user',
    'content' => 'This is a manually injected message'
];

// Next prompt sees the injected message
$agent->prompt('What did I just say?');
// Agent responds based on all messages including the injected one

// Clear history completely
$agent->messages = [];

// Fresh start
$agent->prompt('Hello again');
// No memory of previous conversation
```

Direct manipulation is powerful but use it carefully. The automatic tracking handles most scenarios correctly. Manual manipulation is useful for:

- Testing specific conversation states
- Implementing custom pruning strategies
- Migrating conversations between agents
- Debugging conversation flow issues

## Memory vs Messages

It's worth clarifying the distinction between messages and memory. The `$messages` array is in-memory conversation state. It persists for the lifetime of the agent object, but disappears when your PHP process ends.

Memory (which we'll explore in Chapter 6) provides persistent storage across requests. When you configure memory with `memory()` and `sessionId()`, Pagent automatically loads conversation history at the start of each request and saves it at the end. This is different from manual export/import - it happens transparently.

For now, understand that messages are the building blocks. Memory is one way (the automatic way) to persist those messages across requests. Export/import is another way (the manual way).

## Best Practices for Conversation Management

Based on what we've covered, here are some patterns to follow:

**Let Pagent manage history automatically.** Unless you have a specific reason to manually manipulate `$messages`, let the framework handle it. Every `prompt()` call correctly updates the history.

**Use system messages for persistent instructions.** Don't put role definitions or constraints in user messages. Use the `system()` method so they apply consistently throughout the conversation.

**Configure context windows for long conversations.** If your agent might have dozens of exchanges, set a context window. This prevents token limit errors and keeps API costs predictable.

**Export important conversations.** For customer support, code reviews, or any scenario where you need to reference or audit conversations later, use `exportConversation()` to persist the session.

**Monitor with stats.** Use `getStats()` during development to verify your conversation is behaving as expected. It's a quick sanity check for message counts and configuration.

**Consider your pruning strategy.** The default 'oldest' strategy works well for most conversations where recent context matters most. But think about your use case - some applications might need custom strategies.

## What's Next

We've now covered the fundamentals: creating agents, configuring providers, and managing conversations. These are the building blocks that make every Pagent application work.

In the next chapter, we'll explore prompting strategies - how to craft system prompts that guide agent behavior, implement few-shot learning, and design effective prompts that get better results from your LLMs.

You'll learn that while conversation management keeps the dialogue flowing, prompt engineering determines the quality of what flows through it. Let's dive into that next.
