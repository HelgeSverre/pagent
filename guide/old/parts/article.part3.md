# Chapter 3: Messages and Conversations

## What You'll Learn

After completing this chapter, you'll be able to:

- Build multi-turn conversations with persistent context
- Manage conversation history effectively
- Implement different message roles (system, user, assistant)
- Optimize context windows for cost and performance
- Create conversational agents for real-world applications

**Prerequisites:** You should have completed Chapters 1-2 and understand basic agent creation and configuration.

**Time Estimate:** 30-40 minutes

**Final Result:** A fully functional conversational agent with history management and context optimization.

## Understanding Message Structure

In Pagent, conversations are built from messages, each with a specific role and content. Let's explore how messages work and how they form the foundation of conversational AI.

### Message Roles

Every message in a conversation has one of three roles:

```php
use Pagent\Agent;

$agent = Agent::create()
    ->usingOpenAI('gpt-4o-mini')
    ->withSystemPrompt('You are a helpful coding assistant.'); // System message

$response = $agent->ask('How do I read a file in PHP?'); // User message
// The response is an assistant message
```

Let's examine each role:

1. **System Messages**: Set the agent's behavior and context
2. **User Messages**: Questions or prompts from the user
3. **Assistant Messages**: Responses from the AI model

### Building Conversations Manually

While `ask()` handles simple interactions, you can build conversations manually for more control:

```php
$agent = Agent::create()
    ->usingOpenAI('gpt-4o-mini')
    ->withMessages([
        ['role' => 'system', 'content' => 'You are a code reviewer.'],
        ['role' => 'user', 'content' => 'Review this function: function add($a, $b) { return $a + $b; }'],
        ['role' => 'assistant', 'content' => 'The function looks good but could benefit from type hints.'],
        ['role' => 'user', 'content' => 'Can you show me how to add type hints?']
    ]);

$response = $agent->generate();
echo $response; // Shows how to add type hints to the function
```

### Message Content Types

Messages can contain different types of content:

```php
// Text content (most common)
$agent->ask('Explain recursion');

// Structured content with system context
$agent = Agent::create()
    ->usingAnthropic('claude-3-5-haiku')
    ->withSystemPrompt('You are a JSON API that responds only with valid JSON.')
    ->ask('List three programming languages');

// Multi-part messages (advanced)
$agent->withMessages([
    [
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'What is in this image?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,...']]
        ]
    ]
]);
```

## Managing Conversation History

One of Pagent's powerful features is automatic conversation history management. Each agent maintains its conversation state, allowing for natural multi-turn interactions.

### Automatic History Tracking

```php
$agent = Agent::create()
    ->usingOpenAI('gpt-4o-mini')
    ->withSystemPrompt('You are a helpful math tutor.');

// First interaction
$response1 = $agent->ask('What is the Pythagorean theorem?');
echo $response1; // Explains a² + b² = c²

// Second interaction - the agent remembers the context
$response2 = $agent->ask('Can you give me an example?');
echo $response2; // Provides an example using the previously discussed theorem

// Third interaction - building on previous context
$response3 = $agent->ask('What if a=3 and b=4?');
echo $response3; // Calculates c=5 based on the ongoing conversation
```

### Accessing Conversation History

You can inspect and manipulate the conversation history:

```php
$agent = Agent::create()
    ->usingAnthropic('claude-3-5-sonnet')
    ->withSystemPrompt('You are a writing assistant.');

$agent->ask('Help me write a story about a robot.');
$agent->ask('Make it more dramatic.');

// Access the full conversation history
$history = $agent->messages();

foreach ($history as $message) {
    echo $message['role'] . ': ' . substr($message['content'], 0, 50) . "...\n";
}

// Count messages in the conversation
$messageCount = count($agent->messages());
echo "Total messages: $messageCount\n";
```

### Clearing and Resetting Conversations

Sometimes you need to start fresh:

```php
$agent = Agent::create()
    ->usingOpenAI('gpt-4o')
    ->withSystemPrompt('You are a customer service representative.');

// Handle first customer
$agent->ask('I need help with my order #12345');
$agent->ask('When will it arrive?');

// Clear history for new customer (keeps system prompt)
$agent->clearMessages();

// Handle second customer with fresh context
$agent->ask('I want to return item #67890');
// Agent won't have any memory of order #12345
```

### Preserving Context Across Sessions

For long-running applications, you might need to save and restore conversations:

```php
// Save conversation state
$agent = Agent::create()
    ->usingAnthropic('claude-3-5-haiku')
    ->withSystemPrompt('You are a coding mentor.');

$agent->ask('Teach me about design patterns');
$agent->ask('Explain the singleton pattern');

// Export the conversation
$conversationState = [
    'messages' => $agent->messages(),
    'system_prompt' => $agent->systemPrompt(),
];

// Save to file or database
file_put_contents('conversation.json', json_encode($conversationState));

// Later, restore the conversation
$saved = json_decode(file_get_contents('conversation.json'), true);

$restoredAgent = Agent::create()
    ->usingAnthropic('claude-3-5-haiku')
    ->withMessages($saved['messages']);

// Continue where you left off
$response = $restoredAgent->ask('What about the factory pattern?');
```

## Implementing Different Message Roles

Understanding how to use different message roles effectively is crucial for building sophisticated conversational agents.

### System Prompts: Setting the Stage

System prompts define your agent's personality, knowledge, and constraints:

```php
// Customer Service Bot
$customerBot = Agent::create()
    ->usingOpenAI('gpt-4o-mini')
    ->withSystemPrompt(
        "You are a friendly customer service representative for TechStore. " .
        "You have access to order information and can help with returns, " .
        "tracking, and product questions. Always be polite and helpful. " .
        "If you don't know something, offer to escalate to a human agent."
    );

// Technical Documentation Assistant
$docsBot = Agent::create()
    ->usingAnthropic('claude-3-5-sonnet')
    ->withSystemPrompt(
        "You are a technical documentation expert. You help developers " .
        "write clear, concise, and accurate documentation. Focus on: " .
        "1. Clear structure with headers and sections " .
        "2. Code examples that are complete and runnable " .
        "3. Explaining both the 'what' and the 'why' " .
        "Always suggest improvements for clarity and completeness."
    );

// Code Review Assistant
$reviewBot = Agent::create()
    ->usingOpenAI('gpt-4o')
    ->withSystemPrompt(
        "You are a senior software engineer conducting code reviews. " .
        "Analyze code for: security issues, performance problems, " .
        "code style violations, and potential bugs. Provide constructive " .
        "feedback with specific suggestions for improvement."
    );
```

### User Messages: Crafting Effective Prompts

User messages drive the conversation forward:

```php
$agent = Agent::create()
    ->usingAnthropic('claude-3-5-haiku')
    ->withSystemPrompt('You are a SQL query assistant.');

// Simple question
$response = $agent->ask('How do I join two tables?');

// Detailed context
$response = $agent->ask(
    "I have two tables: users (id, name, email) and orders (id, user_id, total). " .
    "I need to find all users who have placed orders over $100."
);

// Progressive refinement
$agent->ask('Write a SELECT query for users table');
$agent->ask('Now add a JOIN with orders');
$agent->ask('Add a WHERE clause for orders over 100');
$agent->ask('Include the total order count per user');
```

### Assistant Messages: Seeding Conversations

Pre-populating assistant messages can guide the conversation style:

```php
$agent = Agent::create()
    ->usingOpenAI('gpt-4o-mini')
    ->withMessages([
        ['role' => 'system', 'content' => 'You are a Socratic tutor who teaches through questions.'],
        ['role' => 'user', 'content' => 'Teach me about recursion'],
        ['role' => 'assistant', 'content' => 'Interesting topic! Let me ask you: What happens when you stand between two mirrors facing each other?'],
        ['role' => 'user', 'content' => 'I see infinite reflections'],
        ['role' => 'assistant', 'content' => 'Exactly! Now, can you think of how this mirror effect might relate to a function in programming?'],
    ]);

// Continue the Socratic dialogue
$response = $agent->ask('A function could call itself?');
// The agent will continue in the established Socratic style
```

## Context Window Management

Every LLM has a context window limit - the maximum number of tokens it can process. Managing this effectively is crucial for long conversations.

### Understanding Context Limits

Different models have different limits:

```php
// Model context windows (approximate):
// GPT-4o-mini: 128K tokens
// GPT-4o: 128K tokens
// Claude 3.5 Haiku: 200K tokens
// Claude 3.5 Sonnet: 200K tokens

// Check your conversation size
$agent = Agent::create()->usingOpenAI('gpt-4o-mini');

// Rough estimation (1 token ≈ 4 characters)
$estimateTokens = function($messages) {
    $totalChars = 0;
    foreach ($messages as $message) {
        $totalChars += strlen($message['content']);
    }
    return (int)($totalChars / 4);
};

$agent->ask('Long conversation here...');
$tokenEstimate = $estimateTokens($agent->messages());
echo "Estimated tokens used: $tokenEstimate\n";
```

### Implementing a Sliding Window

For long conversations, implement a sliding window to keep recent context:

```php
class ConversationManager
{
    private array $messages = [];
    private int $maxMessages;

    public function __construct(int $maxMessages = 20)
    {
        $this->maxMessages = $maxMessages;
    }

    public function addMessage(string $role, string $content): void
    {
        $this->messages[] = ['role' => $role, 'content' => $content];

        // Keep only the most recent messages plus system prompt
        if (count($this->messages) > $this->maxMessages) {
            $systemPrompt = $this->messages[0]; // Preserve system prompt
            $this->messages = array_merge(
                [$systemPrompt],
                array_slice($this->messages, -($this->maxMessages - 1))
            );
        }
    }

    public function getMessages(): array
    {
        return $this->messages;
    }
}

// Use with Pagent
$manager = new ConversationManager(10); // Keep last 10 messages
$manager->addMessage('system', 'You are a helpful assistant.');

$agent = Agent::create()->usingOpenAI('gpt-4o-mini');

// Long conversation
for ($i = 0; $i < 50; $i++) {
    $manager->addMessage('user', "Question $i");

    $agent->withMessages($manager->getMessages());
    $response = $agent->generate();

    $manager->addMessage('assistant', $response);
}
```

### Smart Context Summarization

For very long conversations, periodically summarize the context:

```php
function summarizeConversation(Agent $agent): string
{
    $summaryAgent = Agent::create()
        ->usingOpenAI('gpt-4o-mini')
        ->withSystemPrompt('Summarize the key points of this conversation in 2-3 sentences.')
        ->withMessages($agent->messages());

    return $summaryAgent->generate();
}

// Main conversation agent
$agent = Agent::create()
    ->usingAnthropic('claude-3-5-sonnet')
    ->withSystemPrompt('You are a project planning assistant.');

// Have a long conversation
for ($i = 0; $i < 10; $i++) {
    $agent->ask("Let's discuss task $i of the project...");
}

// When context gets large, summarize and reset
if (count($agent->messages()) > 20) {
    $summary = summarizeConversation($agent);

    // Reset with summary as context
    $agent->clearMessages();
    $agent->withSystemPrompt(
        "You are a project planning assistant. Previous conversation summary: $summary"
    );
}
```

## Practical Example: Customer Service Chatbot

Let's build a complete customer service chatbot that demonstrates all these concepts:

```php
class CustomerServiceBot
{
    private Agent $agent;
    private array $orderDatabase = [
        '12345' => ['status' => 'shipped', 'eta' => '2024-01-15'],
        '67890' => ['status' => 'processing', 'eta' => '2024-01-18'],
    ];

    public function __construct()
    {
        $this->agent = Agent::create()
            ->usingOpenAI('gpt-4o-mini')
            ->withSystemPrompt(
                "You are a helpful customer service representative for FastShip. " .
                "Be friendly, professional, and solution-oriented. " .
                "You can check order status and help with common issues. " .
                "If you need order information, ask for the order number."
            );
    }

    public function chat(string $message): string
    {
        // Check if message contains order number
        if (preg_match('/\b(\d{5})\b/', $message, $matches)) {
            $orderNum = $matches[1];
            if (isset($this->orderDatabase[$orderNum])) {
                $order = $this->orderDatabase[$orderNum];
                $context = " [Order $orderNum is {$order['status']}, ETA: {$order['eta']}]";
                $message .= $context;
            }
        }

        return $this->agent->ask($message);
    }

    public function resetForNewCustomer(): void
    {
        $this->agent->clearMessages();
    }
}

// Usage
$bot = new CustomerServiceBot();

echo $bot->chat("Hi, I need help with my order") . "\n";
echo $bot->chat("The order number is 12345") . "\n";
echo $bot->chat("When will it arrive?") . "\n";

$bot->resetForNewCustomer();
echo $bot->chat("I want to check order 67890") . "\n";
```

## Summary

You've learned how to build sophisticated conversational agents with Pagent:

- **Message Structure**: Understanding roles (system, user, assistant) and how they shape conversations
- **History Management**: Tracking, accessing, and clearing conversation state
- **Context Optimization**: Managing token limits with sliding windows and summarization
- **Practical Applications**: Building real-world conversational agents

The key to effective conversational AI is balancing context (keeping enough history for coherent responses) with efficiency (managing token usage and costs).

## Next Steps

In Chapter 4, you'll learn about:

- Streaming responses for real-time interactions
- Handling long-running operations
- Building responsive user interfaces
- Managing backpressure and flow control

## Try These Challenges

1. **Context-Aware Bot**: Build a bot that remembers user preferences across conversation turns
2. **Multi-Agent Conversation**: Create two agents that debate a topic with each other
3. **Smart Summarizer**: Implement automatic summarization when context exceeds 50 messages
4. **Conversation Branching**: Save conversation state and explore different conversation paths

## Resources

- [Pagent Messages Documentation](https://github.com/pagent/pagent)
- [Token Counting Guide](https://platform.openai.com/tokenizer)
- [Conversation Design Best Practices](https://docs.anthropic.com/claude/docs/conversation-design)
