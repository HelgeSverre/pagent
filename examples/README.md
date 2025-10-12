# Pagent Examples

This directory contains working examples demonstrating Pagent's features.

## Prerequisites

Make sure you have API keys configured in your `.env` file:

```bash
cp ../.env.example ../.env
# Edit .env and add your API keys
```

## Running Examples

### 01 - Basic Chat

Simple conversation examples with different providers:

```bash
php examples/01-basic-chat.php
```

### 02 - Tool Calling

Automatic tool execution with function calling:

```bash
php examples/02-tool-calling.php
```

### 03 - Context & Memory

Conversation history and context management:

```bash
php examples/03-context-memory.php
```

### 04 - Multi-Provider

Using different providers for different tasks:

```bash
php examples/04-multi-provider.php
```

## Notes

- Examples use `unset($variable)` to trigger AgentBuilder destruction and registration
- Most examples work with OpenAI (always available) and optionally with Anthropic
- Mock provider examples work without any API keys
