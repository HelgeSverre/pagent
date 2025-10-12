# Pagent Quick Start

**TL;DR - Get Running in 60 Seconds**

---

## Install

```bash
composer require pagent/pagent
```

## Basic Usage

```php
use function Pagent\agent;
use function Pagent\anthropic;

agent('bot')->provider(anthropic())->system('You are helpful.');
echo agent('bot')->prompt('Hello')->content;
```

---

## Core Patterns

### Create Agent

```php
agent('name')->provider(anthropic())->model('claude-3-5-sonnet-20241022');
```

### Send Prompt

```php
$response = agent('name')->prompt('Your question here');
echo $response->content;
```

### Add Tools

```php
agent('calc')->tool('add', fn($a, $b) => $a + $b);
```

### Add Guards

```php
use Pagent\Guards\PIIGuard;
agent('secure')->guard(new PIIGuard());
```

### Pipeline

```php
use function Pagent\pipeline;

pipeline('flow')
    ->agent('writer')
    ->agent('editor')
    ->run('Write a post');
```

### Evaluation

```php
use function Pagent\evaluate;
use Pagent\Evaluation\Dataset;

evaluate('agent')
    ->dataset(Dataset::fromArray([...]))
    ->run();
```

---

## Providers

| Provider  | Function             | Model Example                |
| --------- | -------------------- | ---------------------------- |
| Anthropic | `anthropic()`        | `claude-3-5-sonnet-20241022` |
| OpenAI    | `openai()`           | `gpt-4o`                     |
| Mock      | `mock(['q' => 'a'])` | N/A                          |

---

## Guards

| Guard                  | Detects                           |
| ---------------------- | --------------------------------- |
| `PIIGuard`             | SSN, emails, phones, credit cards |
| `ContentFilterGuard`   | Profanity, harmful content        |
| `PromptInjectionGuard` | Injection attempts                |

---

## Response Object

```php
$response = agent('bot')->prompt('Hi');

$response->content;    // string - The response text
$response->model;      // string - Model used
$response->tokens;     // int - Token count
$response->provider;   // string - Provider name
$response->stop_reason; // string - Why it stopped
```

---

## Environment Variables

```bash
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
```

---

## Common Snippets

### Conversation

```php
$agent = agent('chat')->provider(anthropic());
$agent->prompt('My name is Alice');
$agent->prompt('What is my name?'); // "Alice"
```

### Error Handling

```php
try {
    agent('test')->prompt('...');
} catch (Exception $e) {
    echo $e->getMessage();
}
```

### Custom Config

```php
agent('precise')
    ->temperature(0.1)
    ->maxTokens(500)
    ->model('claude-3-5-sonnet-20241022');
```

---

## Examples

See `/examples` folder:

- `01-basic-usage.php`
- `02-tool-calling.php`
- `03-guards.php`
- `09-multi-agent.php`

---

## Need More?

- [Conversational Guide](01-getting-started-conversational.md) - Learn with examples
- [Recipe Guide](02-recipes-task-oriented.md) - Step-by-step solutions
- [Conceptual Guide](04-concepts-deep-dive.md) - How it works
- [API Reference](05-api-reference.md) - Complete docs
