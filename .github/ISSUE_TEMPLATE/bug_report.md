---
name: Bug Report
about: Report a bug or unexpected behavior
title: '[Bug] '
labels: bug
assignees: ''
---

## Bug Description

A clear description of what the bug is.

## Steps to Reproduce

1. Create agent with '...'
2. Add tool '...'
3. Send message '...'
4. See error

## Expected vs Actual Behavior

**Expected:** What you expected to happen  
**Actual:** What actually happened

## Code Sample

```php
agent('bot')->tool('example', 'Example', fn() => 'test')->prompt('Hello');
```

## Environment

- **PHP:** [e.g., 8.4.1]
- **Pagent:** [e.g., 0.5.0]
- **Provider:** [e.g., Anthropic]

## Stack Trace

```
Paste error here
```
