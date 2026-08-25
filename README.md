# Pagent

**A fluent LLM agent framework for PHP, inspired by Pest.**

Pagent provides a compact API for building stateful AI agents with tool calling,
streaming, multiple model providers, safety guards, evaluation, and multi-agent
workflows.

[![Latest Version](https://img.shields.io/packagist/v/helgesverre/pagent.svg?style=flat-square&v=1735257600)](https://packagist.org/packages/helgesverre/pagent)
[![Tests](https://img.shields.io/github/actions/workflow/status/helgesverre/pagent/tests.yml?branch=main&label=tests&style=flat-square&v=1735257600)](https://github.com/helgesverre/pagent/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/helgesverre/pagent.svg?style=flat-square&v=1735257600)](https://packagist.org/packages/helgesverre/pagent)
[![PHP Version](https://img.shields.io/packagist/php-v/helgesverre/pagent.svg?style=flat-square&v=1735257600)](https://packagist.org/packages/helgesverre/pagent)
[![License](https://img.shields.io/github/license/HelgeSverre/pagent?style=flat-square&v=1735257600)](https://github.com/HelgeSverre/pagent/blob/main/LICENSE)

## Features

- Fluent, named agent configuration
- Anthropic, OpenAI, OpenCode Zen/Go, Ollama, and deterministic mock providers
- Automatic tool schemas generated from typed PHP closures
- Reusable class-based tools for files, search, shell commands, PDFs, and HTTP
- Streaming responses and Server-Sent Events support
- File and SQLite conversation persistence
- Guards, middleware, lifecycle events, and fallbacks
- Pipelines, handoffs, delegation, and multi-agent workflows
- Dataset-based evaluation with built-in and custom metrics
- Token and cost tracking
- OpenTelemetry tracing for agents, providers, tools, guards, and workflows
- Model Context Protocol (MCP) client support over stdio and HTTP/SSE

## Requirements

- PHP 8.4.1 or later
- Composer 2
- The PHP cURL extension
- A provider API key, unless you use Ollama or the mock provider

## Installation

```bash
composer require helgesverre/pagent
```

The core package intentionally has a small runtime footprint. Telemetry exporters,
the `Bash` tool, full-text search, and JSON-schema evaluation are optional; Composer
lists their packages under `suggest`. Install only the integrations your application
uses (for example, `composer require open-telemetry/sdk` for telemetry or
`composer require symfony/process` for `Bash`).

Set the environment variable for the provider you plan to use:

```bash
export OPENAI_API_KEY="your-api-key"
export ANTHROPIC_API_KEY="your-api-key"
export OPENCODE_API_KEY="your-api-key"
```

For local development in this repository, copy the supplied environment file and
add your credentials:

```bash
cp .env.example .env
composer install
```

## Quick start

Composer loads Pagent's helper functions automatically. Define a named agent and
send it a prompt:

```php
<?php

require __DIR__.'/vendor/autoload.php';

$assistant = agent('assistant')
    ->provider('openai')
    ->system('You are a concise and helpful PHP assistant.')
    ->temperature(0.3);

$response = $assistant->prompt('Explain readonly properties in PHP.');

echo $response->content;
```

`agent()` always returns and immediately registers an `Agent`; it never depends on
builder destruction. Use `getAgent()` when a missing name must remain missing, or
`defineAgent()` when you want an explicit configuration boundary. `build()` remains
a harmless compatibility no-op on `Agent`.

Named agents are registered for reuse, so application code can retrieve the same
configured agent later:

```php
$response = agent('assistant')->prompt('Show a short example.');
```

See the [vanilla PHP guide](docs/vanilla-php.md) for a complete application
layout, or choose one of the [framework integration guides](#framework-integration).

## Providers

| Provider  | Configuration                          | Typical use                        |
| --------- | -------------------------------------- | ---------------------------------- |
| Anthropic | `ANTHROPIC_API_KEY`                    | Claude models                      |
| OpenAI    | `OPENAI_API_KEY`                       | OpenAI chat models                 |
| OpenCode  | `OPENCODE_API_KEY`                     | Zen/Go models over their protocol  |
| Ollama    | Local server, by default on port 11434 | Local and private model execution  |
| Mock      | In-memory response map                 | Unit tests and deterministic demos |

Use a provider name for standard configuration:

```php
$agent = agent('writer')
    ->provider('anthropic')
    ->model('your-model-id')
    ->maxTokens(1_000);
```

Pass configuration options with the provider name or supply a provider instance
when you need more control:

```php
use Pagent\Providers\Ollama;

$local = agent('local')
    ->provider(new Ollama([
        'base_url' => 'http://127.0.0.1:11434',
        'timeout' => 180,
    ]))
    ->model('qwen3:8b');
```

Custom adapters should implement `IdentifiedProvider` and return a
`Pagent\ProviderCapabilities` value instead of relying on their class name.
Implement `StreamingProvider` when the adapter can produce incremental
`StreamResponse`s. This makes provider identity, tools, system-message support,
and streaming explicit for both built-ins and third-party adapters.

Provider-specific request options can be passed to `prompt()`:

```php
$response = openai()->prompt('Return a JSON object with a status field.', [
    'model' => 'your-model-id',
    'response_format' => ['type' => 'json_object'],
]);
```

OpenCode supports chat-completions, Responses, and Messages model protocols. The
provider defaults to chat-completions; choose a protocol globally, per model, or
per prompt when the selected OpenCode model requires it. The default model ID
depends on the selected gateway:

```php
// Zen uses https://opencode.ai/zen/v1 and x-preview-f-free.
$zen = opencode();
$zenResponse = $zen->prompt('Hello!');

// Go uses https://opencode.ai/zen/go/v1 and ox-alpha-free.
$go = opencode(['gateway' => 'go']);
$goResponse = $go->prompt('Hello!');

// A model using the Responses protocol.
$responses = opencode([
    'protocol' => 'responses',
]);

// Or select protocols by model while keeping a chat-completions default.
$mixed = opencode([
    'model_protocols' => ['your-responses-model' => 'responses'],
]);

// String aliases are available for agent configuration.
$coder = agent('coder')
    ->provider('opencode-go')
    ->model('ox-alpha-free');
```

For local inference setup and model selection, see the
[Ollama integration guide](docs/ollama-integration.md).

## Tool calling

Pagent derives a JSON schema from a closure's parameter names, type declarations,
and default values. The agent can then select and execute the tool during a model
conversation.

```php
$support = agent('order-support')
    ->provider('openai')
    ->system('Use the available tools to answer questions about orders.')
    ->tool(
        'find_order',
        'Find an order by its identifier',
        function (string $orderId, bool $includeItems = false): array {
            return [
                'id' => $orderId,
                'status' => 'shipped',
                'items' => $includeItems ? ['Keyboard', 'Mouse'] : [],
            ];
        },
    );

$response = $support->prompt('Where is order ORD-1042?');
```

For reusable tools, implement a class or use the included tools:

```php
use Pagent\Tools\FileRead;
use Pagent\Tools\Glob;
use Pagent\Tools\Grep;

$codebase = agent('codebase-assistant')
    ->provider('anthropic')
    ->tools([
        new Glob(baseDir: __DIR__),
        new Grep(baseDir: __DIR__),
        new FileRead(baseDir: __DIR__),
    ]);

$response = $codebase->prompt('Find the classes that implement the Provider contract.');
```

Pagent includes `DataExtract`, `FileRead`, `FileWrite`, `Glob`, `Grep`,
`PdfReader`, and `WebFetch`; `Bash` and `SearchTool` additionally require their
suggested Composer packages. Scope tools such as file and shell tools to the
narrowest directory and permissions your application requires.

Custom and MCP tools share one provider-neutral `Pagent\Contracts\Tool` contract
(`getName()`, `getDescription()`, `getInputSchema()`, and `execute()`). Pagent
serializes that JSON Schema at the provider boundary, so tool implementations do
not contain Anthropic- or OpenAI-specific wire formats.

Runnable examples:
[closure tools](examples/02-tool-calling.php) and
[MCP-provided tools](examples/20-mcp-client.php).

## Streaming

Use `streamTo()` for a callback-based interface:

```php
$assistant->streamTo('Write a short introduction to PHP generators.', function ($chunk): void {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});
```

Use `stream()` when you need to inspect start, text, tool, and end chunks or collect
the final response yourself:

```php
$stream = $assistant->stream('Summarize dependency injection in three points.');

foreach ($stream->getStream() as $chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
    }
}
```

Ordinary streams are incremental. Pagent intentionally quarantines a stream before
calling your callback when it has an output policy that needs the complete response
(such as PII/content guards), a legacy two-argument guard, or response-transforming
middleware. This prevents unsafe prefixes from being delivered; use phase-aware
incremental `OutputGuard`s only when their policy is safe across chunk boundaries.

See the [streaming guide](docs/streaming.md) for SSE endpoints, client code, error
handling, and streaming tool calls. The repository also contains a
[basic streaming example](examples/10-streaming-basic.php) and a complete
[SSE endpoint](examples/10-streaming-sse-endpoint.php).

## Conversation memory

Agents retain context in memory during a process. Add a storage adapter and session
identifier to continue conversations across requests or application restarts:

```php
$support = agent('support')
    ->provider('anthropic')
    ->memory('sqlite', ['path' => __DIR__.'/storage/conversations.db'])
    ->sessionId('customer-42')
    ->contextWindow(20_000);

$support->prompt('My order number is ORD-1042.');
$response = $support->prompt('What order are we discussing?');
```

File and SQLite adapters are included. The [memory and persistence guide](docs/memory-persistence.md)
covers session isolation, custom adapters, context windows, and production usage.
See also the runnable [file](examples/11-memory-file.php),
[SQLite](examples/11-memory-sqlite.php), and
[multi-session](examples/11-memory-multi-session.php) examples.

Changing `sessionId()` clears the in-memory conversation and loads only that
session on the next turn. Failed turns are rolled back, so retries do not replay a
partial user message.

## Guards and middleware

Guards validate agent interactions and can return a controlled fallback when a
rule is violated. `PromptInjectionGuard` is an input guard and runs before any
provider or tool call; PII and content guards are output guards:

```php
$assistant = agent('public-assistant')
    ->provider('openai')
    ->guard('pii')
    ->guard('contentFilter')
    ->guard('promptInjection')
    ->fallback(fn (Throwable $error): string => 'This request cannot be processed.');
```

Middleware wraps requests and responses for cross-cutting behavior:

```php
use Pagent\Middleware\RateLimitMiddleware;

$assistant
    ->middleware('logging')
    ->middleware(new RateLimitMiddleware(maxRequests: 60));
```

Read the [guards](docs/guards.md), [middleware](docs/middleware.md), and
[events](docs/events.md) guides for custom implementations and lifecycle hooks.
Runnable demonstrations are available for
[guards](examples/06-safety-guards.php) and
[middleware](examples/08-middleware.php).

## Multi-agent workflows

Pipelines pass one agent's response to the next agent:

```php
agent('researcher')
    ->provider('anthropic')
    ->system('Research the topic and return concise notes.');

agent('editor')
    ->provider('openai')
    ->system('Turn the supplied notes into a polished summary.');

$summary = pipeline('article')
    ->agent('researcher')
    ->agent('editor')
    ->run('How PHP fibers support cooperative concurrency');
```

Pagent also supports named workflow steps, transforms, handoffs, and supervised
delegation. See the [orchestration and workflows guide](docs/orchestration-workflows.md)
and the [multi-agent](examples/09-multi-agent.php),
[simple chain](examples/08-simple-chain.php), and
[named pipeline](examples/09-pipeline-steps.php) examples.

## Testing and evaluation

The mock provider makes application tests deterministic and requires no network
access:

```php
$provider = mock([
    'What is the order status?' => 'The order has shipped.',
]);

$agent = agent('test-support')
    ->provider($provider)
    ->build();

$response = $agent->prompt('What is the order status?');

assert($response->content === 'The order has shipped.');
```

The evaluation framework runs datasets against an agent and scores responses with
built-in or custom metrics:

```php
use Pagent\Evaluation\Dataset;
use Pagent\Evaluation\Metrics\KeywordMetric;

$result = evaluate('test-support')
    ->dataset(Dataset::fromArray([
        ['input' => 'What is the order status?', 'expected' => 'shipped'],
    ]))
    ->metric('status', new KeywordMetric(['shipped']))
    ->run();

echo $result->getAverageScore('status');
```

See the [evaluation example](examples/07-evaluation.php), the
[progressive evaluation example](examples/08-evaluation-progressive.php), and the
[evaluation tutorial](examples/evaluation-tutorial.md) for datasets, metrics, and
HTML, Markdown, and JSON reports.

## Usage tracking and observability

Enable per-agent token and cost tracking:

```php
$assistant = agent('metered-assistant')
    ->provider('openai')
    ->trackUsage();

$assistant->prompt('Explain PHP attributes.');

$usage = $assistant->getUsage();
```

For tracing during development, send OpenTelemetry spans to the console:

```php
telemetry_console(verbose: true);

agent('traced-assistant')
    ->provider('anthropic')
    ->telemetry()
    ->prompt('Explain the repository pattern.');
```

Jaeger, Zipkin, and generic OTLP exporters are supported. The
[observability guide](docs/observability.md) documents configuration, captured
attributes, sampling, and production backends. Additional runnable examples cover
[console traces](examples/15-telemetry-console.php),
[Jaeger](examples/16-telemetry-jaeger.php),
[workflow traces](examples/17-telemetry-workflow.php), and
[custom OTLP configuration](examples/19-telemetry-custom.php).

## Model Context Protocol

Pagent can discover tools from MCP servers, adapt them to Pagent tools, and attach
them to an agent. Both local stdio servers and remote HTTP/SSE servers are
supported.

See the [MCP integration guide](docs/mcp-integration.md) for connection lifecycle,
tool discovery, transport configuration, error handling, and security guidance.
The [MCP client example](examples/20-mcp-client.php) demonstrates both transports.

## Examples

The [`examples`](examples/) directory contains runnable programs organized by
feature:

| Area                  | Examples                                                                                                                                                        |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fundamentals          | [Basic chat](examples/01-basic-chat.php), [context](examples/03-context-memory.php), [providers](examples/04-multi-provider.php)                                |
| Tools and safety      | [Tool calling](examples/02-tool-calling.php), [guards](examples/06-safety-guards.php), [middleware](examples/08-middleware.php)                                 |
| Workflows             | [Chains](examples/08-simple-chain.php), [multi-agent](examples/09-multi-agent.php), [pipeline steps](examples/09-pipeline-steps.php)                            |
| Streaming             | [Basic streaming](examples/10-streaming-basic.php), [SSE endpoint](examples/10-streaming-sse-endpoint.php), [SSE client](examples/10-streaming-sse-client.html) |
| Persistence           | [File memory](examples/11-memory-file.php), [SQLite memory](examples/11-memory-sqlite.php), [multiple sessions](examples/11-memory-multi-session.php)           |
| Local models          | [Ollama basics](examples/12-ollama-basic.php), [streaming](examples/13-ollama-streaming.php), [tools](examples/14-ollama-tools.php)                             |
| Evaluation            | [Evaluation](examples/07-evaluation.php), [progressive evaluation](examples/08-evaluation-progressive.php)                                                      |
| Observability         | [Console](examples/15-telemetry-console.php), [Jaeger](examples/16-telemetry-jaeger.php), [tools](examples/18-telemetry-tools.php)                              |
| External tool servers | [MCP client](examples/20-mcp-client.php)                                                                                                                        |

Run an example from the repository root after installing dependencies:

```bash
php examples/01-basic-chat.php
```

Examples using OpenAI or Anthropic require the corresponding API key. Mock
examples run without credentials. See the [examples index](examples/README.md) for
prerequisites and notes.

## Documentation

### Feature guides

- [Documentation index](docs/README.md)
- [Streaming](docs/streaming.md)
- [Memory and persistence](docs/memory-persistence.md)
- [Guards](docs/guards.md)
- [Middleware](docs/middleware.md)
- [Events](docs/events.md)
- [Orchestration and workflows](docs/orchestration-workflows.md)
- [Observability](docs/observability.md)
- [MCP integration](docs/mcp-integration.md)
- [Ollama integration](docs/ollama-integration.md)

### Framework integration

- [Vanilla PHP](docs/vanilla-php.md)
- [Laravel](docs/laravel-integration.md)
- [Symfony](docs/symfony-integration.md)
- [Slim](docs/slim-integration.md)

For a longer, structured introduction, read the
[complete Pagent guide](guide/complete.md) or choose a learning path in the
[guide index](guide/README.md).

## Development

Install dependencies and run the standard checks:

```bash
composer install
composer format:check
composer analyse
composer test
```

If [`just`](https://github.com/casey/just) is installed, the repository also
provides shortcuts:

```bash
just setup
just format
just analyse
just test
just coverage
```

`composer test` excludes live-provider and external-service tests. Run live provider
coverage explicitly with credentials in `.env`:

```bash
composer test
composer test:live
composer test:external
composer test:observability
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development workflow and pull
request guidelines. Security issues should be reported according to
[SECURITY.md](SECURITY.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history and notable changes.

## License

Pagent is open-source software licensed under the [MIT license](LICENSE).

## Credits

Created by [Helge Sverre](https://helgesver.re). The fluent API is inspired by
[Pest](https://pestphp.com).
