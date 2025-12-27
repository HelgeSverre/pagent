# Pagent Documentation

Comprehensive guides for integrating Pagent into your PHP applications.

## 📚 Guides

### Core Features

- [Guards](guards.md) - Safety validation for LLM responses (PII, content filtering, prompt injection)
- [Middleware](middleware.md) - Request/response hooks for logging, metrics, and rate limiting
- [Events](events.md) - Lifecycle hooks for monitoring and custom behavior
- [Streaming](streaming.md) - Real-time SSE streaming implementation
- [Memory & Persistence](memory-persistence.md) - SQLite, File, and custom storage adapters
- [Observability](observability.md) - OpenTelemetry integration for tracing
- [MCP Integration](mcp-integration.md) - Model Context Protocol for external tool servers

### Framework Integration

- [Vanilla PHP Integration](vanilla-php.md) - Simple setup without any framework
- [Slim Framework Integration](slim-integration.md) - Complete guide for Slim 4.x with DI and middleware
- [Laravel Integration](laravel-integration.md) - Service providers, facades, and Artisan commands
- [Symfony Integration](symfony-integration.md) - Bundle configuration and service container

### Patterns & Best Practices

- [Agent Orchestration & Workflows](orchestration-workflows.md) - Pipelines, chains, handoffs, and delegation
- [Ollama Integration](ollama-integration.md) - Local LLM setup and usage

## 🎯 Quick Links

### Core Documentation

- [Main README](../README.md) - Project overview and quick start
- [Complete Guide](../guide/complete.md) - Comprehensive 28-chapter tutorial
- [Guide README](../guide/README.md) - Learning paths and chapter overview

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
