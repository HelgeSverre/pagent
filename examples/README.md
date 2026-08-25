# Pagent Examples

This directory contains runnable examples for the main Pagent APIs. Run commands
from the repository root so relative paths and Composer autoloading resolve
correctly.

## Setup

```bash
composer install
cp .env.example .env
```

Add `OPENAI_API_KEY` or `ANTHROPIC_API_KEY` to `.env` for examples that call
hosted providers. Ollama examples require a local Ollama server. Mock-provider
examples need no credentials or network access.

## Output convention

Console examples use a compact, consistent format:

- `[Section]` identifies the current scenario.
- `Input:` and `Output:` identify prompts and model responses.
- Domain labels such as `User:`, `Support:`, or `Provider:` are used when
  they communicate useful context.
- `Status:`, `Warning:`, `Blocked:`, and `Skipped:` describe execution
  state.
- `Done.` marks normal completion.

Examples avoid decorative output so logs remain readable in terminals and CI.

## Example catalog

| File                                                           | Demonstrates                                                      | External requirement               |
| -------------------------------------------------------------- | ----------------------------------------------------------------- | ---------------------------------- |
| [01-basic-chat.php](01-basic-chat.php)                         | Basic prompts, conversation context, providers, and mocks         | OpenAI; Anthropic section optional |
| [02-tool-calling.php](02-tool-calling.php)                     | Closure tools, schema inference, and multiple tool calls          | OpenAI; Anthropic section optional |
| [03-context-memory.php](03-context-memory.php)                 | In-process context and message history                            | OpenAI                             |
| [04-multi-provider.php](04-multi-provider.php)                 | Provider comparison and runtime provider selection                | OpenAI; Anthropic optional         |
| [05-complete-demo.php](05-complete-demo.php)                   | Agents, tools, multi-step tasks, and inspection                   | OpenAI and Anthropic               |
| [06-safety-guards.php](06-safety-guards.php)                   | Built-in guards, custom guards, and fallbacks                     | OpenAI                             |
| [07-evaluation.php](07-evaluation.php)                         | Datasets, metrics, and report generation                          | OpenAI                             |
| [08-evaluation-progressive.php](08-evaluation-progressive.php) | Progressive evaluation, prompt comparisons, and regression checks | OpenAI                             |
| [08-middleware.php](08-middleware.php)                         | Metrics, rate limiting, logging, and custom middleware            | OpenAI                             |
| [08-simple-chain.php](08-simple-chain.php)                     | Named workflow steps and transforms                               | None                               |
| [09-multi-agent.php](09-multi-agent.php)                       | Pipelines, handoffs, delegation, and error recovery               | OpenAI                             |
| [09-pipeline-steps.php](09-pipeline-steps.php)                 | Step results, transforms, validation, and metadata                | None                               |
| [10-streaming-basic.php](10-streaming-basic.php)               | Callback and iterable streaming                                   | Anthropic                          |
| [10-streaming-sse-endpoint.php](10-streaming-sse-endpoint.php) | An SSE endpoint for streamed responses                            | Anthropic                          |
| [10-streaming-sse-client.html](10-streaming-sse-client.html)   | Browser client for the SSE endpoint                               | Local SSE endpoint                 |
| [11-memory-file.php](11-memory-file.php)                       | File persistence and context pruning                              | None                               |
| [11-memory-sqlite.php](11-memory-sqlite.php)                   | SQLite persistence across agent instances                         | None                               |
| [11-memory-multi-session.php](11-memory-multi-session.php)     | Session isolation and restoration                                 | None                               |
| [12-ollama-basic.php](12-ollama-basic.php)                     | Local prompts, context, and configuration                         | Ollama                             |
| [13-ollama-streaming.php](13-ollama-streaming.php)             | Ollama NDJSON streaming                                           | Ollama                             |
| [14-ollama-tools.php](14-ollama-tools.php)                     | Tool calling with local models                                    | Ollama                             |
| [15-telemetry-console.php](15-telemetry-console.php)           | Console spans and verbosity                                       | Anthropic                          |
| [16-telemetry-jaeger.php](16-telemetry-jaeger.php)             | OTLP traces exported to Jaeger                                    | Anthropic and Jaeger               |
| [17-telemetry-workflow.php](17-telemetry-workflow.php)         | Trace relationships across workflows                              | Anthropic                          |
| [18-telemetry-tools.php](18-telemetry-tools.php)               | Tool span attributes, timing, and errors                          | Anthropic                          |
| [19-telemetry-custom.php](19-telemetry-custom.php)             | OTLP, Zipkin, headers, sampling, and environments                 | Anthropic; collector varies        |
| [19-telemetry-event-bridge.php](19-telemetry-event-bridge.php) | Automatic spans from lifecycle events                             | None                               |
| [20-mcp-client.php](20-mcp-client.php)                         | MCP transports, discovery, adapters, and errors                   | MCP server for live calls          |

## Running an example

```bash
php examples/01-basic-chat.php
```

Run the deterministic workflow examples without credentials:

```bash
php examples/08-simple-chain.php
php examples/09-pipeline-steps.php
```

For API integration tests rather than demonstrations, see
[`tests/README.md`](../tests/README.md).

## Related documentation

- [Project README](../README.md)
- [Feature guides](../docs/README.md)
- [Evaluation tutorial](evaluation-tutorial.md)
- [Complete framework guide](../guide/complete.md)
