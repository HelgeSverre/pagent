# Pagent Development Roadmap

**Consolidated development plan combining roadmap, next steps, and task tracking**

---

## 🎉 Current Status - v0.5.1 (Workflow & Tools Ready!)

### What We've Built

**Core Features:**

- ✅ Fluent API inspired by PestPHP
- ✅ Multi-provider support (Anthropic Claude, OpenAI GPT, Mock)
- ✅ Automatic tool calling with JSON schema generation
- ✅ Safety guards (PII detection, content filtering, prompt injection prevention)
- ✅ Evaluation framework (datasets, metrics, HTML/JSON/MD reports)
- ✅ Middleware pipeline (logging, rate limiting, metrics tracking)
- ✅ Multi-agent orchestration (pipeline, handoff, delegation patterns)
- ✅ **Workflow patterns** (Chain, Pipeline with named steps & transforms)
- ✅ **8 Built-in Tools** (FileRead, FileWrite, Glob, Grep, WebFetch, Bash, PdfReader, DataExtract)
- ✅ Tool validation with type checking
- ✅ Conversation history and context management
- ✅ Complete documentation (5 different guide styles)

**Metrics:**

- 📊 **229 tests** passing (508 assertions) 
- 🎯 **99%+ pass rate**
- 📚 **11 working examples**
- 🔒 **PHPStan level 9** (maximum strictness)
- 🚀 **PHP 8.3** full type safety
- 🛠️ **8 production-ready tools** with security guards
- 🔄 **2 workflow patterns** (Chain, Pipeline)
- 📖 **100 article ideas** planned
- 🎨 **5 documentation styles** (Conversational, Recipes, Quick Start, Concepts, API Reference)

**Recent Additions (v0.5.1):**

- ✅ **Chain Workflow** - Simple sequential agent execution
- ✅ **Pipeline Workflow** - Named steps with intermediate results access
- ✅ **Transform steps** - Data manipulation between agent calls
- ✅ **8 Built-in Tools:**
  - FileRead - Read files with security guards
  - FileWrite - Write/create files safely
  - Glob - Find files with `**/*.php` patterns
  - Grep - Search text/regex in files
  - WebFetch - HTTP GET with SSRF protection
  - Bash - Execute shell commands (whitelisted)
  - PdfReader - Extract text from PDFs (pdftotext)
  - DataExtract - Structured extraction with JSON Schema
- ✅ **Security features:** Path traversal prevention, SSRF protection, command whitelisting
- ✅ **46 tool tests** with comprehensive coverage

---

## 🎯 Immediate Next Steps

### ✅ COMPLETED: v0.5.1 - Workflows & Tools

**Implemented:**
- Chain and Pipeline workflow patterns
- 8 production-ready tools with security guards
- 46 tool tests passing
- 2 workflow examples

**Status:** Ready for use! 🎉

---

### 🚀 Path A: Publish & Share (NEXT PRIORITY)

**Time**: 3-4 hours  
**Goal**: Make Pagent public and discoverable  
**Priority**: HIGH

#### Tasks:

1. **GitHub Actions CI/CD** (1 hour)

   ```yaml
   # .github/workflows/tests.yml
   - PHP 8.3 matrix testing
   - Run Pest test suite
   - PHPStan static analysis
   - Code style checks with Pint
   - Code coverage reporting
   ```

2. **Publish to Packagist** (30 min)
   - Register package as `helgesverre/pagent`
   - Link GitHub repository
   - Enable automatic updates from GitHub releases

3. **Polish README** (1 hour)
   - Add installation via Composer
   - Add badges (build status, version, downloads, license)
   - Highlight v0.4.0 features
   - Update quick start guide
   - Link to new documentation guides

4. **Create CONTRIBUTING.md** (30 min)
   - Contribution guidelines
   - Development setup instructions
   - Testing requirements
   - Code style guide (Pint configuration)
   - Pull request process

5. **Create Architecture Diagram** (1 hour)
   - System overview (Mermaid diagram)
   - Component relationships
   - Data flow visualization
   - Provider abstraction layer
   - Tool calling architecture

**Deliverables:**

- ✅ Public Packagist package
- ✅ Automated CI/CD testing
- ✅ Community-ready documentation
- ✅ Clear contribution path

---

### 🔧 Path B: Enhanced Tools (HIGH VALUE)

**Time**: 6-8 hours  
**Goal**: More robust and feature-rich tool system  
**Priority**: MEDIUM

#### Features to Implement:

```php
// 1. Tool timeout
agent('bot')
    ->tool('slow-api', 'Call slow API', fn() => /* ... */)
        ->timeout(5); // 5 second timeout

// 2. Retry logic with exponential backoff
agent('bot')
    ->tool('flaky-api', 'Unreliable API', fn() => /* ... */)
        ->retry(3, backoff: 'exponential');

// 3. Return type validation
agent('bot')
    ->tool('get-age', 'Get user age', fn(): int => "thirty") // Runtime error!
        ->validateReturnType();

// 4. Attributes for better documentation
#[Description('Get weather forecast for a location')]
function getWeather(
    #[Param('City name or coordinates')] string $location,
    #[Param('Include 7-day forecast')] bool $includeForecast = false
): array {
    // Implementation
}

// 5. Built-in tool library
use Pagent\Tools\{FileReader, WebFetcher, Calculator, DateFormatter};

agent('assistant')
    ->tool(new FileReader(maxSize: 1024 * 1024)) // 1MB limit
    ->tool(new WebFetcher(timeout: 10))
    ->tool(new Calculator())
    ->tool(new DateFormatter());

// 6. Tool error recovery
agent('bot')
    ->tool('api-call', 'Call API', fn() => /* ... */)
        ->onError(fn($e) => ['error' => $e->getMessage(), 'status' => 'failed']);
```

#### Tasks:

- [ ] Tool timeout configuration (1-2 hours)
- [ ] Retry logic with exponential backoff (2-3 hours)
- [ ] Return type validation (1 hour)
- [ ] PHP attribute parsing for descriptions (1-2 hours)
- [ ] Built-in tool classes (2-3 hours)
  - FileReader, WebFetcher, Calculator, DateFormatter
- [ ] Better error messages with suggestions (1 hour)
- [ ] **Evaluate spiral/json-schema-generator** for automatic schema generation (2-3 hours)
  - https://github.com/spiral/json-schema-generator
  - Generate JSON schemas from PHP DTOs automatically
  - Replace manual schema generation with attribute-based approach
  - Support PHPDoc constraints and validation rules

---

## ✅ Quick Wins (COMPLETED!)

**These have been implemented:**

1. ✅ **Better error messages** - Includes suggestions for typos

   ```php
   // "Tool 'calc' not found. Did you mean 'calculate'? Available: add, multiply, divide"
   ```

2. ✅ **Reset methods** - Clear configuration

   ```php
   agent('bot')->clearTools();
   agent('bot')->clearGuards();
   agent('bot')->clearMiddleware();
   agent('bot')->reset(); // Clear everything + messages
   ```

3. ✅ **Agent cloning** - Duplicate configuration

   ```php
   $bot2 = agent('bot1')->clone('bot2');
   ```

4. ✅ **Conversation export/import** - Save and restore state

   ```php
   $json = agent('bot')->exportConversation();
   agent('bot')->importConversation($json);
   ```

5. ✅ **Usage statistics** - Track performance

   ```php
   $stats = agent('bot')->getStats(); // Total tokens, calls, duration
   ```

6. ✅ **Guard statistics** - Monitor security
   ```php
   $stats = agent('bot')->getGuardStats(); // Trigger counts per guard
   ```

---

## 📋 Version Roadmap

### v0.5.0 - Publishing & Enhanced Tools

**Target**: This week (3-4 hours for publishing, 6-8 hours for tools)  
**Status**: Ready to start

**Publishing (Priority: HIGH):**

- [ ] Set up GitHub Actions CI/CD
- [ ] Publish to Packagist
- [ ] Add README badges
- [ ] Create CONTRIBUTING.md
- [ ] Create architecture diagram
- [ ] Share and promote (Reddit, Twitter, awesome-php)

**Enhanced Tools (Priority: MEDIUM):**

- [ ] Tool timeout configuration
- [ ] Retry logic with exponential backoff
- [ ] Return type validation
- [ ] PHP attributes for tool descriptions
- [ ] Built-in tools library (FileReader, WebFetcher, Calculator, etc.)
- [ ] Tool error recovery and fallbacks

**Success Criteria:**

- ✅ Published to Packagist
- ✅ GitHub Actions CI/CD running
- ✅ Tool timeout and retry support
- ✅ 3+ built-in tools available
- ✅ 175+ tests passing
- ✅ Architecture diagram complete

---

### v0.6.0 - Observability & Monitoring

**Target**: Next month (10-15 hours)  
**Status**: Planned

**OpenTelemetry Integration:**

Full observability with spans, traces, and events for production monitoring.

```php
// Automatic instrumentation
agent('bot')
    ->observability('langfuse', [
        'public_key' => env('LANGFUSE_PUBLIC_KEY'),
        'secret_key' => env('LANGFUSE_SECRET_KEY'),
        'trace_id' => 'session-' . uniqid(),
    ])
    ->prompt('Hello'); // Automatically traced

// Custom spans for specific operations
agent('bot')->withSpan('database-query', function() {
    return DB::query('SELECT ...');
});

// Manual event logging
agent('bot')->logEvent('user-feedback', [
    'rating' => 5,
    'comment' => 'Excellent response',
    'user_id' => auth()->id(),
]);
```

**Features:**

- [ ] OpenTelemetry SDK integration
- [ ] Automatic span creation for:
  - Agent prompt/response cycles
  - Tool execution with timing
  - Guard validation checks
  - Middleware processing
  - Multi-agent handoffs and delegation
- [ ] Support for multiple backends:
  - Langfuse (https://langfuse.com)
  - Langsmith (https://www.langsmith.com)
  - Phoenix (https://phoenix.arize.com)
  - Generic OTLP exporters
- [ ] Metadata enrichment (user ID, session ID, custom tags)
- [ ] Cost tracking per trace
- [ ] Token usage breakdown
- [ ] Error attribution with stack traces
- [ ] Performance profiling and bottleneck detection

**References:**

- Mistral AI Observability: https://docs.mistral.ai/guides/observability/#integrations
- OpenTelemetry PHP: https://opentelemetry.io/docs/languages/php/

---

### v0.7.0 - Memory, Streaming & HTTP Server

**Target**: 2-3 months (20-25 hours)  
**Status**: Planned

#### Memory & Persistence

Persistent storage for long-running conversations and knowledge accumulation.

**Features:**

- [ ] Persistent conversation storage (SQLite, Redis, MySQL)
- [ ] Automatic conversation summarization for long contexts
- [ ] Context window management with intelligent pruning
- [ ] Session management with TTL and expiration
- [ ] Vector storage integration (Pinecone, Weaviate, Qdrant)
- [ ] RAG (Retrieval-Augmented Generation) support
- [ ] Knowledge base tools for document retrieval

```php
// SQLite persistence
agent('support')
    ->memory('sqlite', ['path' => 'storage/conversations.db'])
    ->sessionId('user-' . auth()->id());

// Redis with TTL
agent('chat')
    ->memory('redis', ['host' => 'localhost', 'ttl' => 3600]);

// Vector storage for RAG
agent('research')
    ->memory('pinecone', ['api_key' => '...', 'index' => 'docs'])
    ->tool('search-docs', 'Search knowledge base', fn($query) => /* vector search */);
```

#### Streaming Support

Real-time output for better user experience.

**Features:**

- [ ] Server-Sent Events (SSE) for streaming responses
- [ ] WebSocket integration
- [ ] Progress callbacks during execution
- [ ] Chunk-by-chunk processing
- [ ] Streaming tool results
- [ ] Cancellation support

```php
// SSE streaming
agent('assistant')->streamTo(function($chunk) {
    echo "data: {$chunk}\n\n";
    flush();
});

// Progress callback
agent('worker')->onProgress(function($status) {
    Log::info("Progress: {$status}");
});
```

#### HTTP Server Integration

Deploy agents as HTTP services (inspired by Bun.serve()).

**Features:**

- [ ] Built-in HTTP server (ReactPHP, Swoole, or RoadRunner)
- [ ] Automatic API endpoint generation from agents
- [ ] RESTful API with request/response validation
- [ ] WebSocket support for real-time chat
- [ ] CORS configuration
- [ ] Authentication and authorization middleware
- [ ] Rate limiting per endpoint
- [ ] Health checks and metrics endpoints
- [ ] Graceful shutdown and restart

```php
// Expose single agent via HTTP
agent('support-bot')
    ->system('You are a helpful support agent')
    ->tool('search-kb', 'Search knowledge base', fn($q) => /* ... */)
    ->serve([
        'host' => '0.0.0.0',
        'port' => 8080,
        'path' => '/api/chat',
        'auth' => ['bearer' => env('API_TOKEN')],
        'cors' => ['*'],
        'rate_limit' => ['max' => 100, 'window' => 60],
    ]);

// Multi-agent API server
server()
    ->agent('support', agent('support-bot'), '/chat/support')
    ->agent('sales', agent('sales-bot'), '/chat/sales')
    ->agent('technical', agent('tech-bot'), '/chat/technical')
    ->middleware('auth', new BearerAuth())
    ->middleware('logging', new RequestLogger())
    ->middleware('cors', new CorsMiddleware(['*']))
    ->start('0.0.0.0:8080');

// Streaming endpoint
agent('assistant')->serve([
    'path' => '/stream',
    'stream' => true, // Enable SSE
    'format' => 'json-lines', // Or 'sse'
]);
```

**Use Cases:**

- Deploy agents as microservices
- Create chatbot APIs for frontends
- Build agent-powered webhooks
- Serve multiple specialized agents from single server
- Mobile app backends
- Integration with React/Vue/Svelte apps

---

### v0.8.0 - Advanced Patterns

**Target**: 3-4 months (15-20 hours)  
**Status**: Planned

#### Agentic Reasoning Patterns

Implement advanced prompting and reasoning techniques.

**Features:**

- [ ] **ReAct Pattern** - Reasoning + Acting loop

  ```php
  agent('solver')->react(
      thought: 'I need to calculate the total',
      action: fn() => $this->tool('calculate', [10, 20]),
      observation: fn($result) => "The result is {$result}",
  );
  ```

- [ ] **Chain-of-Thought** - Step-by-step reasoning with validation

  ```php
  agent('math')->chainOfThought()
      ->step('Understand the problem')
      ->step('Break it into sub-problems')
      ->step('Solve each sub-problem')
      ->step('Combine results')
      ->validate(fn($output) => /* check correctness */);
  ```

- [ ] **Tree of Thoughts** - Explore multiple reasoning paths

  ```php
  agent('planner')->treeOfThoughts([
      'branches' => 3, // Explore 3 different approaches
      'depth' => 2,    // Go 2 levels deep
      'selector' => fn($branches) => /* pick best path */,
  ]);
  ```

- [ ] **Plan-and-Execute** - Strategic task decomposition

  ```php
  agent('coordinator')
      ->plan('Write a research paper')
      ->decompose(fn($task) => /* break into subtasks */)
      ->assign(fn($subtask) => /* assign to worker agents */)
      ->execute();
  ```

- [ ] **Reflection Pattern** - Self-critique and improvement
  ```php
  agent('writer')
      ->reflect(function($output) {
          return agent('critic')->prompt("Review this: {$output}");
      })
      ->refine(fn($feedback) => /* improve based on feedback */);
  ```

#### Advanced Orchestration

**Features:**

- [ ] **Swarm Intelligence** - Multi-agent voting and consensus

  ```php
  swarm(['agent1', 'agent2', 'agent3'])
      ->vote('What should we do?')
      ->consensus(threshold: 0.7);
  ```

- [ ] **Conditional Routing** - Dynamic agent selection

  ```php
  router()
      ->when('intent' === 'technical', agent('tech-support'))
      ->when('intent' === 'billing', agent('billing'))
      ->default(agent('general'));
  ```

- [ ] **Parallel Execution** - Run agents concurrently

  ```php
  parallel([
      agent('translator')->task('Translate to Spanish'),
      agent('summarizer')->task('Create summary'),
      agent('analyzer')->task('Analyze sentiment'),
  ])->await();
  ```

- [ ] **State Machines** - Complex workflow orchestration
  ```php
  stateMachine()
      ->state('draft', agent('writer'))
      ->state('review', agent('editor'))
      ->state('publish', agent('publisher'))
      ->transition('draft', 'review', when: 'complete')
      ->transition('review', 'publish', when: 'approved')
      ->start('draft');
  ```

---

### v1.0.0 - Enterprise Ready

**Target**: 6 months (30-40 hours)  
**Status**: Vision

#### Enterprise Features

**Features:**

- [ ] Cost tracking and budget enforcement
- [ ] Comprehensive audit logging for compliance
- [ ] Health checks and monitoring endpoints
- [ ] Advanced caching (prompt caching, response caching, semantic caching)
- [ ] Fine-tuning integration for custom models
- [ ] A/B testing framework for agent variants
- [ ] Deployment strategies (canary, blue-green)
- [ ] Multi-tenancy support with isolation
- [ ] SLA monitoring and alerting

#### Developer Experience

**Features:**

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

- [ ] Mock providers with scenario recording/replay

  ```php
  $mock = mock()->record('scenario1.json');
  // ... run tests ...
  $mock->replay('scenario1.json');
  ```

- [ ] Framework integrations:
  - Laravel package with service provider
  - Symfony bundle
  - WordPress plugin helpers

- [ ] Custom PHPUnit assertions
  ```php
  $this->assertAgentResponded($agent, 'Hello');
  $this->assertToolCalled($agent, 'calculate');
  $this->assertGuardBlocked($agent, PIIGuard::class);
  ```

---

## 🛠️ Technical Debt

### High Priority

- [ ] **Fix PHPStan errors** (~168 warnings in test files)
  - Most are from dynamic LLM response objects
  - Add proper type stubs or suppress selectively

- [ ] **Extract HTTP client** to PSR-18 compatible class
  - Make it swappable (Guzzle, Symfony HTTP Client, etc.)
  - Add interface for custom implementations

- [ ] **Add retry logic** for API failures
  - Exponential backoff
  - Configurable retry count
  - Circuit breaker pattern

- [ ] **Improve error handling**
  - Better error messages with context
  - Suggestions for common mistakes
  - Debug information in non-production

### Medium Priority

- [ ] **Request/response interceptors**
  - Log all API calls
  - Modify requests before sending
  - Transform responses after receiving

- [ ] **Response caching**
  - Semantic caching for similar prompts
  - TTL-based cache invalidation
  - Configurable cache backend

- [ ] **Debug/verbose mode**
  - Detailed logging of all operations
  - Tool execution traces
  - Guard check details

- [ ] **Performance benchmarks**
  - Token usage statistics
  - Response time tracking
  - Memory consumption profiling

### Low Priority

- [ ] **Memory usage tracking**
  - Monitor conversation size
  - Alert when approaching limits
  - Automatic pruning

- [ ] **Provider failover**
  - Automatic switch on provider failure
  - Load balancing across providers
  - Cost-based selection

- [ ] **Conversation history pruning**
  - Intelligent context window management
  - Summarization of old messages
  - Configurable retention policies

---

## 🔬 Libraries & Tools to Evaluate

### spiral/json-schema-generator (v0.5.0)

**URL:** https://github.com/spiral/json-schema-generator

**Current approach:** Manual reflection-based schema generation from function signatures

**Potential improvement:** Attribute-based DTO schema generation

```php
// Current: Manual reflection
fn(string $city, bool $forecast = false) => [...]

// Future: DTO with attributes
class WeatherRequest {
    #[Field(description: 'City name')]
    #[Constraint\Length(min: 2, max: 100)]
    public string $city;

    #[Field(description: 'Include forecast')]
    public bool $includeForecast = false;
}

agent('weather')->tool('getWeather', WeatherRequest::class, fn(WeatherRequest $req) => [...]);
```

**Benefits:** Better validation, reusable DTOs, PHPDoc constraints, type-safe inputs  
**Effort:** 2-3 hours

### PSR-18 HTTP Clients (Technical Debt)

**Current:** Manual cURL in providers  
**Target:** PSR-18 compatible (Guzzle, Symfony HTTP Client)

**Benefits:** Middleware, better errors, retry logic, testing utilities  
**Effort:** 3-4 hours

### OpenTelemetry PHP (v0.6.0)

**Purpose:** Production observability (Langfuse, Langsmith, Phoenix integration)  
**Effort:** 10-15 hours

### ReactPHP / Swoole / RoadRunner (v0.7.0)

**Purpose:** Async HTTP server for deploying agents as APIs  
**Effort:** 10-15 hours

### Vector Databases (v0.7.0)

**Options:** Pinecone, Weaviate, Qdrant  
**Purpose:** RAG, semantic search, long-term memory  
**Effort:** 8-10 hours

---

## 📚 Long-term Vision

### Community & Ecosystem

- [ ] **Plugin system** for custom providers
- [ ] **Community tool registry** (like npm for AI tools)
- [ ] **Agent template marketplace**
- [ ] **Pre-built agents** for common use cases:
  - Customer support chatbots
  - Research assistants
  - Code review agents
  - Data analysis agents
  - Content generation engines
- [ ] **Example projects** and starter kits
- [ ] **Video tutorials** and workshops
- [ ] **Conference talks** and meetups

### Documentation

- [ ] **Comprehensive API documentation** (auto-generated)
- [ ] **Architecture Decision Records** (ADRs)
- [ ] **Real-world case studies** with metrics
- [ ] **Performance optimization guide**
- [ ] **Security best practices** handbook
- [ ] **Migration guides** between versions
- [ ] **Internationalization** support (multi-language docs)
- [ ] **Interactive playground** (try agents in browser)

---

## 🚀 Recommended Action Plan

### Week 1: Publishing (3-4 hours)

**Goal**: Make Pagent publicly available

1. ✅ Set up GitHub Actions workflow (1 hour)
2. ✅ Publish to Packagist (1 hour)
3. ✅ Add badges and polish README (1 hour)
4. ✅ Create CONTRIBUTING.md (30 min)
5. ✅ Create architecture diagram (1 hour)
6. ✅ Share and promote (Reddit, Twitter, Dev.to)

**Outcome**: Public, installable, CI/CD enabled

---

### Week 2-3: Enhanced Tools (6-8 hours)

**Goal**: Robust tool system

1. Tool timeout support (2 hours)
2. Retry logic with exponential backoff (2-3 hours)
3. Return type validation (1 hour)
4. Built-in tools library (2-3 hours)
5. Better error messages (1 hour)

**Outcome**: Production-grade tool system

---

### Month 2: Observability (10-15 hours)

**Goal**: Production monitoring and debugging

1. OpenTelemetry SDK integration (3-4 hours)
2. Automatic span creation (3-4 hours)
3. Langfuse/Langsmith integration (2-3 hours)
4. Cost and token tracking (2-3 hours)
5. Error tracking and debugging (1-2 hours)

**Outcome**: Full observability for production agents

---

### Month 3: Memory & HTTP Server (20-25 hours)

**Goal**: Persistent state and API deployment

1. Memory persistence layer (4-5 hours)
2. Streaming support foundation (4-5 hours)
3. HTTP server implementation (6-8 hours)
4. API endpoint generation (3-4 hours)
5. Production deployment guides (2-3 hours)

**Outcome**: Deploy agents as HTTP services

---

## 📊 Success Metrics

### v0.5.0 Goals:

- [ ] Published to Packagist with auto-updates
- [ ] GitHub Actions CI/CD running on all PRs
- [ ] 175+ tests passing (95%+ pass rate)
- [ ] 3+ built-in tools available
- [ ] Tool timeout and retry working
- [ ] 10+ community stars/downloads

### v0.6.0 Goals:

- [ ] Full OpenTelemetry integration
- [ ] 2+ observability platform integrations
- [ ] Cost tracking per agent/trace
- [ ] Production usage examples

### v0.7.0 Goals:

- [ ] Memory persistence working
- [ ] SSE streaming implemented
- [ ] HTTP server deployable
- [ ] Docker deployment guide

### v1.0.0 Goals:

- [ ] 250+ tests passing
- [ ] 50+ community stars
- [ ] 5+ production users
- [ ] Complete enterprise features
- [ ] Laravel/Symfony packages

---

## 🎯 Current Status Summary

**Version:** v0.4.0  
**Status:** ✅ Production Ready  
**Next Milestone:** v0.5.0 (Publishing & Enhanced Tools)  
**Estimated Time to v1.0.0:** 40-60 hours of focused development

**Immediate Priority:** Path A (Publishing) - Get Pagent public!

**All systems go!** 🚀
