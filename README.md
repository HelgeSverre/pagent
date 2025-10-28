# Pagent 🩸

**A Pest-inspired LLM Agent Framework for PHP**

Build intelligent agents with automatic tool calling, multi-provider support, safety guards, and multi-agent orchestration—all with a clean, fluent API.

[![Latest Version](https://img.shields.io/packagist/v/helgesverre/pagent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/pagent)
[![Tests](https://img.shields.io/github/actions/workflow/status/helgesverre/pagent/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/helgesverre/pagent/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/helgesverre/pagent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/pagent)
[![PHP Version](https://img.shields.io/packagist/php-v/helgesverre/pagent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/pagent)
[![License](https://img.shields.io/packagist/l/helgesverre/pagent.svg?style=flat-square)](https://github.com/helgesverre/pagent/blob/main/LICENSE)

---

## Why Pagent?

- **🧪 Pest-Inspired API** - Fluent, expressive syntax that feels natural
- **🌊 Real-Time Streaming** - SSE streaming for ChatGPT-like experiences
- **💾 Memory & Persistence** - SQLite, File, and custom storage adapters
- **🔧 Automatic Tool Calling** - JSON schema generation from PHP functions
- **🤖 Multi-Provider** - Anthropic Claude, OpenAI GPT, Mock (for testing)
- **🛡️ Safety Guards** - PII detection, content filtering, prompt injection prevention
- **📊 Evaluation Framework** - Test datasets with automated metrics and reports
- **🔄 Multi-Agent Orchestration** - Pipeline, handoff, and delegation patterns
- **⚡ Production Ready** - 265+ tests, PHPStan level 9, PHP 8.3+ type safety

---

## Installation

```bash
composer require helgesverre/pagent
```

**Requirements:**

- PHP 8.3 or higher
- Composer 2.x

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

// Or stream responses in real-time
agent('assistant')->streamTo('Tell me a story', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});

// Persist conversations across sessions
agent('support')
    ->memory('sqlite', ['path' => 'storage/conversations.db'])
    ->sessionId('user-123')
    ->contextWindow(100000)
    ->prompt('Hello');
```

**📖 Explore:** [Streaming Guide](docs/streaming.md) | [Memory & Persistence](docs/memory-persistence.md)

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

### Learning Guides

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

### Integration Guides

Learn how to integrate Pagent into your application:

- **[Centralized Configuration Pattern](docs/centralized-configuration.md)** - Set up a global `agents.php` file (recommended)
- **[Slim Framework Integration](docs/slim-integration.md)** - Complete Slim 4.x setup with DI and middleware
- **Laravel Integration** - Coming soon
- **Symfony Integration** - Coming soon

See the [docs/](docs/) folder for all integration guides.

---

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on:

- Development setup
- Running tests
- Code style guidelines
- Pull request process

Read our [Code of Conduct](CODE_OF_CONDUCT.md) and [Security Policy](SECURITY.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Created by [Helge Sverre](https://helgesver.re).

Inspired by [Pest](https://pestphp.com)'s elegant API design.
