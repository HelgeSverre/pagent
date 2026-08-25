# Pagent Guide

The [complete guide](complete.md) is the long-form introduction to Pagent. It
covers the framework from initial agent configuration through testing,
observability, optimization, and deployment.

The files in [`parts`](parts/) contain the same material as individual chapters
for readers who need a specific topic.

## Contents

| Part                          | Chapters | Topics                                                                    |
| ----------------------------- | -------- | ------------------------------------------------------------------------- |
| Foundations                   | 1-5, 5B  | Agents, providers, conversations, prompting, responses, and events        |
| Tool integration              | 6-9, 7B  | Closure tools, class tools, built-in tools, validation, and external APIs |
| Real-time interaction         | 10-11    | Streaming responses and interactive interfaces                            |
| Persistence and state         | 12-13    | Memory adapters, sessions, and context management                         |
| Reliability and safety        | 14-15    | Error handling, guards, middleware, and rate limiting                     |
| Multi-agent orchestration     | 16-19    | Pipelines, chains, handoffs, delegation, and agent systems                |
| Quality assurance             | 20-21    | Evaluation datasets, metrics, reports, and testing                        |
| Observability                 | 22-23    | Events, tracing, usage, cost, and debugging                               |
| Integration and extensibility | 24-25    | Application integration and extension points                              |
| Production                    | 26-28    | Performance, deployment, and architecture                                 |

## Suggested reading paths

### First application

Read Chapters 1-3 for agent fundamentals, Chapter 6 for tool calling, Chapter 12
for persistence, and Chapters 20-21 for testing and evaluation.

### Production preparation

Read Chapters 1-7, 14-15, 20-23, and 26-28. Use the focused
[feature guides](../docs/README.md) alongside the relevant chapters for
implementation details.

### Multi-agent systems

Review Chapters 1-6, then focus on Chapters 16-19. The
[orchestration guide](../docs/orchestration-workflows.md) provides additional
runnable patterns.

### Reference use

Use the [table of contents](complete.md#table-of-contents) to navigate directly to
a chapter, or open the corresponding file under [`parts`](parts/).

## Other documentation

- [Project overview and quick start](../README.md)
- [Feature and integration guides](../docs/README.md)
- [Runnable examples](../examples/README.md)
- [Contributing guide](../CONTRIBUTING.md)

Corrections and focused improvements are welcome through the repository's standard
pull request process.
