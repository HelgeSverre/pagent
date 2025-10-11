# Pagent 🩸

PHP library for interacting with language models (LLMs).

## Features

- Fluent API inspired by PestPHP
- Multiple provider support (Anthropic, OpenAI)
- Mock provider for testing
- Conversation history tracking
- **Tool/Function calling with automatic schema inference**
- Provider-specific features exposed (intentionally leaky abstraction)
- Global helper functions

## Installation

```bash
composer require pagent/pagent
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

## Examples

See the `examples/` directory for working demonstrations:

- **[01-basic-chat.php](examples/01-basic-chat.php)** - Simple conversations with different providers
- **[02-tool-calling.php](examples/02-tool-calling.php)** - Automatic tool execution
- **[03-context-memory.php](examples/03-context-memory.php)** - Conversation history and context
- **[04-multi-provider.php](examples/04-multi-provider.php)** - Comparing providers
- **[05-complete-demo.php](examples/05-complete-demo.php)** - Comprehensive feature demonstration

Legacy examples:
- `example.php` - Basic agent usage
- `example-api.php` - Real API examples  
- `example-tools.php` - Tool schema generation

## What's New in v0.2.0

✨ **Tool/Function Calling is Live!**

Agents now automatically execute tools when needed:

```php
agent('assistant')
    ->provider('openai')
    ->tool('calculate', 'Do math', fn(int $a, int $b) => $a + $b);

$response = agent('assistant')->prompt('What is 25 + 17?');
// Tool is automatically called and result is returned: "The answer is 42"
```

Features:
- ✅ Automatic tool execution loop
- ✅ Works with both Anthropic and OpenAI
- ✅ Supports multiple tools per agent
- ✅ Type inference from PHP closures
- ✅ JSON schema auto-generation

## License

MIT
