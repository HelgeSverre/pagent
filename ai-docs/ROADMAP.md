# Pagent Roadmap

**Last Updated:** 2025-10-29
**Current Version:** v0.6.0 (Memory & Persistence)
**Status:** Production-ready, actively developed

This roadmap provides a chronological view of Pagent's feature development, organized by version releases.

---

## ✅ v0.1.0-v0.5.0 - Foundation (Completed)

**Status:** Released
**Timeline:** Initial development

### Core Features

- ✅ Fluent API inspired by PestPHP
- ✅ Multi-provider support (Anthropic Claude, OpenAI GPT, Mock)
- ✅ Automatic tool calling with JSON schema generation
- ✅ Safety guards (PII detection, content filtering, prompt injection)
- ✅ Evaluation framework (datasets, metrics, HTML/JSON/MD reports)
- ✅ Middleware pipeline (logging, rate limiting, metrics)
- ✅ Conversation history and context management
- ✅ 8 Built-in tools (FileRead, FileWrite, Glob, Grep, WebFetch, Bash, PdfReader, DataExtract)
- ✅ Tool validation with type checking
- ✅ PHPStan level 9 compliance
- ✅ PHP 8.3 full type safety

### Metrics

- 📊 229 tests passing (508 assertions)
- 🎯 99%+ pass rate
- 📚 11 working examples
- 🛠️ 8 production-ready tools
- 📖 5 documentation styles

---

## ✅ v0.5.1 - Workflow Patterns (Completed)

**Status:** Released
**Effort:** 4-6 hours
**Timeline:** Week 1-2

### Features

- ✅ **Chain Workflow** - Simple sequential agent execution
- ✅ **Pipeline Workflow** - Named steps with intermediate results
- ✅ **Transform Steps** - Data manipulation between agent calls
- ✅ **WorkflowResult/StepResult** - Shared result classes
- ✅ 2 workflow examples

### Implementation

- `src/Workflow/Chain.php`
- `src/Workflow/Pipeline.php`
- `src/Workflow/WorkflowResult.php`
- `src/Workflow/StepResult.php`
- `src/Workflow/Metadata.php`

---

## ✅ v0.6.0 - Memory & Persistence (Completed)

**Status:** Released
**Effort:** 8-10 hours (actual)
**Timeline:** Month 1

### Features

- ✅ **Streaming Support (SSE)** - Real-time response streaming
- ✅ **Memory Persistence** - Long-running conversations
  - SQLite adapter (production-ready)
  - File adapter (development/single-user)
  - Null adapter (no persistence)
- ✅ **Context Window Management** - Token counting and pruning
- ✅ **Session Management** - Multi-user isolation
- ✅ **Bulk Tool Addition** - `Agent::tools()` method

### Metrics

- ✅ 265+ tests passing (all green)
- ✅ 21 streaming tests
- ✅ 77 memory/persistence tests
- ✅ 3 memory examples
- ✅ 820-line memory-persistence.md guide

### Implementation

- `src/Memory/` - Memory interfaces and adapters
- `src/Streaming/` - Streaming classes and parsers
- `docs/memory-persistence.md`
- `docs/streaming.md`
- `examples/11-memory-multi-session.php`

---

## 🚧 v0.7.0 - Observability & Usage Tracking (In Planning)

**Status:** Planning
**Effort:** 49-66 hours (adjusted after cohesion review)
**Timeline:** Month 2-3
**Plan:** See `ai-docs/plans/` and **[API Cohesion Review](plans/api-cohesion-review.md)**

> **⚠️ IMPLEMENTATION NOTE:** After API cohesion review, Events/Hooks System has been **moved from v0.8.0 → v0.7.0** to serve as the foundation for observability. This ensures clean architecture from day one.

### Recommended Implementation Order

**Phase 1: Foundation (Week 1-2)**

1. HTTP Client Migration (4-6 hrs) - Technical debt
2. ✅ **Events/Hooks System (6-8 hrs)** - **FOUNDATION** ← COMPLETED 2025-11-18
3. TOON Integration (3-4 hrs) - Independent
4. Attribute-Based Tools (6-8 hrs) - Builds on TOON

**Phase 2: Observability (Week 3-4)**

5. ✅ **OpenTelemetry Exporters (3-4 hrs)** - Uses events ← COMPLETED 2025-11-18
6. Cost & Token Tracking (18-24 hrs) - Integrates with OpenTelemetry
7. MCP Server Support (6-8 hrs) - Independent

**Total:** ~49-66 hours

### Features

#### 1. Events/Hooks System (**COMPLETED** ✅)

**Effort:** 6-8 hours | **Plan:** [`ai-docs/plans/events-hooks-system-plan.md`](plans/events-hooks-system-plan.md)
**Status:** COMPLETED 2025-11-18

- [x] Event infrastructure (Event, EventListener, EventDispatcher)
- [x] EventManager singleton for global events
- [x] 19 event classes (Agent, LLM, Tool, Guard, Memory, Stream) - 17 integrated
- [x] Per-agent and global event listeners
- [x] Priority system and propagation control
- [x] 36 passing tests (117 assertions)
- [x] **TelemetryEventBridge for automatic span creation** ✅ **COMPLETED 2025-11-18**
- [ ] StreamChunkEvent implementation (Optional - requires StreamResponse refactor)

**API Preview:**

```php
// Global event listener
EventManager::instance()->on('after_prompt', function (AfterPromptEvent $e) {
    Log::info("Prompt completed in {$e->duration}s");
});

// Per-agent listener
agent('bot')
    ->on('tool_executed', fn($e) => logToolMetrics($e))
    ->prompt('Calculate 5 + 3');

// TelemetryEventBridge creates spans automatically from events
EventManager::instance()->listen(new TelemetryEventBridge());
```

**Why First:** Events system provides foundation for observability, replacing manual span creation with event-driven architecture.

#### 2. OpenTelemetry Observability (**COMPLETED** ✅)

**Effort:** 3-4 hours (reduced - only exporters) | **Plan:** [`ai-docs/plans/opentelemetry-observability-plan.md`](plans/opentelemetry-observability-plan.md)
**Status:** COMPLETED 2025-11-18

- [x] Event system foundation (implemented in Events/Hooks)
- [x] **TelemetryEventBridge for automatic span creation from events** ✅
  - [x] LLM operations (request/response)
  - [x] Tool execution (before/after/error)
  - [x] Guard checks (checking/passed/violated/fallback)
  - [x] Memory operations (loading/loaded)
  - [x] Stream operations (started/completed)
  - [x] 20 passing tests (116 assertions)
- [x] OpenTelemetry exporters:
  - [x] ConsoleExporter (development) ✅
  - [x] OTLPExporter (generic) ✅
  - [x] JaegerExporter ✅
  - [x] ZipkinExporter ✅
- [ ] Future: Langfuse, Langsmith, Phoenix adapters (v0.9.0+)

**API Preview:**

```php
// Initialize telemetry
TelemetryManager::instance()->initialize([
    'exporter' => 'jaeger',
    'jaeger' => ['endpoint' => 'http://localhost:14268/api/traces'],
]);

// Register event bridge (spans created automatically from events)
EventManager::instance()->listen(new TelemetryEventBridge());

// All operations automatically traced via events
agent('bot')->prompt('Hello'); // Creates spans: agent.prompt, llm.request

// Custom observability via events
agent('bot')
    ->on('after_prompt', function($e) {
        // Custom metrics, logging, etc.
    })
    ->prompt('Hello');
```

---

### v0.7.0 Progress Summary (Updated 2025-11-19)

**Completed:**

- ✅ Events/Hooks System (6-8 hours)
  - 36 passing tests (117 assertions)
  - 23 event classes total (Agent, LLM, Tool, Guard, Memory, Stream, MCP)
  - EventManager singleton with per-agent and global listeners
- ✅ TelemetryEventBridge (2-3 hours)
  - 20 passing tests (116 assertions)
  - Automatic span creation for LLM, Tool, Guard, Memory, Stream operations
  - Configurable tracing per operation type
- ✅ OpenTelemetry Exporters (3-4 hours)
  - 82+ total observability tests (196+ assertions)
  - ConsoleExporter, OTLPExporter, JaegerExporter, ZipkinExporter, InMemoryExporter
  - Helper methods in TelemetryManager for all operation types
  - Multiple working examples and integration tests
- ✅ MCP Client Support (6-8 hours)
  - 69 passing tests (315 assertions)
  - Full MCP protocol implementation (v2024-11-05)
  - StdioTransport and HttpSseTransport
  - McpToolAdapter for tool integration
  - 10 MCP-specific events integrated

**Total Completed:** ~20-26 hours of ~49-66 hours (**52% complete**)

**Remaining:**

- Cost & Token Tracking (18-24 hours) - **Largest remaining work**
- TOON Integration (3-4 hours)
- Attribute-Based Tools (6-8 hours)
- HTTP Client Migration (4-6 hours)

**Next Steps:** Cost & Token Tracking is the largest remaining piece and provides critical production functionality.

---

#### 3. Cost & Token Usage Tracking

**Effort:** 18-24 hours | **Plan:** [`ai-docs/plans/cost-token-tracking-plan.md`](plans/cost-token-tracking-plan.md)

- [ ] Track token usage (input, output, cached, total)
- [ ] Calculate costs based on provider pricing
- [ ] Budget enforcement (soft warnings, hard limits)
- [ ] Usage analytics (by agent, session, provider)
- [ ] Persistent storage (SQLite, File adapters)
- [ ] Export capabilities (JSON, CSV, SQLite)
- [ ] OpenTelemetry integration hook

**API Preview:**

```php
agent('bot')
    ->trackUsage([
        'budget' => 10.00, // $10 USD max
        'warn_at' => 0.8,  // Warn at 80%
    ])
    ->prompt('Hello');

$usage = agent('bot')->getUsage();
// ['input_tokens' => 120, 'output_tokens' => 450, 'cost' => 0.0234]

// Session-level tracking
agent('bot')
    ->sessionId('user-123')
    ->sessionBudget(5.00)
    ->prompt('Hello');

// Global analytics
UsageTracker::summary(); // All agents
UsageTracker::byAgent(); // Grouped by agent
UsageTracker::bySession(); // Grouped by session
```

#### 4. TOON Integration (Token Optimization)

**Effort:** 3-4 hours | **Plan:** [`ai-docs/plans/toon-integration-plan.md`](plans/toon-integration-plan.md)

- [ ] TOON encoder wrapper class
- [ ] Configuration option to use TOON for tool schemas (opt-in)
- [ ] TOON support for memory/context serialization (opt-in)
- [ ] Performance comparison tests (JSON vs TOON)
- [ ] Documentation and examples

**API Preview:**

```php
// Use TOON format for tool schemas (30-60% token savings)
agent('bot')
    ->useToon(true)
    ->toonOptions(EncodeOptions::compact())
    ->tool(new FileRead())
    ->prompt('Read config.json');

// Works with both manual and attribute-based tools
agent('bot')
    ->useToon(true)
    ->tool(new CalculatorTool()) // Attribute-based
    ->prompt('Calculate 5 + 3');
```

**Integration:** Works seamlessly with attribute-based tools (auto-generates schema, then encodes in TOON).

#### 5. Attribute-Based Tool Definition

**Effort:** 6-8 hours | **Plan:** [`ai-docs/plans/attribute-based-tools-plan.md`](plans/attribute-based-tools-plan.md)

- [ ] Attribute classes (`#[Tool]`, `#[Parameter]`, `#[Returns]`)
- [ ] Schema generator using reflection
- [ ] Type mapping (PHP types → JSON schema types)
- [ ] Support for scalars, arrays, enums, unions, nullable, defaults
- [ ] AttributeTool base class
- [ ] Integration with existing Tool system
- [ ] Comprehensive test suite (30+ tests)

**API Preview:**

```php
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;

#[Tool(
    name: 'get_weather',
    description: 'Get weather forecast for a location'
)]
class GetWeatherTool extends AttributeTool
{
    public function __invoke(
        #[Parameter(description: 'City name or coordinates')]
        string $location,

        #[Parameter(description: 'Include 7-day forecast')]
        bool $includeForecast = false
    ): array {
        // Implementation - type-safe!
    }
}

agent('weather-bot')
    ->tool(new GetWeatherTool())
    ->prompt('What's the weather in Oslo?');
```

**Integration:** Works with TOON encoding for maximum token efficiency.

#### 6. MCP Client Support (**COMPLETED** ✅)

**Effort:** 6-8 hours | **Plan:** [`ai-docs/plans/mcp-server-support-plan.md`](plans/mcp-server-support-plan.md)
**Status:** COMPLETED 2025-11-19

- [x] Connect to MCP (Model Context Protocol) servers
- [x] Discover available tools from MCP servers
- [x] Map MCP tools to Pagent tools automatically (McpToolAdapter)
- [x] Support stdio and HTTP SSE MCP transports
- [x] Handle tool parameters and responses
- [x] Integration with existing tool system
- [x] MCP event system (10 event classes)
- [x] 69 passing tests (315 assertions)

**API Preview:**

```php
// Connect to MCP server and auto-import tools
$transport = new StdioTransport('npx -y @modelcontextprotocol/server-filesystem /tmp');
$client = new McpClient($transport);
$client->connect();
$tools = $client->discoverTools();

// Register MCP event listeners
$client->on('mcp_connection_established', function($event) {
    echo "Connected to MCP server\n";
});

// HTTP SSE transport
$transport = new HttpSseTransport('http://localhost:3000/mcp');
$client = new McpClient($transport);
```

**References:**

- MCP Specification: https://modelcontextprotocol.io/
- Implementation: `src/Mcp/McpClient.php`

#### 4. TOON Integration (Attribute-based Tool Definition)

**Effort:** 3-4 hours | **Plan:** TBD

- [ ] Integrate helgesverre/toon-php for tool definition
- [ ] Support PHP attribute-based tool schemas
- [ ] Automatic JSON schema generation from attributes
- [ ] Type-safe tool parameter validation
- [ ] Backward compatible with existing tool system
- [ ] Enhanced DX for defining tools

**API Preview:**

```php
use Toon\Tool;
use Toon\ToolParameter;

#[Tool(
    name: 'get_weather',
    description: 'Get weather forecast for a location'
)]
class GetWeatherTool
{
    public function __invoke(
        #[ToolParameter(description: 'City name or coordinates')]
        string $location,

        #[ToolParameter(description: 'Include 7-day forecast')]
        bool $includeForecast = false
    ): array {
        // Implementation
        return ['temp' => 72, 'conditions' => 'sunny'];
    }
}

// Use with Pagent
agent('bot')
    ->toonTool(new GetWeatherTool())
    ->prompt('What is the weather in Paris?');

// Or auto-discover tools
agent('bot')
    ->discoverToonTools(__DIR__ . '/tools')
    ->prompt('Help me plan my trip');
```

**Benefits:**

- Cleaner tool definitions with attributes
- Automatic schema generation (no manual JSON)
- Better IDE support and type checking
- Reusable tool classes
- Standards-compliant with OpenAI function calling

**References:**

- TOON (TypeScript): https://github.com/johannschopplich/toon
- TOON PHP: https://github.com/helgesverre/toon-php

---

## 📋 v0.8.0 - Advanced Workflows & Patterns (Planned)

**Status:** Planned
**Effort:** 26-36 hours
**Timeline:** Month 3-4
**Plan:** See [`ai-docs/plans/workflow-orchestration-plan.md`](plans/workflow-orchestration-plan.md)

### Features

#### 1. Workflow (Branching Logic)

**Effort:** 3-4 hours

- [ ] Conditional routing based on agent output
- [ ] Branch to different agents based on conditions
- [ ] Merge results from multiple branches
- [ ] Customer support triage patterns

**API Preview:**

```php
Workflow::create()
    ->start(agent('intake'))
    ->then(agent('classifier'))
    ->branch(fn($r) => match($r->type) {
        'tech' => agent('tech-support'),
        'billing' => agent('billing'),
        default => agent('general'),
    })
    ->run($message);
```

#### 2. Graph (Full DAG)

**Effort:** 5-6 hours

- [ ] Node-based workflow definition
- [ ] Edge connections with conditions
- [ ] Cycle detection (DFS algorithm)
- [ ] Mermaid diagram visualization
- [ ] Complex approval workflows

**API Preview:**

```php
Graph::create()
    ->node('start', agent('intake'))
    ->node('classify', agent('classifier'))
    ->node('tech', agent('tech-support'))
    ->node('billing', agent('billing'))
    ->edge('start', 'classify')
    ->edge('classify', 'tech', when: fn($r) => $r->type === 'tech')
    ->edge('classify', 'billing', when: fn($r) => $r->type === 'billing')
    ->run('start', $input);
```

#### 3. Parallel Execution

**Effort:** 2-3 hours

- [ ] Sequential execution (default, always available)
- [ ] Optional true parallelism (pcntl/amphp/ReactPHP)
- [ ] Result merging and aggregation
- [ ] Multi-source data collection

**API Preview:**

```php
parallel([
    agent('translator')->task('Translate to Spanish'),
    agent('summarizer')->task('Create summary'),
    agent('analyzer')->task('Analyze sentiment'),
])->await();
```

#### 4. Advanced Reasoning Patterns

**Effort:** 10-15 hours

- [ ] **ReAct Pattern** - Reasoning + Acting loop (3-4h)
- [ ] **Chain-of-Thought** - Step-by-step reasoning (3-4h)
- [ ] **Tree of Thoughts** - Multi-path exploration (5-6h)

**API Preview:**

```php
// ReAct Pattern
agent('solver')->react(
    thought: 'I need to calculate the total',
    action: fn() => $this->tool('calculate', [10, 20]),
    observation: fn($result) => "The result is {$result}",
);

// Chain-of-Thought
agent('math')->chainOfThought()
    ->step('Understand the problem')
    ->step('Break into sub-problems')
    ->step('Solve each sub-problem')
    ->step('Combine results')
    ->validate(fn($output) => /* check */);
```

#### 5. Events/Hooks System

**Effort:** 6-8 hours | **Plan:** [`ai-docs/plans/events-hooks-system-plan.md`](plans/events-hooks-system-plan.md)

- [ ] Event-driven architecture for observability
- [ ] Replace manual TelemetryManager span creation with events
- [ ] Typed event classes for all Agent lifecycle points
- [ ] EventDispatcher with priority system
- [ ] Hybrid interface + closure pattern (like Guards)
- [ ] Global and per-agent event scopes
- [ ] TelemetryEventBridge for automatic span creation
- [ ] Propagation control and listener management

**API Preview:**

```php
// Per-agent event listeners
agent('bot')
    ->on('llm.response', fn(AfterLLMResponseEvent $e) =>
        Log::info('LLM Response', ['tokens' => $e->tokens])
    )
    ->prompt('Hello');

// Class-based listener
agent('bot')->listen(new CustomEventListener());

// Global events
EventManager::instance()
    ->on('tool.executed', fn(ToolExecutedEvent $e) =>
        metrics()->record('tool_usage', $e->toolName)
    );

// Priority and control
agent('bot')
    ->on('guard.violated', $handler, priority: 100)
    ->once('agent.prompt', fn($e) => /* one-time listener */)
    ->off('llm.response', $oldHandler);

// Event-driven telemetry (replaces manual spans)
EventManager::instance()->listen(new TelemetryEventBridge());
```

**Breaking Change:** This refactors observability from manual `TelemetryManager::startSpan()` calls to event-driven span creation. Migration guide provided in plan.

---

## 🔮 v0.9.0 - HTTP Server & Multi-Agent (Future)

**Status:** Vision
**Effort:** 15-20 hours
**Timeline:** Month 5-6

### Features

#### 1. HTTP Server Integration

**Effort:** 10-12 hours

- [ ] Built-in HTTP server (ReactPHP, Swoole, or RoadRunner)
- [ ] Automatic API endpoint generation
- [ ] RESTful API with validation
- [ ] WebSocket support for real-time chat
- [ ] CORS configuration
- [ ] Authentication and authorization middleware
- [ ] Rate limiting per endpoint
- [ ] Health checks and metrics endpoints

**API Preview:**

```php
agent('support-bot')
    ->serve([
        'host' => '0.0.0.0',
        'port' => 8080,
        'path' => '/api/chat',
        'auth' => ['bearer' => env('API_TOKEN')],
        'stream' => true, // SSE streaming
    ]);

// Multi-agent API server
server()
    ->agent('support', agent('support-bot'), '/chat/support')
    ->agent('sales', agent('sales-bot'), '/chat/sales')
    ->middleware('auth', new BearerAuth())
    ->middleware('cors', new CorsMiddleware(['*']))
    ->start('0.0.0.0:8080');
```

#### 2. Swarm Intelligence

**Effort:** 4-5 hours

- [ ] Multi-agent voting and consensus
- [ ] Democratic decision-making
- [ ] Reduce hallucinations through agreement

**API Preview:**

```php
swarm(['agent1', 'agent2', 'agent3'])
    ->vote('What should we do?')
    ->consensus(threshold: 0.7);
```

#### 3. Conditional Router

**Effort:** 2-3 hours

- [ ] Dynamic agent selection based on intent
- [ ] Customer support routing
- [ ] Multi-domain bots

**API Preview:**

```php
router()
    ->when('intent' === 'technical', agent('tech-support'))
    ->when('intent' === 'billing', agent('billing'))
    ->default(agent('general'));
```

---

## 🌟 v1.0.0 - Enterprise Ready (Future)

**Status:** Vision
**Effort:** 30-40 hours
**Timeline:** 6+ months

### Enterprise Features

- [ ] Advanced caching (prompt, response, semantic)
- [ ] Comprehensive audit logging
- [ ] Health checks and monitoring endpoints
- [ ] Fine-tuning integration for custom models
- [ ] A/B testing framework
- [ ] Deployment strategies (canary, blue-green)
- [ ] Multi-tenancy support with isolation
- [ ] SLA monitoring and alerting

### Developer Experience

- [ ] CLI tool for interactive development

  ```bash
  pagent chat assistant
  pagent test --dataset tests/data.json
  pagent deploy --env production
  ```

- [ ] Web-based debugging UI
  - Conversation replay
  - Step-through debugging
  - Tool execution traces
  - Performance profiling

- [ ] Framework integrations
  - Laravel package with service provider
  - Symfony bundle
  - WordPress plugin helpers

- [ ] Custom PHPUnit assertions
  ```php
  $this->assertAgentResponded($agent, 'Hello');
  $this->assertToolCalled($agent, 'calculate');
  $this->assertGuardBlocked($agent, PIIGuard::class);
  ```

### Additional MCP Features

- [ ] **MCP Server Implementation** - Expose Pagent as MCP server via HTTP API
  - Allow external clients to use Pagent agents as MCP tools
  - Full MCP protocol compliance
  - Authentication and authorization
  - Rate limiting and quotas

---

## 🛠️ Technical Debt & Improvements

### High Priority

- [ ] Fix PHPStan errors (26 errors in src folder)
- [ ] Extract HTTP client to PSR-18 compatible interface
- [ ] Add retry logic with exponential backoff
- [ ] Improve error messages with context and suggestions

### Medium Priority

- [ ] Request/response interceptors
- [ ] Response caching (semantic caching)
- [ ] Debug/verbose mode
- [ ] Performance benchmarks

### Low Priority

- [ ] Memory usage tracking
- [ ] Provider failover and load balancing
- [ ] Conversation history pruning strategies

---

## 📚 Documentation & Community

### Ongoing

- [ ] Comprehensive API documentation (auto-generated)
- [ ] Architecture Decision Records (ADRs)
- [ ] Real-world case studies with metrics
- [ ] Performance optimization guide
- [ ] Security best practices handbook
- [ ] Migration guides between versions
- [ ] Interactive playground (try agents in browser)

### Content Creation

- [ ] 100 article ideas (see `ai-docs/future/article-ideas.md`)
- [ ] Video tutorials and workshops
- [ ] Conference talks and meetups
- [ ] Community tool registry

---

## 🔬 Libraries to Evaluate

### For v0.7.0

- **open-telemetry/sdk** - Observability and tracing
- **spiral/json-schema-generator** - Attribute-based DTO schema generation

### For v0.8.0+

- **ReactPHP / Swoole / RoadRunner** - Async HTTP server
- **Pinecone / Weaviate / Qdrant** - Vector databases for RAG
- **amphp/parallel** - True parallel execution in PHP

---

## 📊 Success Metrics

### v0.7.0 Goals

- [ ] Full OpenTelemetry integration with 4+ platforms
- [ ] Cost tracking accurate within 1%
- [ ] 30+ observability tests passing
- [ ] MCP server integration working
- [ ] Production usage examples

### v0.8.0 Goals

- [ ] All workflow patterns implemented
- [ ] 70-95 workflow tests passing
- [ ] Advanced reasoning patterns (ReAct, CoT)
- [ ] Graph visualization working
- [ ] Events/hooks system fully functional
- [ ] 30-40 event system tests passing
- [ ] Event-driven telemetry replacing manual spans
- [ ] Migration guide for breaking changes

### v1.0.0 Goals

- [ ] 350+ tests passing
- [ ] 100+ community stars
- [ ] 10+ production users
- [ ] Complete enterprise features
- [ ] Laravel/Symfony packages

---

## 🎯 Current Focus

**Version:** v0.7.0 (Planning)
**Priority Features:**

1. OpenTelemetry Observability (10-15 hours)
2. Cost & Token Tracking (4-6 hours)
3. MCP Server Support (6-8 hours)
4. TOON Integration (3-4 hours)

**Next Milestone:** v0.8.0 - Advanced Workflows & Patterns
**Timeline:** 2-3 months to v1.0.0

---

## 📖 Related Documents

- **Plans:** `ai-docs/plans/` - Implementation plans for upcoming features
- **Specs:** `ai-docs/specs/` - Technical specifications and architecture
- **Reports:** `ai-docs/reports/` - Historical implementation reports
- **Features:** `ai-docs/FEATURES.md` - Complete feature list

---

**Last Updated:** 2025-11-19
**Maintained By:** Pagent Core Team
**Format:** Chronological by version, consistent structure
