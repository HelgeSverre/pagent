# Pagent Development Roadmap

## Current Status - v0.4.0

**Production Ready**

- Core agent system with fluent API
- Multi-provider support (Anthropic, OpenAI, Mock)
- Automatic tool calling and execution
- Safety guards (PII, content filtering, prompt injection)
- Evaluation framework (datasets, metrics, HTML/JSON/MD reports)
- Middleware pipeline (logging, rate limiting, metrics)
- Tool validation with type checking
- Multi-agent orchestration (pipeline, handoff, delegation)
- Conversation history and context management
- Environment configuration via .env

**Metrics:**
- 169 tests passing (385 assertions)
- 99.4% pass rate
- 9 working examples
- Full PHP 8.3 type safety
- PHPStan level 9
- Complete DX tooling (Makefile, git hooks, etc.)

---

## v0.5.0 - Publishing & Enhanced Tools

### Publishing Tasks

**Priority: High - Make it public and installable**

- [ ] Set up GitHub Actions CI/CD workflow
  - PHP 8.3 matrix testing
  - Run Pest test suite
  - PHPStan static analysis
  - Code style checks with Pint
- [ ] Publish to Packagist
  - Register package as `helgesverre/pagent`
  - Enable automatic updates from GitHub
- [ ] Add README badges
  - Build status
  - Latest version
  - Downloads
  - License
- [ ] Create CONTRIBUTING.md
  - Development setup guide
  - Testing requirements
  - Code style guidelines
  - Pull request process
- [ ] Create architecture diagram
  - System overview
  - Component relationships
  - Data flow

### Enhanced Tool System

**Priority: High - Better developer experience**

Features to add:
- Tool timeout configuration
- Retry logic with exponential backoff
- Return type validation
- PHP attributes for descriptions
- Built-in tools (FileReader, WebFetcher, Calculator)
- Better error messages with suggestions

```php
// Example: Tool with advanced features
agent('bot')
    ->tool('fetch', 'Fetch data', fn(string $url) => /* ... */)
        ->timeout(5)
        ->retry(3)
        ->onError(fn($e) => 'Fetch failed');

// Example: Attributes for better documentation
#[Description('Get weather for a location')]
function getWeather(
    #[Param('City name')] string $city,
    #[Param('Include forecast')] bool $forecast = false
): string
```

### Quick Wins

Small improvements that add significant value:

1. ✅ **Better error messages** - Include suggestions for typos (COMPLETED)
2. ✅ **Add reset methods** - `clearTools()`, `clearGuards()`, `reset()` (COMPLETED)
3. ✅ **Agent cloning** - Duplicate configuration easily (COMPLETED)
4. ✅ **Conversation export/import** - Save and restore state (COMPLETED)
5. ✅ **Usage statistics** - Track tokens, calls, duration per agent (COMPLETED)
6. ✅ **Guard statistics** - Monitor how often guards trigger (COMPLETED)

---

## v0.6.0 - Observability & Monitoring

### OpenTelemetry Integration

**Priority: High - Production monitoring and debugging**

Implement comprehensive observability with OpenTelemetry spans/traces/events:

- Automatic tracing for all agent interactions
- Span creation for tool calls, guard checks, middleware execution
- Integration with observability platforms (Langfuse, Langsmith, Phoenix, etc.)
- Performance metrics and latency tracking
- Error tracking and debugging
- Distributed tracing across multi-agent workflows

```php
// Example: Automatic instrumentation
agent('bot')
    ->observability('langfuse', [
        'public_key' => '...',
        'secret_key' => '...',
        'trace_id' => 'session-123',
    ])
    ->prompt('Hello'); // Automatically traced

// Example: Custom spans
agent('bot')
    ->withSpan('custom-operation', function() {
        // Your code here
        return $result;
    });

// Example: Manual event logging
agent('bot')
    ->logEvent('user-feedback', [
        'rating' => 5,
        'comment' => 'Great response',
    ]);
```

**Features to implement:**
- OpenTelemetry SDK integration
- Automatic span creation for:
  - Agent prompt/response cycles
  - Tool execution
  - Guard validation
  - Middleware processing
  - Multi-agent handoffs/delegation
- Support for multiple backends:
  - Langfuse (https://langfuse.com)
  - Langsmith (https://www.langsmith.com)
  - Phoenix (https://phoenix.arize.com)
  - Generic OTLP exporters
- Metadata enrichment (user ID, session ID, tags)
- Cost tracking per trace
- Token usage tracking
- Error attribution and stack traces
- Performance profiling

**Reference:**
- Mistral AI Observability: https://docs.mistral.ai/guides/observability/#integrations
- OpenTelemetry PHP: https://opentelemetry.io/docs/languages/php/

---

## v0.7.0 - Memory & Streaming

### Memory & Persistence

- Persistent conversation storage (SQLite, Redis, MySQL)
- Conversation summarization for long contexts
- Context window management and intelligent pruning
- Session management with TTL
- Vector storage integration (Pinecone, Weaviate, Qdrant)
- RAG (Retrieval-Augmented Generation) support

### Streaming Support

- Server-Sent Events (SSE) for real-time output
- WebSocket integration
- Progress callbacks during execution
- Chunk-by-chunk processing
- Streaming tool results

---

## v0.8.0 - Advanced Patterns

### Agentic Reasoning Patterns

- ReAct pattern (Reasoning + Acting loop)
- Chain-of-Thought prompting with validation
- Tree of Thoughts for exploring multiple paths
- Plan-and-Execute for strategic task decomposition
- Reflection loops for self-improvement
- Self-critique and iterative refinement

### Advanced Orchestration

- Swarm pattern with multi-agent voting
- Conditional routing based on context
- Parallel agent execution
- State machines for complex workflows
- Agent priority and scheduling
- Resource allocation and load balancing

---

## v1.0.0 - Enterprise Ready

### Enterprise Features

- Cost tracking and budget enforcement
- Audit logging for compliance
- Health checks and monitoring endpoints
- Caching strategies (prompt caching, response caching)
- Fine-tuning integration for custom models
- A/B testing framework for agent variants
- Deployment strategies (canary, blue-green)
- Rate limiting and throttling
- Multi-tenancy support

### Developer Experience

- CLI tool for interactive development
- Web-based debugging UI
- Mock providers with scenario recording/replay
- Laravel package with first-class integration
- Symfony bundle
- Custom PHPUnit assertions for testing
- Performance profiling and benchmarks
- Documentation generator from code

---

## Technical Debt

### High Priority

- Fix PHPStan errors (~168 warnings in tests)
- Extract HTTP client to PSR-18 compatible class
- Add retry logic for API failures with backoff
- Improve error messages with context and suggestions
- Add request/response interceptors

### Medium Priority

- Add request timeout configuration per provider
- Implement intelligent response caching
- Add debug/verbose mode for troubleshooting
- Create comprehensive performance benchmarks
- Extract provider implementations to separate packages

### Low Priority

- Memory usage tracking and optimization
- Conversation history pruning strategies
- Provider failover mechanisms
- Comprehensive logging throughout framework

---

## Long-term Vision

### Community & Ecosystem

- Plugin system for custom providers
- Community tool registry
- Agent template marketplace
- Pre-built agents for common use cases:
  - Customer support agents
  - Research assistants
  - Code review agents
  - Data analysis agents
  - Content generation
- Example projects and starter kits
- Video tutorials and workshops

### Documentation

- Comprehensive API documentation
- Architecture decision records (ADRs)
- Real-world case studies
- Performance optimization guide
- Security best practices
- Migration guides between versions
- Internationalization support

---

## Recommended Next Steps

**This Week (4-6 hours):**
1. Set up GitHub Actions workflow (1 hour)
2. Publish to Packagist (1 hour)
3. Add badges and polish README (1 hour)
4. Create CONTRIBUTING.md (1 hour)
5. Create architecture diagram (2 hours)

**Next Week (6-8 hours):**
1. Tool timeout and retry logic (2-3 hours)
2. Built-in tools library (2-3 hours)
3. Better error messages (2 hours)

**Following Week (8-10 hours):**
1. Memory persistence layer (4-5 hours)
2. Streaming support foundation (4-5 hours)

---

**Current Version:** v0.4.0
**Next Milestone:** v0.5.0 (Publishing & Enhanced Tools)
**Target:** v1.0.0 in 40-60 hours of focused development

**Status:** Production ready, ready for public release
