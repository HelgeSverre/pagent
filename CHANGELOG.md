# Changelog

All notable changes to Pagent will be documented in this file.

## [Unreleased]

## [0.3.0] - 2025-10-11

### 🛡️ Major Release: Safety Guards & Evaluation Framework

Complete safety system with guards, evaluation framework, and middleware pipeline.

### Added

- **Safety Guards System**:
  - `Guard` interface for custom guard implementations
  - `GuardException` with detailed context
  - Agent methods: `guard()`, `fallback()`, `getGuards()`
  - Automatic guard execution in prompt flow
  
- **Built-in Guards**:
  - `PIIGuard` - Detects SSN, credit cards, emails, phone numbers, IP addresses
  - `ContentFilterGuard` - Blocks profanity and harmful content
  - `PromptInjectionGuard` - Prevents prompt injection attacks
  - Support for closure-based guards
  
- **Evaluation Framework**:
  - `Evaluator` class for systematic agent testing
  - `Dataset` class with JSON/CSV/Array loaders
  - `EvaluationResult` with statistical analysis
  - `Report` class with HTML/JSON/Markdown export
  - Global `evaluate()` function
  
- **Built-in Metrics**:
  - `KeywordMetric` - Check for presence of keywords
  - `LengthMetric` - Validate response length
  - `SimilarityMetric` - Compare with expected output
  - Support for custom metrics via closures
  
- **Middleware System**:
  - `Middleware` interface for request/response handlers
  - Before/after hooks for every prompt
  - Agent methods: `middleware()`, `getMiddleware()`
  
- **Built-in Middleware**:
  - `LoggingMiddleware` - PSR-3 compatible logging
  - `RateLimitMiddleware` - Request throttling with configurable limits
  - `MetricsMiddleware` - Performance tracking and token counting
  
- **Enhanced Tool System**:
  - `ToolValidator` for automatic input validation
  - Type checking for tool arguments
  - Better error messages for validation failures
  
- **Examples**:
  - `examples/06-safety-guards.php` - Guard demonstrations
  - `examples/07-evaluation.php` - Evaluation framework usage
  - `examples/08-middleware.php` - Middleware examples
  - `examples/datasets/support_tickets.json` - Sample dataset

### Changed

- Tool execution now validates arguments before calling
- Agent.prompt() now runs middleware before and after provider calls
- Improved error messages for missing tools and guards

### Technical Details

- 42 new tests added (all passing)
- 100% test pass rate maintained
- Full type safety with PHP 8.3
- PSR-3 logging compatibility
- Zero external dependencies (except PSR interfaces)

## [0.2.0] - 2025-10-11

### 🎉 Major Release: Automatic Tool/Function Calling

Complete implementation of tool calling with both Anthropic and OpenAI providers.

### Added

- **Automatic Tool Execution**:
  - Multi-turn conversation loop with tool calls
  - Detects tool calls in LLM responses
  - Executes tools and continues conversation with results
  - Works seamlessly with both Anthropic and OpenAI
  
- **Tool/Function Calling System**:
  - `Tool` class for creating tools from PHP closures
  - `ToolArgument` class with automatic type inference
  - Schema generation for both Anthropic (`input_schema`) and OpenAI (`parameters`)
  - Agent methods: `tool()`, `getTools()`, `executeTool()`
  
- **Provider Enhancements**:
  - Anthropic: Tool use API with `tool_result` blocks
  - OpenAI: Function calling with `tool_calls` messages
  - Both providers return structured `tool_calls` array
  
- **Examples Directory**: 5 comprehensive working demos
  - `examples/01-basic-chat.php` - Conversations with different providers
  - `examples/02-tool-calling.php` - Automatic tool execution ⭐
  - `examples/03-context-memory.php` - Context tracking
  - `examples/04-multi-provider.php` - Provider comparison
  - `examples/05-complete-demo.php` - Full feature demonstration
  
- **Infrastructure**:
  - `.env` support via vlucas/phpdotenv
  - `.env.example` with placeholder API keys
  - Pest v4 upgrade (v2.36 → v4.1.2)
  
- **Documentation**:
  - `ROADMAP.md` - Future features prioritized
  - `NEXT_STEPS.md` - Actionable development guide
  - `AGENTS.md` - AI coding assistant instructions
  - `examples/README.md` - Example documentation

### Changed

- **Default Models**: Updated to latest Claude versions
  - `claude-sonnet-4-20250514` (from `claude-3-sonnet-20240229`)
- **Providers**: Check `$_ENV` before `getenv()` for dotenv v5 compatibility
- **Agent.prompt()**: Now includes automatic tool execution loop
- **`.gitignore`**: Added vendor, .env, .DS_Store

### Fixed

- Environment variable loading in test suite
- Provider API key detection from .env
- Pest v4 compatibility issues
- Test helpers for better tool testing

### Technical Details

- Uses PHP 8.3 `ReflectionFunction` for parameter introspection
- Supported types: string, int, float, bool, array
- Handles nullable (`?type`) and default values
- Generates provider-specific JSON schemas automatically
- **Test Coverage**: 75 tests, 181 assertions, 100% pass rate

## [0.1.0] - Initial Release

### Added

- Core agent system with fluent API
- Multiple LLM provider support (Anthropic, OpenAI)
- Mock provider for testing
- Conversation history tracking
- Global helper functions
