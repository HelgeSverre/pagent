# Chapter 1: Introduction to Pagent

## What is Pagent?

Pagent is a lightweight, framework-agnostic PHP library for building LLM-powered agents with a Pest-inspired fluent API. Whether you're creating a chatbot, building tool-calling assistants, or orchestrating multi-agent workflows, Pagent provides the foundation you need without the framework overhead.

**Core Philosophy:**

- **Fluent and expressive** - Chain methods naturally like writing prose
- **Provider agnostic** - Switch between Anthropic, OpenAI, Ollama, or mock providers seamlessly
- **Type-safe** - Built on PHP 8.4+ with strict types throughout
- **Testing-friendly** - Mock providers and in-memory state make testing trivial
- **Production-ready** - Battle-tested patterns for error handling, retries, and observability

Think of Pagent as Laravel's Eloquent, but for AI agents - minimal boilerplate, maximum expressiveness.

## Installation

Pagent requires **PHP 8.4 or higher**. Install via Composer:

```bash
composer require pagent/pagent
```

Set your API key as an environment variable:

```bash
export ANTHROPIC_API_KEY="sk-ant-..."
# or
export OPENAI_API_KEY="sk-..."
```

That's it. No configuration files, no service providers, no framework dependencies.

## Your First Agent

Let's create the simplest possible agent - one that responds to a single prompt:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use function Pagent\agent;

$agent = agent('hello-world')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6');

$response = $agent->prompt('Hello! Who are you?');

echo $response->content;
// Output: "Hello! I'm Claude, an AI assistant created by Anthropic..."
```

Let's break down what's happening here.

### The `agent()` Helper Function

```php
function agent(string $name): Agent
```

The `agent()` function is your entry point into Pagent. It creates or retrieves a
named `Agent` and registers a newly created agent immediately. Configuration is
fluent directly on that `Agent`; there is no implicit builder lifecycle.

This makes it safe to configure an agent once and retrieve the same instance
anywhere in the current registry:

```php
// First call: creates and configures agent
agent('chatbot')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful chatbot');

// Later, anywhere in your code: retrieves the same agent
$chatbot = agent('chatbot');
$chatbot->prompt('What can you help me with?');
```

Use `getAgent('chatbot')` when a lookup must not create an agent. It returns
`null` when no such name is registered.

### Explicit Builder Compatibility

`defineAgent()` is available for code that deliberately wants a builder boundary.
It wraps an already-registered `Agent`; call `register()` (or `build()`) to make
that handoff explicit. `Agent::build()` is a compatibility no-op that returns the
same agent, so it is harmless but unnecessary in new code.

```php
use function Pagent\defineAgent;
use function Pagent\getAgent;

$agent = defineAgent('my-agent')
    ->provider('anthropic')      // Set LLM provider
    ->model('claude-sonnet-4-6') // Choose model
    ->temperature(0.7)            // Control randomness (0.0-2.0)
    ->maxTokens(1024)            // Limit response length
    ->system('You are X')        // Set system prompt
    ->register();                // Explicit builder handoff

assert(getAgent('my-agent') === $agent);
```

For ordinary configuration, prefer `agent('my-agent')->provider(...)->model(...)`.

### The Provider Interface

Pagent abstracts LLM providers behind a simple interface:

```php
interface Provider
{
    public function prompt(string $message, array $options = []): object;
}
```

This single-method interface means you can:

- Switch providers without changing your code
- Create custom providers for proprietary models
- Use mock providers for testing

Built-in providers include:

- **Anthropic** - Claude models via Anthropic API
- **OpenAI** - GPT models via OpenAI API
- **Ollama** - Local models via Ollama
- **Mock** - Predefined responses for testing

### Provider Helper Functions

Pagent provides convenience functions for each provider:

```php
use function Pagent\anthropic;
use function Pagent\openai;
use function Pagent\ollama;
use function Pagent\mock;

// String-based provider (uses environment variables for API keys)
agent('bot')->provider('anthropic')->build();

// Or provider helper with custom config
agent('bot')->provider(anthropic(['api_key' => 'sk-...']))->build();

// Mock provider for testing
agent('test')->provider(mock([
    'Hello' => 'Hi there!',
    'Goodbye' => 'See you later!',
]))->build();
```

The string-based approach (`->provider('anthropic')`) automatically loads API keys from environment variables (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`), making it ideal for production. The function-based approach gives you full control over configuration.

## Building a Conversational Agent

Now let's build something more practical - a chatbot that maintains conversation history:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use function Pagent\agent;

// Configure the agent
agent('assistant')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a helpful, concise assistant.')
    ->temperature(0.7)
    ->maxTokens(500)
    ->build();

// Retrieve the agent
$assistant = agent('assistant');

// First interaction
$response1 = $assistant->prompt('My name is Alice.');
echo $response1->content . "\n";
// Output: "Nice to meet you, Alice! How can I help you today?"

// Second interaction - agent remembers context
$response2 = $assistant->prompt('What is my name?');
echo $response2->content . "\n";
// Output: "Your name is Alice."

// Check conversation history
var_dump($assistant->messages);
// Array with 4 messages: 2 user messages, 2 assistant responses
```

### Conversation History

Every `Agent` instance maintains an in-memory `messages` array:

```php
public array $messages = [];
```

When you call `prompt()`, Pagent automatically:

1. Adds your message to `$agent->messages` as `['role' => 'user', 'content' => '...']`
2. Sends all messages to the provider
3. Adds the response to `$agent->messages` as `['role' => 'assistant', 'content' => '...']`

This creates a stateful conversation where each prompt builds on previous context:

```php
$agent = agent('chat')
    ->provider('anthropic')
    ->build();

expect($agent->messages)->toBeEmpty();

$agent->prompt('Hello');
expect($agent->messages)->toHaveCount(2); // user + assistant

$agent->prompt('How are you?');
expect($agent->messages)->toHaveCount(4); // 2 user + 2 assistant
```

**Warning:** Conversation history is stored in-memory only. If you need persistence, you'll need to implement your own storage layer or use Pagent's memory adapters (covered in Chapter 8).

## Configuration and Parameters

Pagent provides fluent methods for all common LLM parameters:

### Model Selection

```php
agent('writer')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->build();
```

Model availability depends on your provider. Pagent doesn't validate model names - that's the provider's responsibility.

### Temperature (Randomness)

```php
agent('creative')
    ->temperature(1.5)  // More random, creative
    ->build();

agent('precise')
    ->temperature(0.2)  // More deterministic, focused
    ->build();
```

Valid range: `0.0` to `2.0`. Values outside this range throw `InvalidArgumentException`:

```php
$agent->temperature(2.5);
// InvalidArgumentException: Temperature must be between 0.0 and 2.0, got 2.50
```

### Max Tokens

```php
agent('brief')
    ->maxTokens(100)   // Short responses
    ->build();

agent('verbose')
    ->maxTokens(4000)  // Longer responses
    ->build();
```

Minimum value: `1`. Zero or negative values throw `InvalidArgumentException`:

```php
$agent->maxTokens(0);
// InvalidArgumentException: Max tokens must be at least 1, got 0
```

### System Prompts

```php
agent('coder')
    ->system('You are an expert PHP developer. Provide concise, working code examples.')
    ->build();
```

System prompts guide the agent's behavior and persona. They're sent with every request and take precedence over user messages.

### Generic Configuration

For provider-specific parameters, use `config()`:

```php
agent('custom')
    ->provider('anthropic')
    ->config([
        'top_p' => 0.9,
        'top_k' => 40,
        'stop_sequences' => ["\n\n"],
    ])
    ->build();
```

The config array is merged with your fluent method calls and passed directly to the provider.

## Provider Switching

One of Pagent's most powerful features is provider portability. The same agent code works across providers:

```php
// Development: use mock provider
if (getenv('APP_ENV') === 'testing') {
    agent('bot')->provider(mock([
        'Hello' => 'Hi there!',
    ]))->build();
}

// Production: use real provider
else {
    agent('bot')->provider('anthropic')->build();
}

// Same code works with both
$bot = agent('bot');
$response = $bot->prompt('Hello');
```

This enables powerful testing patterns:

```php
use function Pagent\mock;

// Test without API calls
it('greets users correctly', function () {
    $agent = agent('greeter')
        ->provider(mock(['Hi' => 'Hello, friend!']))
        ->build();

    $response = $agent->prompt('Hi');

    expect($response->content)->toBe('Hello, friend!');
});
```

**Best Practice:** Always use mock providers in unit tests. Reserve real API calls for integration tests with the `@group api` annotation.

## Response Objects

Every `prompt()` call returns a response object with these properties:

```php
$response = $agent->prompt('Hello');

$response->content;   // string - The assistant's response text
$response->model;     // string - Model that generated response
$response->tokens;    // int - Total tokens used
$response->provider;  // string - Provider name ('anthropic', 'openai', etc)
```

For the mock provider, you'll also see:

```php
// Mock provider response
return (object) [
    'content' => 'Mock response to: Hello',
    'model' => 'mock',
    'tokens' => 25,  // strlen(message) + strlen(response)
    'provider' => 'mock',
];
```

**Warning:** Different providers may include additional fields (like `tool_calls`, `finish_reason`, etc). Always check the provider's documentation for the complete response structure.

## Error Handling

Pagent throws exceptions for common errors:

### No Provider Set

```php
$agent = agent('broken')->build();  // No provider configured

$agent->prompt('Hello');
// RuntimeException: No provider set for agent 'broken'
```

Always configure a provider before calling `prompt()`.

### Invalid Parameters

```php
$agent->temperature(3.0);
// InvalidArgumentException: Temperature must be between 0.0 and 2.0, got 3.00

$agent->maxTokens(-100);
// InvalidArgumentException: Max tokens must be at least 1, got -100
```

Pagent validates parameters before sending them to providers, giving you early, clear error messages.

### API Errors

Provider-specific errors (authentication, rate limits, network issues) propagate as exceptions from the provider layer. Always wrap production code in try-catch:

```php
try {
    $response = $agent->prompt('Hello');
    echo $response->content;
} catch (\RuntimeException $e) {
    // Handle API errors
    error_log("Agent error: " . $e->getMessage());
    echo "Sorry, I'm having trouble connecting right now.";
}
```

## The Global Registry

Pagent maintains a global registry of all agents:

```php
use function Pagent\agents;
use function Pagent\clearAgents;

// Create multiple agents
agent('alice')->provider('anthropic')->build();
agent('bob')->provider('openai')->build();

// Get all agents
$all = agents();
// Returns: ['alice' => Agent, 'bob' => Agent]

// Clear registry (useful for testing)
clearAgents();

$all = agents();
// Returns: []
```

The registry enables powerful patterns:

```php
// Define agents in a bootstrap file
require 'agents/chatbot.php';
require 'agents/translator.php';
require 'agents/coder.php';

// Use them anywhere without imports
$chatbot = agent('chatbot');
$translator = agent('translator');
$coder = agent('coder');
```

**Tip:** Call `clearAgents()` in your test setup to ensure a clean state between tests.

## Complete Example: Multi-Agent System

Let's put it all together with a practical example - a support bot that delegates to specialist agents:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\clearAgents;

// Define specialist agents
agent('technical-support')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a technical support specialist. Provide detailed troubleshooting steps.')
    ->temperature(0.3)
    ->build();

agent('sales')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a friendly sales representative. Help customers find the right product.')
    ->temperature(0.7)
    ->build();

agent('general')
    ->provider('anthropic')
    ->model('claude-sonnet-4-6')
    ->system('You are a general support agent. Answer common questions briefly.')
    ->temperature(0.5)
    ->build();

// Simple router (in production, use a classifier)
function route(string $query): string
{
    if (str_contains(strtolower($query), 'bug') || str_contains(strtolower($query), 'error')) {
        return 'technical-support';
    }

    if (str_contains(strtolower($query), 'buy') || str_contains(strtolower($query), 'price')) {
        return 'sales';
    }

    return 'general';
}

// Handle customer query
$query = 'I found a bug in your checkout process';
$agentName = route($query);
$specialist = agent($agentName);

$response = $specialist->prompt($query);

echo "Routed to: {$agentName}\n";
echo "Response: {$response->content}\n";
```

This example demonstrates:

- **Multiple agents** with different system prompts and temperatures
- **Agent retrieval** via the global registry
- **Specialization** - each agent has a specific role
- **Routing** - selecting the right agent for each query

In Chapter 4, you'll learn how to implement this routing with LLM-powered classification instead of keyword matching.

## What's Next?

You now understand Pagent's core concepts:

- The `agent()` helper and builder pattern
- Provider abstraction and switching
- Conversation history management
- Configuration and validation
- The global registry

In **Chapter 2: Working with Providers**, we'll dive deeper into:

- Configuring Anthropic, OpenAI, and Ollama providers
- Provider-specific features and limitations
- API key management strategies
- Advanced mock provider patterns for testing
- Handling provider errors and fallbacks

**Key Takeaways:**

- Pagent uses a fluent API inspired by Pest
- The `agent()` function creates or retrieves agents from a global registry
- Provider abstraction enables switching between LLM providers
- Agents maintain in-memory conversation history automatically
- Mock providers support tests without API calls
- Configuration is validated before reaching the provider layer

Continue to [Chapter 2: Working with Providers](./article.part2.md) →
