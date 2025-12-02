# Chapter 1: Introduction to Pagent

## What You'll Learn

By the end of this chapter, you'll be able to:

- Install and configure Pagent in your PHP 8.3+ project
- Create your first AI agent with the fluent API
- Switch between different LLM providers (OpenAI, Anthropic, Ollama)
- Understand Pagent's core philosophy and design principles
- Build a working conversational agent that maintains context

**Prerequisites:** PHP 8.3+, Composer 2.x, and an API key from OpenAI or Anthropic

**Time to Complete:** 20 minutes

**Final Result:** A fully functional AI assistant that responds to prompts and maintains conversation history

---

## The Pagent Philosophy

Before we write any code, let's understand what makes Pagent different. If you've worked with the OpenAI or Anthropic SDKs directly, you've probably written code like this:

```php
// Traditional SDK approach - lots of boilerplate
$client = new OpenAI\Client(['api_key' => $apiKey]);
$messages = [];
$messages[] = ['role' => 'system', 'content' => 'You are helpful'];
$messages[] = ['role' => 'user', 'content' => 'Hello!'];

$response = $client->chat()->completions()->create([
    'model' => 'gpt-4',
    'messages' => $messages,
    'temperature' => 0.7,
]);

$messages[] = ['role' => 'assistant', 'content' => $response->choices[0]->message->content];
```

Pagent takes inspiration from Pest's philosophy: **simplicity through expressive APIs**. Here's the same interaction in Pagent:

```php
// Pagent approach - clean and expressive
agent('assistant')
    ->provider('openai')
    ->system('You are helpful')
    ->temperature(0.7)
    ->prompt('Hello!');
```

The difference? Pagent handles the complexity so you can focus on building. No manual message management, no boilerplate, no provider-specific quirks. Just clean, chainable methods that read like natural language.

### Core Design Principles

1. **Fluent by Default**: Every method returns `$this`, enabling natural method chaining
2. **Provider Agnostic**: Switch between OpenAI, Anthropic, or local models with one line
3. **Stateful Conversations**: Agents remember context automatically
4. **Progressive Disclosure**: Start simple, add complexity only when needed
5. **Framework Agnostic**: Works everywhere—Laravel, Symfony, vanilla PHP

---

## Installation and Setup

Let's get Pagent installed and configured. Open your terminal and navigate to your project directory:

```bash
composer require helgesverre/pagent
```

That's it for installation. Pagent has minimal dependencies and works with PHP 8.3 or higher.

### Environment Configuration

Pagent reads API keys from environment variables. Create a `.env` file in your project root:

```bash
# For OpenAI
OPENAI_API_KEY=sk-proj-...

# For Anthropic
ANTHROPIC_API_KEY=sk-ant-api03-...

# For Ollama (local models, no key needed)
OLLAMA_BASE_URL=http://localhost:11434
```

💡 **Tip:** Never commit API keys to version control. Add `.env` to your `.gitignore` file.

If you're not using a framework that loads `.env` files automatically, install vlucas/phpdotenv:

```bash
composer require vlucas/phpdotenv
```

Then load it in your bootstrap file:

```php
<?php
require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
```

---

## Your First Agent

Time to create your first Pagent agent. We'll start with the absolute minimum, then progressively add features.

### Hello World

Create a file called `hello.php`:

```php
<?php
require 'vendor/autoload.php';

// Create and configure an agent
agent('assistant')
    ->provider('anthropic')
    ->system('You are a helpful assistant');

// Use the agent to get a response
$response = agent('assistant')->prompt('Say hello!');
echo $response->content;
```

Run it:

```bash
php hello.php
# Output: Hello! How can I assist you today?
```

Let's break down what just happened:

1. **`agent('assistant')`** - Creates or retrieves an agent named "assistant"
2. **`->provider('anthropic')`** - Configures it to use Claude (Anthropic's LLM)
3. **`->system('...')`** - Sets the system prompt (the agent's personality/role)
4. **`->prompt('...')`** - Sends a user message and gets a response

ℹ️ **Note:** The first `agent()` call configures the agent. Subsequent calls with the same name retrieve the configured instance.

### Understanding the Response Object

The response from `prompt()` is a `Response` object with several useful properties:

```php
$response = agent('assistant')->prompt('Tell me a fun fact');

// The actual text response
echo $response->content;

// Provider-specific metadata
echo "Tokens used: " . $response->usage['total_tokens'] . "\n";
echo "Model: " . $response->model . "\n";

// The full structured response (varies by provider)
var_dump($response->data);
```

### Adding Conversation Memory

One of Pagent's strengths is automatic conversation management. Each agent remembers its conversation history:

```php
<?php
require 'vendor/autoload.php';

// Configure the agent once
agent('chatbot')
    ->provider('openai')
    ->system('You are a helpful math tutor')
    ->model('gpt-4')
    ->temperature(0.3);  // Lower temperature for more focused responses

// Have a conversation
$agent = agent('chatbot');

$agent->prompt("What's 15% of 80?");
echo "First response: " . $agent->messages[1]['content'] . "\n\n";

$agent->prompt("Double that number");
echo "Second response: " . $agent->messages[3]['content'] . "\n\n";

$agent->prompt("Now add 7");
echo "Third response: " . $agent->messages[5]['content'] . "\n\n";

// Access the full conversation history
echo "\nFull conversation:\n";
foreach ($agent->messages as $message) {
    echo "[{$message['role']}]: {$message['content']}\n";
}
```

Output:

```
First response: 15% of 80 is 12.

Second response: Double of 12 is 24.

Third response: 24 + 7 = 31.

Full conversation:
[system]: You are a helpful math tutor
[user]: What's 15% of 80?
[assistant]: 15% of 80 is 12.
[user]: Double that number
[assistant]: Double of 12 is 24.
[user]: Now add 7
[assistant]: 24 + 7 = 31.
```

The agent maintains context across prompts, understanding that "that number" refers to 12 and continuing the calculation chain.

---

## Provider Abstraction

Pagent's provider abstraction is intentionally "leaky"—you get provider-specific features while maintaining a consistent API. Let's explore switching between providers.

### Using Global Helper Functions

Pagent provides helper functions for each provider:

```php
<?php
require 'vendor/autoload.php';

// Anthropic (Claude)
$claude = anthropic();
$response = $claude->prompt('Explain recursion in one sentence');
echo "Claude: " . $response->content . "\n\n";

// OpenAI (GPT)
$gpt = openai();
$response = $gpt->prompt('Explain recursion in one sentence');
echo "GPT: " . $response->content . "\n\n";

// Ollama (Local LLM)
$local = ollama();
$response = $local->prompt('Explain recursion in one sentence', [
    'model' => 'llama3.2'  // Specify which local model
]);
echo "Llama: " . $response->content . "\n";
```

Each provider has its own personality and strengths. Claude tends to be more analytical, GPT more creative, and local models offer privacy and zero API costs.

### Provider-Specific Configuration

While Pagent provides a unified interface, you can still access provider-specific features:

```php
// Anthropic-specific: Use Claude 3 Opus for complex reasoning
$response = anthropic()->prompt('Analyze this philosophical paradox...', [
    'model' => 'claude-3-opus-20240229',
    'max_tokens' => 4096
]);

// OpenAI-specific: JSON mode for structured output
$response = openai()->prompt('Generate a user profile', [
    'model' => 'gpt-3.5-turbo-1106',
    'response_format' => ['type' => 'json_object']
]);

// Ollama-specific: Custom model parameters
$response = ollama()->prompt('Write code', [
    'model' => 'codellama:13b',
    'options' => [
        'num_predict' => 500,
        'repeat_penalty' => 1.1
    ]
]);
```

✅ **Best Practice:** Start with the generic interface, then add provider-specific features when you need them.

### Dynamic Provider Switching

One powerful pattern is configuring agents with different providers for different tasks:

```php
<?php
require 'vendor/autoload.php';

// Creative writing with GPT-4
agent('writer')
    ->provider('openai')
    ->model('gpt-4')
    ->temperature(0.9)
    ->system('You are a creative writer');

// Code generation with Claude
agent('coder')
    ->provider('anthropic')
    ->model('claude-3-sonnet-20240229')
    ->temperature(0.2)
    ->system('You are an expert PHP developer');

// Local processing for sensitive data
agent('privacy')
    ->provider('ollama')
    ->model('llama3.2')
    ->system('You process personal information locally');

// Use each agent for its strength
$story = agent('writer')->prompt('Write a opening line for a mystery novel');
$code = agent('coder')->prompt('Write a function to validate email addresses');
$analysis = agent('privacy')->prompt('Analyze this user data: [SENSITIVE INFO]');
```

---

## The Fluent API Pattern

Pagent's fluent API makes complex configurations readable. Let's explore how method chaining works and why it matters.

### Building Complex Agents

Here's a real-world agent configuration:

```php
<?php
require 'vendor/autoload.php';

agent('customer-support')
    ->provider('anthropic')
    ->model('claude-3-haiku-20240307')  // Fast, affordable model
    ->system('You are a friendly customer support agent for ACME Corp')
    ->temperature(0.3)                   // Consistent responses
    ->maxTokens(500)                     // Concise answers
    ->contextWindow(8192)                // Remember recent context
    ->fallback(function($error) {
        // Graceful error handling
        return "I'm having trouble right now. Please try again.";
    });

// The agent is now configured and ready to use
$response = agent('customer-support')->prompt('I need help with my order');
```

Each method returns the agent instance, allowing you to chain calls naturally. The configuration reads like a specification: "Create a customer support agent using Anthropic's Haiku model with a temperature of 0.3..."

### Method Categories

Pagent's methods fall into three categories:

**1. Configuration Methods** (called during setup):

```php
agent('name')
    ->provider($provider)      // Set the LLM provider
    ->model($model)            // Choose specific model
    ->system($prompt)          // Define agent personality
    ->temperature($temp)       // Control randomness (0.0-1.0)
    ->maxTokens($limit)        // Response length limit
    ->contextWindow($size);    // Conversation memory size
```

**2. Runtime Methods** (called during use):

```php
$agent = agent('name');
$agent->prompt($message);          // Send message, get response
$agent->streamTo($message, $callback);  // Stream response chunks
$agent->addMessage($role, $content);    // Add to conversation history
$agent->clearMessages();                // Reset conversation
```

**3. Extension Methods** (add capabilities):

```php
agent('name')
    ->tool($name, $description, $function)  // Add callable tools
    ->guard($guardInstance)                 // Add safety checks
    ->middleware($middleware)               // Add request/response processing
    ->memory($adapter)                      // Enable persistence
    ->sessionId($id);                       // Track user sessions
```

### Creating Reusable Configurations

You can create factory functions for common agent patterns:

```php
function createAnalyst(string $name, string $specialty): void {
    agent($name)
        ->provider('anthropic')
        ->model('claude-3-opus-20240229')
        ->system("You are an expert {$specialty} analyst. Provide detailed, data-driven insights.")
        ->temperature(0.2)
        ->maxTokens(2000);
}

function createCreative(string $name, string $role): void {
    agent($name)
        ->provider('openai')
        ->model('gpt-4')
        ->system("You are a creative {$role}. Think outside the box.")
        ->temperature(0.9)
        ->maxTokens(1500);
}

// Create specialized agents
createAnalyst('financial-analyst', 'financial');
createAnalyst('market-analyst', 'market research');
createCreative('copywriter', 'copywriter');
createCreative('storyteller', 'storyteller');

// Use them throughout your application
$report = agent('financial-analyst')->prompt('Analyze Q3 revenue trends');
$campaign = agent('copywriter')->prompt('Write tagline for eco-friendly product');
```

---

## Common Patterns

Let's explore patterns you'll use frequently when building with Pagent.

### Pattern 1: Retry with Fallback

Handle API failures gracefully:

```php
agent('resilient')
    ->provider('openai')
    ->fallback(function($error) {
        // Log the error
        error_log("LLM Error: " . $error->getMessage());

        // Try alternate provider
        return ollama()->prompt($this->getLastUserMessage());
    });

$response = agent('resilient')->prompt('What is the weather like?');
// Automatically falls back to Ollama if OpenAI fails
```

### Pattern 2: Multi-Step Processing

Chain multiple prompts for complex tasks:

```php
function analyzeDocument(string $document): array {
    $agent = agent('analyzer');

    // Step 1: Summarize
    $summary = $agent->prompt("Summarize this document: {$document}")->content;

    // Step 2: Extract key points (context maintains document knowledge)
    $keyPoints = $agent->prompt("List the 3 most important points")->content;

    // Step 3: Generate action items
    $actions = $agent->prompt("What actions should be taken based on this?")->content;

    return [
        'summary' => $summary,
        'key_points' => $keyPoints,
        'action_items' => $actions
    ];
}
```

### Pattern 3: Role-Based Agents

Create agents with specific expertise:

```php
// Define specialist agents
$agents = [
    'translator' => 'You are a professional translator. Maintain tone and nuance.',
    'reviewer' => 'You are a code reviewer. Focus on best practices and security.',
    'teacher' => 'You are a patient teacher. Explain concepts step-by-step.',
];

foreach ($agents as $name => $systemPrompt) {
    agent($name)
        ->provider('anthropic')
        ->system($systemPrompt)
        ->temperature(0.3);
}

// Use the right expert for each task
$translation = agent('translator')->prompt('Translate to French: Hello world');
$review = agent('reviewer')->prompt('Review this code: ' . $codeSnippet);
$explanation = agent('teacher')->prompt('Explain recursion to a beginner');
```

### Pattern 4: Conversation Templates

Standardize interactions with templates:

```php
class SupportTemplate {
    public static function configure(string $agentName): void {
        agent($agentName)
            ->provider('anthropic')
            ->model('claude-3-haiku-20240307')
            ->system(self::getSystemPrompt())
            ->temperature(0.3)
            ->contextWindow(4096);
    }

    private static function getSystemPrompt(): string {
        return <<<PROMPT
        You are a customer support representative. Guidelines:
        - Be empathetic and professional
        - Ask clarifying questions when needed
        - Provide step-by-step solutions
        - Escalate complex issues appropriately
        - Always thank the customer
        PROMPT;
    }

    public static function greet(string $agentName): string {
        return agent($agentName)->prompt(
            "Customer just connected. Greet them warmly."
        )->content;
    }

    public static function troubleshoot(string $agentName, string $issue): string {
        return agent($agentName)->prompt(
            "Customer issue: {$issue}. Provide troubleshooting steps."
        )->content;
    }
}

// Usage
SupportTemplate::configure('support-agent');
echo SupportTemplate::greet('support-agent');
echo SupportTemplate::troubleshoot('support-agent', 'Cannot login to account');
```

---

## Debugging and Troubleshooting

When things don't work as expected, here are common issues and solutions:

### Issue 1: Empty Responses

```php
// Problem: Getting empty or null responses
$response = agent('assistant')->prompt('Hello');
var_dump($response); // NULL

// Solution: Check provider configuration
try {
    agent('assistant')
        ->provider('anthropic')  // Ensure provider is set
        ->system('You are helpful')
        ->prompt('Hello');
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    // Often reveals missing API key or network issues
}
```

### Issue 2: Conversation Context Lost

```php
// Problem: Agent doesn't remember previous messages
agent('bot')->provider('openai');  // This creates new instance!
agent('bot')->prompt('My name is Alice');
agent('bot')->provider('openai');  // This resets everything!
agent('bot')->prompt('What is my name?');  // Bot doesn't know

// Solution: Configure once, use many times
agent('bot')->provider('openai')->system('Remember our conversation');
$bot = agent('bot');  // Get configured instance
$bot->prompt('My name is Alice');
$bot->prompt('What is my name?');  // "Your name is Alice"
```

### Issue 3: Rate Limiting

```php
// Problem: API rate limit errors
// Solution: Add retry logic with exponential backoff

function promptWithRetry(string $agentName, string $message, int $maxRetries = 3) {
    $delay = 1;

    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            return agent($agentName)->prompt($message);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'rate_limit')) {
                sleep($delay);
                $delay *= 2;  // Exponential backoff
                continue;
            }
            throw $e;  // Re-throw non-rate-limit errors
        }
    }

    throw new RuntimeException("Max retries exceeded");
}
```

⚠️ **Warning:** Always handle API errors gracefully. LLM services can be temporarily unavailable.

---

## What's Next

Congratulations! You've learned the fundamentals of Pagent:

✅ Installed and configured Pagent with environment variables
✅ Created agents using the fluent API
✅ Understood provider abstraction and switching
✅ Built conversational agents with memory
✅ Explored common patterns and troubleshooting

In Chapter 2, we'll dive into **advanced agent features**:

- **Tool Calling**: Give your agents the ability to execute functions
- **Streaming Responses**: Build ChatGPT-like interfaces with real-time text
- **Guards and Middleware**: Add safety checks and request processing
- **Memory Persistence**: Save conversations across sessions

But first, solidify your understanding with this exercise:

### Practice Exercise

Create a file called `practice.php` and implement a "Translation Service" with these requirements:

1. Create two agents: `translator` and `reviewer`
2. The translator should translate text between languages
3. The reviewer should check translations for accuracy
4. Use different temperature settings for each
5. Make them work together: translate, then review

<details>
<summary>💡 Click here for a hint</summary>

Start with:

```php
agent('translator')
    ->provider('anthropic')
    ->system('You are a professional translator')
    ->temperature(0.3);

agent('reviewer')
    ->provider('anthropic')
    ->system('You review translations for accuracy')
    ->temperature(0.1);
```

</details>

<details>
<summary>✅ Click here for the solution</summary>

```php
<?php
require 'vendor/autoload.php';

// Configure the translator
agent('translator')
    ->provider('anthropic')
    ->system('You are a professional translator. Translate accurately while preserving tone and meaning.')
    ->temperature(0.3);

// Configure the reviewer
agent('reviewer')
    ->provider('anthropic')
    ->system('You are a translation reviewer. Check for accuracy, grammar, and natural flow.')
    ->temperature(0.1);

function translateAndReview(string $text, string $targetLanguage): array {
    // Step 1: Translate
    $translation = agent('translator')->prompt(
        "Translate to {$targetLanguage}: {$text}"
    )->content;

    // Step 2: Review
    $review = agent('reviewer')->prompt(
        "Review this {$targetLanguage} translation of '{$text}': {$translation}"
    )->content;

    return [
        'original' => $text,
        'translation' => $translation,
        'review' => $review
    ];
}

// Test the service
$result = translateAndReview(
    "The early bird catches the worm",
    "Spanish"
);

echo "Original: " . $result['original'] . "\n";
echo "Translation: " . $result['translation'] . "\n";
echo "Review: " . $result['review'] . "\n";
```

</details>

---

## Summary

In this chapter, you've learned that Pagent brings the elegance of Pest's testing philosophy to LLM interactions. The fluent API isn't just syntactic sugar—it fundamentally changes how you think about building AI agents. Instead of managing messages arrays and API calls, you focus on agent behavior and conversation flow.

Key takeaways:

- **Agents are stateful**: They remember conversations automatically
- **Providers are swappable**: Change LLMs without changing code structure
- **Configuration is chainable**: Build complex agents with readable method chains
- **Patterns are reusable**: Create templates for common agent types

Next chapter, we'll unlock your agents' full potential with tools, streaming, and persistence. Your agents won't just chat—they'll take action.

---

**Ready for Chapter 2?** [Continue to Advanced Agent Features →](article.part2.md)
