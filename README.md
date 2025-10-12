# Pagent 🩸

PHP library for interacting with language models (LLMs), inspired by the functional-fluent-builder pattern
in [Pest](https://pestphp.com).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgesverre/pagent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/pagent)

## Installation

```bash
composer require helgesverre/pagent


```

## Quick Start

```php
// Configure an agent
agent('assistant')
    ->provider('anthropic')
    ->system('You are a helpful assistant')
    ->temperature(0.7);

// Use the agent
$response = agent('assistant')->prompt('Hello!');
echo $response->content;
```

## Providers

### Mock Provider (for testing)

```php
$mock = mock([
    'Hello' => 'Hi there!',
    'How are you?' => 'I am doing great!'
]);

$response = $mock->prompt('Hello');
echo $response->content; // "Hi there!"
```

### Anthropic (Claude)

```bash
export ANTHROPIC_API_KEY="your-key"
```

```php
$claude = anthropic();
$response = $claude->prompt('Hello!', [
    'model' => 'claude-3-sonnet-20240229',
    'max_tokens' => 100
]);
```

### OpenAI (GPT)

```bash
export OPENAI_API_KEY="your-key"
```

```php
$gpt = openai();
$response = $gpt->prompt('Hello!', [
    'model' => 'gpt-4',
    'temperature' => 0.8
]);
```

## Agent Pattern

Agents provide a higher-level abstraction with conversation history:

```php
// Define an agent
agent('support')
    ->provider('anthropic')
    ->system('You are a customer support agent')
    ->model('claude-3-haiku-20240307')
    ->temperature(0.3);

// Have a conversation
$agent = agent('support');
$agent->prompt('I need help with my order');
$agent->prompt('Order number is 12345');

// Access conversation history
foreach ($agent->messages as $message) {
    echo "[{$message['role']}]: {$message['content']}\n";
}
```

## Provider-Specific Features

The library is intentionally "leaky" - you can use provider-specific features:

```php
// Anthropic-specific models
$response = anthropic()->prompt('Complex analysis task', [
    'model' => 'claude-3-opus-20240229',
    'max_tokens' => 4096
]);

// OpenAI-specific features
$response = openai()->prompt('Generate JSON data', [
    'model' => 'gpt-3.5-turbo-1106',
    'response_format' => ['type' => 'json_object']
]);
```

## Tool Calling

Define tools using PHP closures with automatic JSON schema generation:

```php
use Pagent\Tool\Tool;

// Create a tool from a closure
$weatherTool = Tool::fromClosure(
    'get_weather',
    'Get the current weather for a location',
    fn(string $location, bool $include_forecast = false) => "Weather data..."
);

// Add tools to an agent
$agent = agent('assistant')
    ->provider('anthropic')
    ->tool('calculate', 'Perform calculations', fn(int $a, int $b) => $a + $b)
    ->tool('get_time', 'Get current time', fn(string $tz = 'UTC') => date('H:i:s'));

// Execute tools
$result = $agent->executeTool('calculate', [10, 5]); // 15

// Generate provider-specific schemas
$anthropicSchema = $weatherTool->toAnthropicSchema();
$openaiSchema = $weatherTool->toOpenAISchema();
```

Type hints are automatically converted to JSON schema types:

- `string` → `"string"`
- `int` → `"integer"`
- `float` → `"number"`
- `bool` → `"boolean"`
- `array` → `"array"`

## Testing

The library includes comprehensive test suites:

```bash
# Run unit tests (no API calls)
./vendor/bin/pest --exclude-group=api

# Run API integration tests (requires API keys)
# Copy .env.example to .env and add your keys
cp .env.example .env
./vendor/bin/pest --group=api
```

## Documentation

We've created **5 different guide styles** so you can learn in the way that works best for you:

1. **[Getting Started (Conversational)](guide/01-getting-started-conversational.md)** - Friendly, interactive
   introduction with examples
2. **[Recipes (Task-Oriented)](guide/02-recipes-task-oriented.md)** - Step-by-step solutions for common tasks
3. **[Quick Start (Minimal)](guide/03-quick-start-minimal.md)** - TL;DR reference for when you're in a hurry
4. **[Concepts (Deep Dive)](guide/04-concepts-deep-dive.md)** - Understand the architecture and design decisions
5. **[API Reference (Technical)](guide/05-api-reference.md)** - Complete technical documentation

**New to Pagent?** Start with the [Getting Started Guide](guide/01-getting-started-conversational.md).

**Need something specific?** Check the [Recipes Guide](guide/02-recipes-task-oriented.md).

**In a hurry?** The [Quick Start](guide/03-quick-start-minimal.md) has you covered.
