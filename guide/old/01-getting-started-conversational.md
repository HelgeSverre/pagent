# Getting Started with Pagent

**Interactive & Conversational Style Guide**

---

Hey there! 👋 Welcome to Pagent. Let's get you up and running in minutes.

## What is Pagent?

Think of Pagent as your Swiss Army knife for building AI agents in PHP. Whether you're adding a chatbot to your Laravel app or building a complex multi-agent system, Pagent makes it dead simple.

## Quick Install

```bash
composer require pagent/pagent
```

That's it. No config files. No boilerplate. Let's write some code.

## Your First Agent

Let's create an agent that actually does something useful. How about a code reviewer?

```php
<?php
// review.php

require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\anthropic;

// Create an agent named "reviewer"
agent('reviewer')
    ->provider(anthropic())
    ->system('You are a helpful code reviewer who provides constructive feedback.')
    ->model('claude-3-5-sonnet-20241022');

// Ask it to review some code
$response = agent('reviewer')->prompt('Review this: function add($a,$b){return $a+$b;}');

echo $response->content;
```

Run it:

```bash
php review.php
```

**What just happened?**

1. You created an agent with `agent('reviewer')`
2. Configured it to use Anthropic's Claude
3. Gave it a personality with `system()`
4. Asked it to review code with `prompt()`

The agent is automatically registered and ready to use anywhere in your app.

## Real-World Example: Multi-Turn Conversations

Agents remember what you've talked about:

```php
agent('assistant')
    ->provider(anthropic())
    ->system('You are a helpful assistant.');

$agent = agent('assistant');

$response1 = $agent->prompt('My name is Alice.');
// "Nice to meet you, Alice!"

$response2 = $agent->prompt('What is my name?');
// "Your name is Alice."
```

See? The agent maintains context automatically. No session management, no database—just works.

## Using Different Providers

Want to switch to OpenAI? Change one line:

```php
use function Pagent\openai;

agent('gpt-agent')
    ->provider(openai())
    ->model('gpt-4o');
```

Need to test without burning API credits? Use the mock provider:

```php
use function Pagent\mock;

agent('test')
    ->provider(mock([
        'Hello' => 'Hi there!',
        'Goodbye' => 'See you later!'
    ]));

$response = agent('test')->prompt('Hello');
// Returns: "Hi there!"
```

## What's Next?

Now that you've got the basics down, here's where to go:

- **Add Tools** → Let your agent call functions and APIs
- **Safety Guards** → Filter PII, harmful content, and prompt injections
- **Multi-Agent Systems** → Chain agents together for complex workflows
- **Evaluation** → Measure and improve your agent's performance

Check out the examples in the `/examples` folder or dive into the other guides:

- **Task-Oriented Guide** → Step-by-step recipes for common tasks
- **Quick-Start Guide** → Ultra-minimal TL;DR version
- **Conceptual Guide** → Deep dive into how Pagent works
- **API Reference** → Complete technical documentation

---

**Having trouble?** Check the examples folder or open an issue on GitHub. We're here to help!
