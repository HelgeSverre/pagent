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
**Effort:** 24-33 hours
**Timeline:** Month 2-3
**Plan:** See `ai-docs/plans/`

### Features

#### 1. OpenTelemetry Observability

**Effort:** 10-15 hours | **Plan:** [`ai-docs/plans/opentelemetry-observability-plan.md`](plans/opentelemetry-observability-plan.md)

- [ ] OpenTelemetry SDK integration
- [ ] Automatic span creation for:
  - Agent prompt/response cycles
  - Tool execution with timing
  - Guard validation checks
  - Middleware processing
  - Multi-agent handoffs
- [ ] Platform integrations:
  - Langfuse (LLM observability)
  - Langsmith (LangChain ecosystem)
  - Phoenix (Arize AI)
  - Generic OTLP exporters
- [ ] Metadata enrichment (user ID, session ID, tags)
- [ ] Error attribution with stack traces
- [ ] Performance profiling

**API Preview:**

```php
agent('bot')
    ->observability('langfuse', [
        'public_key' => env('LANGFUSE_KEY'),
        'trace_id' => 'session-' . uniqid(),
    ])
    ->prompt('Hello'); // Automatically traced

agent('bot')->withSpan('database-query', function() {
    return DB::query('SELECT ...');
});

agent('bot')->logEvent('user-feedback', [
    'rating' => 5,
    'comment' => 'Excellent',
]);
```

#### 2. Cost & Token Usage Tracking

**Effort:** 4-6 hours | **Plan:** [`ai-docs/plans/cost-token-tracking-plan.md`](plans/cost-token-tracking-plan.md)

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

#### 3. MCP Server Support (Consumer)

**Effort:** 6-8 hours | **Plan:** TBD

- [ ] Connect to MCP (Model Context Protocol) servers
- [ ] Discover available tools from MCP servers
- [ ] Map MCP tools to Pagent tools automatically
- [ ] Support stdio and HTTP MCP transports
- [ ] Handle tool parameters and responses
- [ ] Integration with existing tool system

**API Preview:**

```php
// Connect to MCP server and auto-import tools
agent('bot')
    ->mcpServer('filesystem', [
        'transport' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-filesystem'],
    ])
    ->prompt('List files in /tmp');

// Or HTTP transport
agent('bot')
    ->mcpServer('api', [
        'transport' => 'http',
        'url' => 'http://localhost:3000/mcp',
    ])
    ->prompt('Get user data');
```

**References:**

- MCP Specification: https://modelcontextprotocol.io/
- MCP PHP: https://github.com/modelcontextprotocol/php-sdk

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

**Last Updated:** 2025-10-29
**Maintained By:** Pagent Core Team
**Format:** Chronological by version, consistent structure
