# Pagent Documentation

This directory contains focused guides for Pagent features and application
integration. Start with the [project README](../README.md) if you are new to the
library.

## Feature guides

| Guide                                                     | Covers                                                                  |
| --------------------------------------------------------- | ----------------------------------------------------------------------- |
| [Streaming](streaming.md)                                 | Stream iteration, callbacks, SSE endpoints, and error handling          |
| [Memory and persistence](memory-persistence.md)           | File and SQLite storage, sessions, context windows, and custom adapters |
| [Guards](guards.md)                                       | Built-in safety checks, custom guards, fallbacks, and violations        |
| [Middleware](middleware.md)                               | Logging, metrics, rate limiting, and custom request/response hooks      |
| [Events](events.md)                                       | Agent lifecycle events, listeners, priorities, and global events        |
| [Orchestration and workflows](orchestration-workflows.md) | Pipelines, chains, handoffs, delegation, transforms, and metadata       |
| [Observability](observability.md)                         | OpenTelemetry setup, exporters, spans, sampling, and troubleshooting    |
| [MCP integration](mcp-integration.md)                     | Stdio and HTTP/SSE transports, tool discovery, events, and security     |
| [Ollama integration](ollama-integration.md)               | Local model setup, streaming, tool calling, and deployment              |

## Framework integration

| Framework   | Guide                                                                                      |
| ----------- | ------------------------------------------------------------------------------------------ |
| Vanilla PHP | [Application structure, configuration, and HTTP endpoints](vanilla-php.md)                 |
| Laravel     | [Service providers, facades, controllers, queues, and testing](laravel-integration.md)     |
| Symfony     | [Bundle configuration, services, commands, Messenger, and testing](symfony-integration.md) |
| Slim        | [Dependency injection, middleware, routes, persistence, and testing](slim-integration.md)  |

## Recommended paths

For a first application:

1. Complete the [quick start](../README.md#quick-start).
2. Choose the relevant framework guide.
3. Add [tool calling](../README.md#tool-calling) and
   [conversation memory](../README.md#conversation-memory) as needed.
4. Review [guards](guards.md), [testing and evaluation](../README.md#testing-and-evaluation),
   and [observability](observability.md) before deployment.

For a structured, long-form introduction, use the
[complete guide](../guide/complete.md). The [guide index](../guide/README.md)
provides shorter reading paths by topic.

## Examples

The [examples index](../examples/README.md) maps each feature to a runnable PHP
program. Run examples from the repository root after installing dependencies:

```bash
php examples/01-basic-chat.php
```

Examples backed by the mock provider do not require network access or API keys.
