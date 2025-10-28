# Pagent Documentation

Comprehensive guides for integrating Pagent into your PHP applications.

## 📚 Guides

### Framework Integration

- [Vanilla PHP Integration](vanilla-php.md) - Simple setup without any framework
- [Slim Framework Integration](slim-integration.md) - Complete guide for Slim 4.x with DI and middleware
- [Laravel Integration](laravel-integration.md) - Service providers, facades, and Artisan commands
- [Symfony Integration](symfony-integration.md) - Bundle configuration and service container

### Patterns & Best Practices

- [Agent Orchestration & Workflows](orchestration-workflows.md) - Pipelines, chains, handoffs, and delegation
- Testing AI Agents _(coming soon)_ - Mocking and test strategies
- Production Deployment _(coming soon)_ - Scaling and monitoring

## 🎯 Quick Links

### Core Documentation

- [Main README](../README.md) - Project overview and quick start
- [API Reference](../guide/05-api-reference.md) - Complete API documentation
- [HOW IT WORKS](../HOW_IT_WORKS.md) - Technical deep dive

### Guides by Learning Style

1. [Getting Started (Conversational)](../guide/01-getting-started-conversational.md)
2. [Recipes (Task-Oriented)](../guide/02-recipes-task-oriented.md)
3. [Quick Start (Minimal)](../guide/03-quick-start-minimal.md)
4. [Concepts (Deep Dive)](../guide/04-concepts-deep-dive.md)
5. [API Reference (Technical)](../guide/05-api-reference.md)

## 🔧 Integration Overview

### The Centralized Pattern

Instead of configuring agents everywhere:

```php
// ❌ Repeated configuration
agent('support')
    ->provider('anthropic')
    ->model('claude-3-haiku-20240307')
    ->system('You are a support agent...');
```

Configure once, use everywhere:

```php
// ✅ In config/agents.php
agent('support')
    ->provider('anthropic')
    ->model('claude-3-haiku-20240307')
    ->system('You are a support agent...');

// ✅ Anywhere in your app
$response = pagent('support')->prompt('Help needed!');
```

### Supported Frameworks

| Framework       | Status      | Guide                           |
| --------------- | ----------- | ------------------------------- |
| **Vanilla PHP** | ✅ Complete | [Guide](vanilla-php.md)         |
| **Slim**        | ✅ Complete | [Guide](slim-integration.md)    |
| **Laravel**     | ✅ Complete | [Guide](laravel-integration.md) |
| **Symfony**     | ✅ Complete | [Guide](symfony-integration.md) |

## 💡 Common Use Cases

### 1. Customer Support Bot

```php
// config/agents.php
agent('support')
    ->provider('anthropic')
    ->tool('search_orders', fn($email) => /* ... */)
    ->tool('process_refund', fn($orderId) => /* ... */);

// Your app
$response = pagent('support')->prompt($customerMessage);
```

### 2. Content Generation

```php
// config/agents.php
agent('blog-writer')->provider('openai')->model('gpt-4');
agent('social-media')->provider('openai')->model('gpt-4o-mini');

// Your app
$blog = pagent('blog-writer')->prompt("Write about: {$topic}");
$tweet = pagent('social-media')->prompt("Summarize: {$blog}");
```

### 3. Multi-Agent Workflow

```php
use function Pagent\pipeline;

$result = pipeline('research')
    ->agent('researcher')
    ->agent('fact-checker')
    ->agent('report-generator')
    ->run($query);
```

## 🚀 Next Steps

1. **Choose your integration**: Select the guide that matches your project setup
2. **Framework-specific setup**: Follow the detailed guide for your framework
3. **Explore patterns**: Learn about multi-agent workflows and orchestration
4. **Production ready**: Review [SECURITY.md](../SECURITY.md)
