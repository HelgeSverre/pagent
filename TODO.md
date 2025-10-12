# Pagent TODO - Prioritized Action Items

## ✅ Recently Completed

### v0.4.0 - SHIPPED! 🎉

- [x] Multi-Agent Orchestration (Pipeline, Handoff, Delegation)
- [x] Safety & Guards System (PIIGuard, ContentFilterGuard, PromptInjectionGuard)
- [x] Evaluation Framework (Dataset, Evaluator, Metrics, Reports)
- [x] Middleware System (Logging, RateLimit, Metrics)
- [x] Tool Validation (Automatic argument validation)
- [x] 9 Working Examples
- [x] 150+ tests passing

---

## 🔥 High Priority - Do Next

### 1. Quick Polish & Publishing (v0.5.0)

**Estimated**: 2-4 hours  
**Value**: Make it public and installable

**Tasks**:

- [ ] **GitHub Actions CI/CD**
  ```yaml
  .github/workflows/tests.yml
  - PHP 8.3
  - Run Pest tests
  - Run PHPStan analyse
  - Code formatting check
  ```
- [ ] **Publish to Packagist** - Register and enable auto-sync
- [ ] **Add badges to README** - Build status, version, downloads, license
- [ ] **Update README** - Add v0.3.0 features (guards, evaluation, middleware)
- [ ] **Create CONTRIBUTING.md** - Contribution guidelines

**Files to create**:

```
.github/workflows/tests.yml
CONTRIBUTING.md
```

---

### 2. Enhanced Tool System (v0.5.0)

**Estimated**: 4-6 hours  
**Value**: Better DX and error handling

**What to build**:

```php
agent('bot')
    ->tool('fetch', 'Fetch data', fn(string $url) => /* ... */)
        ->timeout(5) // seconds
        ->retry(3)
        ->onError(fn($e) => 'Fetch failed');

// Attributes for better descriptions
#[Description('Get weather for a location')]
function getWeather(
    #[Param('City name')] string $city,
    #[Param('Include forecast')] bool $forecast = false
): string
```

**Tasks**:

- [ ] Tool timeout support
- [ ] Retry logic for failed tools
- [ ] Return type validation
- [ ] Attribute support for descriptions
- [ ] Built-in tools (FileReader, WebFetcher, Calculator)
- [ ] Tool error handling with recovery

**Files to create**:

```
src/Tool/ToolConfig.php
src/Tool/BuiltInTools/
src/Attributes/Description.php
src/Attributes/Param.php
```

---

## ⚡ Quick Wins (30-60 min each)

Priority ordered:

1. [ ] **Better error messages** - Include suggestions

   ```php
   // "Tool 'calc' not found. Available: add, multiply, divide"
   ```

2. [ ] **Add clearTools() and clearGuards()** - Reset agent state

   ```php
   agent('bot')->clearTools()->clearGuards();
   ```

3. [ ] **Tool return type validation**

   ```php
   ->tool('get_age', 'Get age', fn(): int => "30") // RuntimeException!
   ```

4. [ ] **Add agent()->reset()** - Clear history and state

   ```php
   agent('bot')->reset(); // Clear messages, tools, guards
   ```

5. [ ] **Streaming support foundation** - Callback interface

   ```php
   agent('bot')->stream(fn($chunk) => echo $chunk);
   ```

6. [ ] **Add agent cloning** - Duplicate configuration
   ```php
   $bot2 = agent('bot1')->clone('bot2');
   ```

---

## 📋 Progress Tracking

### v0.1.0 ✅ (Foundation)

- [x] Core agent system
- [x] Multi-provider support
- [x] Conversation history
- [x] Basic testing

### v0.2.0 ✅ (Tool Calling)

- [x] Tool calling implementation
- [x] Anthropic & OpenAI integration
- [x] Automatic execution loop
- [x] 5 working examples

### v0.3.0 ✅ (Safety & Evaluation)

- [x] Safety guards system
- [x] Evaluation framework
- [x] Middleware pipeline
- [x] Tool validation
- [x] 134 tests passing

### v0.4.0 ✅ (Multi-Agent Orchestration)

- [x] Pipeline pattern
- [x] Handoff pattern
- [x] Delegation pattern
- [x] Error recovery
- [x] 150+ tests passing

### v0.5.0 (Next - Publishing & Tools)

- [ ] GitHub Actions
- [ ] Packagist publishing
- [ ] Architecture diagram
- [ ] Updated README
- [ ] Enhanced tool system

---

## 🎯 Suggested Next Session Plan

### Option A: Publish & Polish (Recommended for visibility)

**Time**: 2-4 hours

1. Set up GitHub Actions workflow
2. Publish to Packagist
3. Add badges and update README
4. Create architecture diagram
5. Share on social media

**Outcome**: Public, installable package

---

### Option B: Multi-Agent Features (Recommended for features)

**Time**: 8-12 hours

1. Implement Pipeline for sequential agents
2. Add agent handoff mechanism
3. Create delegation pattern
4. Write comprehensive tests
5. Create multi-agent examples

**Outcome**: Unique orchestration capabilities

---

### Option C: Enhanced Tools (Quick wins)

**Time**: 4-6 hours

1. Add tool timeout support
2. Implement retry logic
3. Return type validation
4. Built-in tools (file, web, calc)
5. Better error messages

**Outcome**: More robust tool system

---

## 🛠️ Technical Debt

### High Priority

- [ ] Fix remaining PHPStan errors (~168 warnings)
- [ ] Extract HTTP client to separate class
- [ ] Add retry logic for API failures
- [ ] Improve error messages throughout

### Medium Priority

- [ ] Add request timeout configuration
- [ ] Implement response caching
- [ ] Add debug/verbose mode
- [ ] Create performance benchmarks

### Low Priority

- [ ] Memory usage tracking
- [ ] Conversation history optimization
- [ ] Add more comprehensive logging

---

## 📊 Current Status

**What's Working** (v0.4.0):

- ✅ Core agent system with fluent API
- ✅ Multi-provider (Anthropic, OpenAI, Mock)
- ✅ Automatic tool calling & execution
- ✅ Safety guards (PII, content, prompt injection)
- ✅ Evaluation framework (datasets, metrics, reports)
- ✅ Middleware pipeline (logging, rate limiting, metrics)
- ✅ Tool validation (type checking, required args)
- ✅ Multi-agent orchestration (pipeline, handoff, delegation)
- ✅ Conversation history & context
- ✅ Environment configuration

**What's Next**:

- 🎯 Publishing to Packagist
- 🎯 Enhanced tool features
- 🎯 Memory & persistence
- 🎯 Streaming responses

**Test Coverage**: 150+ passing (99.3%)
**Production Ready**: YES ✅

---

## 🚀 Recommendation

**For maximum impact, do Option A (Publish) first**, then Option B (Multi-Agent).

This gets Pagent into developers' hands while you build the advanced features.

**Estimated time to v0.4.0**: 15-20 hours  
**Estimated time to v1.0.0**: 40-60 hours

Let's ship it! 🎉
