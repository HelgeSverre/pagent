# Pagent Features Tracking

**Last Updated:** 2025-10-30

This document tracks all features mentioned in documentation, proposals, and the codebase - showing what's implemented, what's planned, and what's just ideas.

## Executive Summary

### Current Status

- **Core Framework**: ✅ Complete (Agent, Provider abstraction, Tool system)
- **Multi-Agent Orchestration**: ✅ Basic patterns implemented (Pipeline x2, Handoff, Delegation, Chain)
- **Evaluation System**: ✅ Complete (Dataset, Metrics, Reports)
- **Memory & Persistence**: ✅ Complete (SQLite, File, Null adapters)
- **Streaming**: ✅ Complete (SSE for Anthropic/OpenAI, NDJSON for Ollama)
- **Safety Guards**: ✅ Complete (PII, Content Filter, Prompt Injection)
- **Advanced Workflows**: ⚠️ Partially implemented (Missing: Router, Swarm, Graph, Parallel execution)

### Statistics

- **Implemented**: 70 features (+MCP Client + Events/Hooks + Observability completed)
- **Partially Implemented**: 8 features
- **Planned/Proposed**: 11 features
- **Future Ideas**: 13 features (+1 Toolkit pattern)

---

## Feature Status Legend

| Status          | Symbol | Meaning                                          |
| --------------- | ------ | ------------------------------------------------ |
| **Implemented** | ✅     | Feature is fully implemented and tested          |
| **Partial**     | ⚠️     | Feature exists but incomplete or has limitations |
| **Planned**     | 📋     | In roadmap, commitment to implement              |
| **Proposed**    | 💡     | Proposal exists, not yet committed               |
| **Idea**        | 🔮     | Concept mentioned, no concrete plan              |
| **Not Planned** | ❌     | Explicitly decided against                       |

---

## Detailed Features Inventory

### 1. Core Agent System

| Feature             | Status | Implementation         | Source | Notes                           |
| ------------------- | ------ | ---------------------- | ------ | ------------------------------- |
| Agent Registry      | ✅     | `src/Registry.php`     | Core   | Global agent configuration      |
| Fluent API          | ✅     | `src/Agent.php`        | Core   | Pest-inspired builder pattern   |
| System Prompts      | ✅     | `Agent::system()`      | Core   |                                 |
| Temperature Control | ✅     | `Agent::temperature()` | Core   |                                 |
| Max Tokens          | ✅     | `Agent::maxTokens()`   | Core   |                                 |
| Message History     | ✅     | `Agent::messages`      | Core   | Automatic conversation tracking |
| Context Window      | ✅     | Memory system          | Core   | Token-based context limits      |
| Model Selection     | ✅     | `Agent::model()`       | Core   | Per-agent model override        |

### 2. Provider System

| Feature                    | Status | Implementation                         | Source | Notes                              |
| -------------------------- | ------ | -------------------------------------- | ------ | ---------------------------------- |
| Anthropic Claude           | ✅     | `src/Providers/Anthropic.php`          | Core   | Full support                       |
| OpenAI GPT                 | ✅     | `src/Providers/OpenAI.php`             | Core   | Full support                       |
| Ollama (Local LLMs)        | ✅     | `src/Providers/Ollama.php`             | Core   | Full support with NDJSON streaming |
| Mock Provider              | ✅     | `src/Providers/Mock.php`               | Core   | For testing                        |
| Provider Abstraction       | ✅     | `src/Contracts/Provider.php`           | Core   | Clean interface                    |
| Multi-Provider Support     | ✅     | Agent can switch providers             | Core   |                                    |
| Provider-Specific Features | ✅     | Leaky abstraction by design            | Core   | JSON mode, etc.                    |
| Response Normalization     | ✅     | Each provider normalizes               | Core   | Consistent Response object         |
| Tool Call Normalization    | ✅     | `Agent::normalizeToolCallArguments()`  | Core   | Handles provider differences       |
| Ollama Streaming (NDJSON)  | ✅     | `src/Streaming/OllamaStreamParser.php` | Core   | Newline-delimited JSON             |
| Ollama Tool Calling        | ✅     | Uses OpenAI schema format              | Core   | Compatible with qwen3, llama3.1    |

### 3. Tool System

| Feature                     | Status | Implementation                       | Source     | Notes                                                  |
| --------------------------- | ------ | ------------------------------------ | ---------- | ------------------------------------------------------ |
| Closure-Based Tools         | ✅     | `src/Tool/Tool.php`                  | Core       | `Tool::fromClosure()` (guide/complete.md Chapter 7B)   |
| Class-Based Tools           | ✅     | `src/Tools/Tool.php`                 | Core       | Abstract base class (guide/complete.md Chapter 7B)     |
| Automatic Schema Generation | ✅     | Reflection-based                     | Core       | From PHP type hints                                    |
| Anthropic Schema Format     | ✅     | `ToolInterface::toAnthropicSchema()` | Core       |                                                        |
| OpenAI Schema Format        | ✅     | `ToolInterface::toOpenAISchema()`    | Core       |                                                        |
| Automatic Tool Execution    | ✅     | `Agent::handleToolCalls()`           | Core       | Recursive calling                                      |
| Tool Result Formatting      | ✅     | Provider-specific formats            | Core       |                                                        |
| Built-in FileRead           | ✅     | `src/Tools/FileRead.php`             | Tools      | With safety checks                                     |
| Built-in FileWrite          | ✅     | `src/Tools/FileWrite.php`            | Tools      | With safety checks                                     |
| Built-in Bash               | ✅     | `src/Tools/Bash.php`                 | Tools      | Command execution                                      |
| Built-in WebFetch           | ✅     | `src/Tools/WebFetch.php`             | Tools      | With SSRF protection                                   |
| Built-in Grep               | ✅     | `src/Tools/Grep.php`                 | Tools      | Content search                                         |
| Built-in Glob               | ✅     | `src/Tools/Glob.php`                 | Tools      | File pattern matching                                  |
| Built-in PdfReader          | ✅     | `src/Tools/PdfReader.php`            | Tools      | PDF extraction                                         |
| Built-in DataExtract        | ✅     | `src/Tools/DataExtract.php`          | Tools      | Structured extraction                                  |
| Built-in SearchTool         | ✅     | `src/Tools/SearchTool.php`           | Tools      | Full-text search with TNTSearch (BM25, fuzzy matching) |
| Parameter Validation        | ✅     | `src/Tool/ToolValidator.php`         | Core       | JSON schema validation                                 |
| Tool Argument Parser        | ✅     | `src/Tool/ToolArgument.php`          | Core       | Type inference                                         |
| Bulk Tool Addition          | ✅     | `Agent::tools()`                     | Core       | Add multiple tools at once                             |
| TOON Integration            | 📋     | Not implemented                      | ROADMAP.md | Attribute-based tool definition (helgesverre/toon-php) |
| Tool Registry/Toolkit       | 💡     | Not implemented                      | Future     | Reusable tool sets (MathToolkit, FileToolkit)          |

### 4. Multi-Agent Orchestration (Implemented)

| Feature                | Status | Implementation                     | Source                | Notes                     |
| ---------------------- | ------ | ---------------------------------- | --------------------- | ------------------------- |
| Pipeline (Simple)      | ✅     | `src/Orchestration/Pipeline.php`   | HOW_IT_WORKS.md       | Sequential agent chain    |
| Pipeline (Named Steps) | ✅     | `src/Workflow/Pipeline.php`        | WORKFLOWS_PROPOSAL.md | With step access          |
| Chain                  | ✅     | `src/Workflow/Chain.php`           | WORKFLOWS_PROPOSAL.md | Simplest pattern          |
| Handoff                | ✅     | `src/Orchestration/Handoff.php`    | HOW_IT_WORKS.md       | Conversation transfer     |
| Delegation             | ✅     | `src/Orchestration/Delegation.php` | HOW_IT_WORKS.md       | Manager-worker pattern    |
| Transform Steps        | ✅     | `Pipeline::transform()`            | WORKFLOWS_PROPOSAL.md | Pure function steps       |
| Error Recovery         | ✅     | `Pipeline::onError()`              | HOW_IT_WORKS.md       | Graceful failure handling |
| Pipeline Results       | ✅     | `src/Workflow/WorkflowResult.php`  | WORKFLOWS_PROPOSAL.md | Structured output         |
| Step Metadata          | ✅     | `src/Workflow/StepMetadata.php`    | WORKFLOWS_PROPOSAL.md | Duration, tokens          |
| Named Step Access      | ✅     | `$result->step('name')`            | WORKFLOWS_PROPOSAL.md | Intermediate results      |

### 5. Multi-Agent Orchestration (Planned/Proposed)

| Feature              | Status | Implementation        | Source                | Notes                         |
| -------------------- | ------ | --------------------- | --------------------- | ----------------------------- |
| Conditional Router   | 📋     | Not implemented       | ROADMAP.md            | Route based on classification |
| Swarm Intelligence   | 📋     | Not implemented       | ROADMAP.md            | Dynamic agent collaboration   |
| Parallel Execution   | 💡     | Not implemented       | WORKFLOWS_PROPOSAL.md | Concurrent agent runs         |
| Branch/Conditional   | ⚠️     | Partial via transform | WORKFLOWS_PROPOSAL.md | Not true branching yet        |
| Graph Workflows      | 💡     | Not implemented       | WORKFLOWS_PROPOSAL.md | DAG-based orchestration       |
| Graph Visualizer     | 💡     | Not implemented       | WORKFLOWS_PROPOSAL.md | Mermaid/HTML export           |
| Workflow Builder API | 💡     | Not implemented       | WORKFLOWS_PROPOSAL.md | Fluent graph builder          |
| Parallel.run()       | 💡     | Not implemented       | WORKFLOWS_PROPOSAL.md | Sequential fallback ready     |
| Parallel.runAsync()  | 🔮     | Not implemented       | WORKFLOWS_PROPOSAL.md | Requires amphp/ReactPHP       |
| Cycle Detection      | 🔮     | Not implemented       | WORKFLOWS_PROPOSAL.md | For graph workflows           |
| State Management     | 🔮     | Not implemented       | WORKFLOWS_PROPOSAL.md | Stateful workflows            |

### 6. Evaluation System

| Feature              | Status | Implementation                                   | Source          | Notes                        |
| -------------------- | ------ | ------------------------------------------------ | --------------- | ---------------------------- |
| Dataset Creation     | ✅     | `src/Evaluation/Dataset.php`                     | HOW_IT_WORKS.md | From JSON/array              |
| Evaluator            | ✅     | `src/Evaluation/Evaluator.php`                   | HOW_IT_WORKS.md | Run evaluations              |
| Evaluation Results   | ✅     | `src/Evaluation/EvaluationResult.php`            | HOW_IT_WORKS.md | Structured results           |
| Report Generation    | ✅     | `src/Evaluation/Report.php`                      | HOW_IT_WORKS.md | HTML/JSON/Markdown           |
| Keyword Metric       | ✅     | `src/Evaluation/Metrics/KeywordMetric.php`       | HOW_IT_WORKS.md | Keyword matching             |
| Length Metric        | ✅     | `src/Evaluation/Metrics/LengthMetric.php`        | HOW_IT_WORKS.md | Length validation            |
| Similarity Metric    | ✅     | `src/Evaluation/Metrics/SimilarityMetric.php`    | HOW_IT_WORKS.md | Text similarity              |
| JsonValid Metric     | ✅     | `src/Evaluation/Metrics/JsonValidMetric.php`     | Core            | JSON parsing validation      |
| JsonSchema Metric    | ✅     | `src/Evaluation/Metrics/JsonSchemaMetric.php`    | Core            | Schema validation (swaggest) |
| RegexMatch Metric    | ✅     | `src/Evaluation/Metrics/RegexMatchMetric.php`    | Core            | Generic pattern matching     |
| HasCodeBlock Metric  | ✅     | `src/Evaluation/Metrics/HasCodeBlockMetric.php`  | Core            | Code block detection         |
| MarkdownValid Metric | ✅     | `src/Evaluation/Metrics/MarkdownValidMetric.php` | Core            | Markdown structure           |
| UrlValidity Metric   | ✅     | `src/Evaluation/Metrics/UrlValidityMetric.php`   | Core            | URL format validation        |
| Custom Metrics       | ✅     | Via closures                                     | HOW_IT_WORKS.md | User-defined scoring         |
| Metric Aggregation   | ✅     | In EvaluationResult                              | HOW_IT_WORKS.md | Average, min, max            |
| A/B Testing          | ✅     | Via dataset + evaluate                           | HOW_IT_WORKS.md | Compare configurations       |

### 7. Memory & Persistence

| Feature               | Status | Implementation                          | Source                     | Notes                   |
| --------------------- | ------ | --------------------------------------- | -------------------------- | ----------------------- |
| SQLite Adapter        | ✅     | `src/Memory/Adapters/SqliteAdapter.php` | docs/memory-persistence.md | Production-ready        |
| File Adapter          | ✅     | `src/Memory/Adapters/FileAdapter.php`   | docs/memory-persistence.md | JSON-based              |
| Null Adapter          | ✅     | `src/Memory/Adapters/NullAdapter.php`   | docs/memory-persistence.md | For testing             |
| Context Manager       | ✅     | `src/Memory/ContextManager.php`         | docs/memory-persistence.md | Token window management |
| Session Management    | ✅     | `Agent::sessionId()`                    | docs/memory-persistence.md | Multi-user support      |
| Context Window Limits | ✅     | `Agent::contextWindow()`                | docs/memory-persistence.md | Token-based pruning     |
| Message Persistence   | ✅     | Automatic save/load                     | docs/memory-persistence.md | Transparent             |
| Custom Adapters       | ✅     | `AdapterInterface`                      | docs/memory-persistence.md | Extensible              |

### 8. Streaming

| Feature             | Status | Implementation                     | Source            | Notes                 |
| ------------------- | ------ | ---------------------------------- | ----------------- | --------------------- |
| SSE Streaming       | ✅     | `Agent::streamTo()`                | docs/streaming.md | Real-time output      |
| Anthropic Streaming | ✅     | `Providers/Anthropic.php`          | docs/streaming.md | Native support        |
| OpenAI Streaming    | ✅     | `Providers/OpenAI.php`             | docs/streaming.md | Native support        |
| Stream Chunks       | ✅     | `src/Streaming/StreamChunk.php`    | docs/streaming.md | Typed chunks          |
| Stream Response     | ✅     | `src/Streaming/StreamResponse.php` | docs/streaming.md | Wrapper               |
| Chunk Types         | ✅     | Text, ToolUse, ContentBlock        | docs/streaming.md | Type detection        |
| Stream Callbacks    | ✅     | Via closure                        | docs/streaming.md | User-defined handling |

### 9. Safety Guards

| Feature                | Status | Implementation                        | Source    | Notes                   |
| ---------------------- | ------ | ------------------------------------- | --------- | ----------------------- |
| PII Detection          | ✅     | `src/Guards/PIIGuard.php`             | README.md | Email, phone, SSN, etc. |
| Content Filtering      | ✅     | `src/Guards/ContentFilterGuard.php`   | README.md | Keyword blocklist       |
| Prompt Injection Guard | ✅     | `src/Guards/PromptInjectionGuard.php` | README.md | Pattern detection       |
| Guard Middleware       | ✅     | `Agent::guard()`                      | README.md | Pre-prompt filtering    |
| Custom Guards          | ✅     | `GuardInterface`                      | README.md | Extensible              |

### 10. Framework Integrations

| Feature            | Status | Implementation                | Source        | Notes                    |
| ------------------ | ------ | ----------------------------- | ------------- | ------------------------ |
| Vanilla PHP        | ✅     | `docs/vanilla-php.md`         | Documentation | No dependencies          |
| Slim Framework     | ✅     | `docs/slim-integration.md`    | Documentation | Complete guide           |
| Laravel            | ✅     | `docs/laravel-integration.md` | Documentation | Service provider pattern |
| Symfony            | ✅     | `docs/symfony-integration.md` | Documentation | Bundle integration       |
| Centralized Config | ✅     | Via agent registry            | Documentation | Global agents.php        |

### 11. Testing & Quality

| Feature           | Status | Implementation       | Source     | Notes             |
| ----------------- | ------ | -------------------- | ---------- | ----------------- |
| Unit Tests        | ✅     | `tests/Unit/`        | Repository | 265+ tests        |
| Integration Tests | ✅     | `tests/Integration/` | Repository | Real API tests    |
| PHPStan Level 9   | ✅     | Configuration        | Repository | Strict types      |
| Pest Framework    | ✅     | `phpunit.xml`        | Repository | Test runner       |
| Mock Provider     | ✅     | For testing          | Repository | No API calls      |
| Test Coverage     | ⚠️     | Partial              | Repository | Needs improvement |

### 12. Observability & Monitoring

| Feature                       | Status | Implementation                                          | Source | Notes                                                         |
| ----------------------------- | ------ | ------------------------------------------------------- | ------ | ------------------------------------------------------------- |
| TelemetryManager              | ✅     | `src/Observability/TelemetryManager.php`                | v0.7.0 | OpenTelemetry SDK wrapper, singleton pattern                  |
| OpenTelemetry SDK Integration | ✅     | `open-telemetry/sdk` dependency                         | v0.7.0 | Industry-standard distributed tracing                         |
| Span Creation                 | ✅     | `startAgentSpan()`, `startLLMSpan()`, `startToolSpan()` | v0.7.0 | Helper methods for all operation types                        |
| Semantic Conventions          | ✅     | GenAI attributes (`gen_ai.*`)                           | v0.7.0 | OpenTelemetry semantic conventions for LLMs                   |
| Console Exporter              | ✅     | `src/Observability/Exporters/ConsoleExporter.php`       | v0.7.0 | Debug output to console                                       |
| OTLP Exporter                 | ✅     | `src/Observability/Exporters/OTLPExporter.php`          | v0.7.0 | Generic OTLP protocol support                                 |
| Jaeger Exporter               | ✅     | `src/Observability/Exporters/JaegerExporter.php`        | v0.7.0 | Jaeger-specific integration                                   |
| Zipkin Exporter               | ✅     | `src/Observability/Exporters/ZipkinExporter.php`        | v0.7.0 | Zipkin-specific integration                                   |
| InMemory Exporter             | ✅     | `src/Observability/Exporters/InMemoryExporter.php`      | v0.7.0 | Test-only exporter with span inspection                       |
| Agent Telemetry Integration   | ✅     | `Agent::telemetry(true)`                                | v0.7.0 | Opt-in per-agent telemetry                                    |
| Events/Hooks System           | ✅     | `src/Events/`                                           | v0.7.0 | Event-driven architecture for observability                   |
| Event Base Classes            | ✅     | Event, EventListener, EventDispatcher                   | v0.7.0 | Typed event objects with propagation control                  |
| Lifecycle Events              | ✅     | 23 typed event classes                                  | v0.7.0 | Agent, LLM, Tool, Guard, Memory, Stream, MCP                  |
| Hybrid Listener Pattern       | ✅     | Interface + Closure support                             | v0.7.0 | Both class and closure listeners                              |
| EventDispatcher Priority      | ✅     | Priority-based listener execution                       | v0.7.0 | Control listener order                                        |
| Global Events                 | ✅     | EventManager singleton                                  | v0.7.0 | Cross-agent event listening (guide/complete.md Chapter 5B)    |
| Per-Agent Events              | ✅     | `Agent::on()`, `once()`, `off()`                        | v0.7.0 | Instance-level event listeners (guide/complete.md Chapter 5B) |
| TelemetryEventBridge          | ✅     | `src/Observability/TelemetryEventBridge.php`            | v0.7.0 | Automatic span creation from events                           |
| Event-Driven Telemetry        | ✅     | Fire events → Bridge creates spans                      | v0.7.0 | Clean separation of concerns                                  |
| Cost & Token Tracking         | 📋     | `UsageTracker` singleton                                | v0.7.0 | Track usage, calculate costs, budget enforcement              |
| Provider Pricing              | 📋     | `ProviderPricing` with Jan 2025 pricing                 | v0.7.0 | Up-to-date pricing for Anthropic, OpenAI, etc.                |
| Budget Enforcement            | 📋     | Soft warnings, hard limits                              | v0.7.0 | Agent, session, and global budget tracking                    |
| Usage Analytics               | 📋     | By agent, session, provider                             | v0.7.0 | Queryable usage statistics                                    |
| Usage Export                  | 📋     | JSON, CSV, SQLite                                       | v0.7.0 | Export usage data for analysis                                |

### 13. MCP (Model Context Protocol) Integration

| Feature            | Status | Implementation                                  | Source | Notes                                     |
| ------------------ | ------ | ----------------------------------------------- | ------ | ----------------------------------------- |
| MCP Client         | ✅     | `src/Mcp/McpClient.php`                         | v0.7.0 | Full MCP protocol v2024-11-05             |
| Stdio Transport    | ✅     | `src/Mcp/Transports/StdioTransport.php`         | v0.7.0 | Process-based MCP servers                 |
| HTTP SSE Transport | ✅     | `src/Mcp/Transports/HttpSseTransport.php`       | v0.7.0 | HTTP Server-Sent Events transport         |
| Tool Discovery     | ✅     | `McpClient::discoverTools()`                    | v0.7.0 | Automatic tool discovery from MCP servers |
| Tool Execution     | ✅     | `McpClient::callTool()`                         | v0.7.0 | Execute MCP tools via JSON-RPC            |
| Resource Discovery | ✅     | `McpClient::discoverResources()`                | v0.7.0 | Discover available resources              |
| Resource Reading   | ✅     | `McpClient::readResource()`                     | v0.7.0 | Read resource content                     |
| Prompt Discovery   | ✅     | `McpClient::discoverPrompts()`                  | v0.7.0 | Discover available prompts                |
| Prompt Retrieval   | ✅     | `McpClient::getPrompt()`                        | v0.7.0 | Get prompt with arguments                 |
| Tool Adapter       | ✅     | `src/Mcp/McpToolAdapter.php`                    | v0.7.0 | Wrap MCP tools as Pagent tools            |
| Connection Events  | ✅     | Initiating, Established, Failed, Disconnecting  | v0.7.0 | 5 connection lifecycle events             |
| Tool Events        | ✅     | Discovering, Discovered, Calling, Called, Error | v0.7.0 | 5 tool operation events                   |
| Event Listeners    | ✅     | `McpClient::on()`, `once()`, `off()`            | v0.7.0 | Per-client event listeners                |
| Error Handling     | ✅     | McpConnectionException, McpProtocolException    | v0.7.0 | Comprehensive error types                 |
| Timeout Handling   | ✅     | McpTimeoutException with configurable timeouts  | v0.7.0 | Prevent hanging operations                |

### 14. Developer Experience

| Feature              | Status | Implementation       | Source     | Notes                         |
| -------------------- | ------ | -------------------- | ---------- | ----------------------------- |
| Fluent API           | ✅     | Throughout           | Core       | Chainable methods             |
| Helper Functions     | ✅     | `src/functions.php`  | Core       | `agent()`, `pipeline()`, etc. |
| Type Safety          | ✅     | PHP 8.3+             | Core       | Strict types                  |
| Clear Error Messages | ✅     | Custom exceptions    | Core       | Helpful debugging             |
| Comprehensive Docs   | ✅     | `docs/`, `guide/`    | Repository | 5 learning styles             |
| Examples             | ✅     | `examples/`          | Repository | Working code                  |
| Code of Conduct      | ✅     | `CODE_OF_CONDUCT.md` | Repository |                               |
| Contributing Guide   | ✅     | `CONTRIBUTING.md`    | Repository |                               |
| Security Policy      | ✅     | `SECURITY.md`        | Repository |                               |
| Changelog            | ✅     | `CHANGELOG.md`       | Repository | Version history               |

---

## Features from WORKFLOWS_PROPOSAL.md Not Yet Implemented

### Phase 1: Foundation (2-3 hours) - MOSTLY DONE ✅

- [x] Shared abstractions (WorkflowResult, StepResult, Metadata)
- [x] Chain implementation
- [x] Unit tests
- [x] Examples + docs

### Phase 2: Core Patterns (4-6 hours) - MOSTLY DONE ✅

- [x] Pipeline implementation (both versions exist)
- [x] Transform steps
- [x] Error handling
- [ ] Workflow implementation with branching (partial - no true branch)
- [ ] Conditional routing (match/case patterns)

### Phase 3: Advanced (6-8 hours) - NOT STARTED 📋

- [ ] Graph implementation
- [ ] Parallel execution (sequential fallback)
- [ ] GraphVisualizer (Mermaid export)
- [ ] Async support (optional dependency)
- [ ] Branch/conditional API (`->branch()`, `->branchIf()`)

### Specific Missing APIs from Proposal

| API                            | Status | Proposal Location         | Priority |
| ------------------------------ | ------ | ------------------------- | -------- |
| `Workflow::create()->branch()` | ❌     | WORKFLOWS_PROPOSAL.md:159 | HIGH     |
| `Parallel::run()`              | ❌     | WORKFLOWS_PROPOSAL.md:176 | HIGH     |
| `Graph::create()`              | ❌     | WORKFLOWS_PROPOSAL.md:199 | MEDIUM   |
| `Graph::visualize()`           | ❌     | WORKFLOWS_PROPOSAL.md:970 | LOW      |
| `Pipeline::merge()`            | ❌     | WORKFLOWS_PROPOSAL.md:672 | MEDIUM   |
| `Pipeline::collect()`          | ❌     | WORKFLOWS_PROPOSAL.md:656 | MEDIUM   |
| `Pipeline::parallel()`         | ❌     | WORKFLOWS_PROPOSAL.md:651 | HIGH     |
| `agent()->extends()`           | ❌     | WORKFLOWS_PROPOSAL.md:708 | LOW      |
| `Pipeline::withContext()`      | ❌     | WORKFLOWS_PROPOSAL.md:526 | LOW      |

---

## Features from HOW_IT_WORKS.md Status

### Fully Implemented ✅

- ✅ Evaluation System (all components)
- ✅ Dataset creation and loading
- ✅ Metrics (Keyword, Length, Similarity, Custom)
- ✅ Report generation (HTML, JSON, Markdown)
- ✅ Pipeline (Sequential Processing) - both implementations
- ✅ Agent Handoff (Conversation Transfer)
- ✅ Delegation (Manager-Worker Pattern)
- ✅ Error Recovery in Pipelines

### Planned but Missing 📋

- 📋 Conditional Router (mentioned as planned)
- 📋 Swarm Intelligence (mentioned as planned)
- 📋 True branching in pipelines (transform workaround exists)

---

## Implementation Gaps Summary

### Critical Gaps (Should implement soon)

1. **True Conditional Branching** - Currently using transform workarounds
2. **Parallel Execution** - Even with sequential fallback would be valuable
3. **Router Pattern** - Mentioned in docs but not implemented

### Nice-to-Have Gaps (Future enhancements)

1. **Graph Workflows** - Complex but powerful
2. **Graph Visualization** - Helpful for debugging
3. **Swarm Intelligence** - Innovative but experimental
4. **Async Parallel** - Requires significant PHP infrastructure

### Non-Critical Gaps (Can skip)

1. **Agent inheritance** (`->extends()`) - Composition works fine
2. **Context injection** (`->withContext()`) - Can do manually
3. **Advanced merge patterns** - Current approaches sufficient

---

## Recommendations

### High Priority (Next Release)

1. ✅ Add defensive argument normalization (DONE)
2. ✅ Document class-based tools in README (DONE)
3. ✅ Add class-based tool integration tests (DONE)
4. 📋 Implement true `->branch()` API for Pipeline
5. 📋 Implement `Parallel::run()` with sequential fallback
6. 📋 Create Router pattern implementation

### Medium Priority (Future)

1. Graph workflows
2. Graph visualization (Mermaid export)
3. Pipeline `->merge()` and `->collect()` helpers
4. More comprehensive test coverage

### Low Priority (Nice to have)

1. Agent composition/inheritance
2. Async parallel execution (requires ext-pcntl or amphp)
3. Context injection helpers
4. Advanced workflow features from proposal

---

## Future Enhancements: Tool Registry/Toolkit Pattern

### Concept Overview

A **Toolkit** is a reusable collection of related tools that can be easily added to agents. This pattern would provide:

1. **Semantic Grouping**: Organize tools by domain (file operations, math, web, etc.)
2. **Reusability**: Share common tool configurations across projects
3. **Discoverability**: Make it easier to find and use related tools together

### Example Implementation (User-Space Workaround)

Currently, users can create their own toolkit pattern without framework support:

```php
// Create tool collections
class FileToolkit {
    public static function all(?string $baseDir = null): array {
        return [
            new FileRead($baseDir),
            new FileWrite($baseDir),
            new Glob($baseDir),
            new Grep($baseDir),
        ];
    }
}

class MathToolkit {
    public static function all(): array {
        return [
            Tool::fromClosure('add', 'Add numbers', fn($a, $b) => $a + $b),
            Tool::fromClosure('subtract', 'Subtract', fn($a, $b) => $a - $b),
            Tool::fromClosure('multiply', 'Multiply', fn($a, $b) => $a * $b),
            Tool::fromClosure('divide', 'Divide', fn($a, $b) => $a / $b),
        ];
    }
}

// Usage with the new tools() method
agent('file-assistant')
    ->tools(FileToolkit::all('/project'))
    ->tools(MathToolkit::all())
    ->provider('anthropic');
```

### Potential Framework Support

If this pattern proves valuable, the framework could provide:

1. **Built-in Toolkits**: `FileToolkit`, `WebToolkit`, `DataToolkit`, etc.
2. **Registry Pattern**: Similar to Agent registry for managing toolkits
3. **Helper Function**: `allTools()` to get all built-in tools at once
4. **Discovery API**: Methods to list available toolkits and their tools

### Decision Rationale

**Why Not Implement Now:**

- ✅ Current API already supports the pattern via user-space code
- ✅ No evidence of pain points requiring framework-level solution
- ✅ Users can experiment with patterns before we commit to an API
- ✅ Avoids premature abstraction (YAGNI principle)

**When to Implement:**

- 📋 Community requests for built-in toolkits
- 📋 Multiple projects replicating the same pattern
- 📋 Clear API design emerges from user-space implementations
- 📋 Enough built-in tools to justify grouping (threshold: 15+)

### Related Work

The new `Agent::tools()` method (added 2025-10-29) provides the foundation for this pattern by enabling bulk tool addition. This allows users to experiment with toolkit patterns without framework changes.

---

## Version History

| Version | Date       | Changes                                                                                                |
| ------- | ---------- | ------------------------------------------------------------------------------------------------------ |
| 1.5     | 2025-11-20 | Test Coverage Enhancement: +239 tests, +~478 assertions, 8 new test files (see ROADMAP.md)             |
| 1.4     | 2025-11-20 | Added documentation references: Event System Architecture (Chapter 5B), Tool Architecture (Chapter 7B) |
| 1.3     | 2025-11-19 | Added MCP Client section (15 features), updated Events/Hooks and Observability to completed            |
| 1.2     | 2025-10-29 | Added TOON Integration to roadmap, updated references to ROADMAP.md                                    |
| 1.1     | 2025-10-29 | Added Agent::tools() method, documented Toolkit pattern future enhancement                             |
| 1.0     | 2025-01-29 | Initial feature inventory created                                                                      |

---

## Notes

- Two Pipeline implementations exist: `Orchestration\Pipeline` (simple) and `Workflow\Pipeline` (named steps)
- This is intentional - different use cases
- Most features from HOW_IT_WORKS.md are implemented
- Most features from WORKFLOWS_PROPOSAL.md are still proposals
- The gap between documented features and implemented features is small
- The gap between proposed features and implemented features is larger but expected
