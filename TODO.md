# Pagent TODO - Prioritized Action Items

## 🔥 High Priority - Do Next

### 1. Safety & Guards System (v0.3.0)
**Estimated**: 6-8 hours  
**Value**: Production-critical

**What to build**:
```php
agent('bot')
    ->guard('pii', PIIGuard::class)
    ->guard('content', fn($output) => !str_contains($output, 'forbidden'))
    ->fallback(fn($error) => "I cannot help with that.");
```

**Tasks**:
- [ ] Create `src/Contracts/Guard.php` interface
- [ ] Create `src/Guards/PIIGuard.php` (SSN, credit cards, emails)
- [ ] Create `src/Guards/ContentFilterGuard.php` (profanity, harmful content)
- [ ] Add `guard()` and `fallback()` methods to Agent
- [ ] Execute guards before returning responses
- [ ] Write 10+ tests for guard functionality

**Files**:
```
src/Contracts/Guard.php
src/Guards/PIIGuard.php
src/Guards/ContentFilterGuard.php  
src/Guards/PromptInjectionGuard.php
src/Exceptions/GuardException.php
tests/Unit/Guards/
```

---

### 2. Evaluation Framework (v0.3.0)
**Estimated**: 8-10 hours  
**Value**: Differentiation factor

**What to build**:
```php
evaluate('support-bot')
    ->dataset('tests/datasets/tickets.json')
    ->metric('accuracy', fn($output, $expected) => similar_text($output, $expected))
    ->metric('contains_keywords', fn($output) => /* check keywords */)
    ->export('reports/evaluation.html');
```

**Tasks**:
- [ ] Create `src/Evaluation/Evaluator.php`
- [ ] Create `src/Evaluation/Dataset.php` (JSON/CSV loader)
- [ ] Create `src/Evaluation/Metric.php` interface
- [ ] Built-in metrics: keyword matching, length, sentiment
- [ ] Report generation (HTML/JSON/Markdown)
- [ ] CLI: `./vendor/bin/pagent evaluate agent-name`

**Files**:
```
src/Evaluation/Evaluator.php
src/Evaluation/Dataset.php
src/Evaluation/Metrics/KeywordMetric.php
src/Evaluation/Metrics/LengthMetric.php
src/Evaluation/Report.php
bin/pagent
```

---

### 3. Middleware System (v0.4.0)
**Estimated**: 4-6 hours  
**Value**: Enables extensibility

**What to build**:
```php
agent('bot')
    ->middleware([
        LoggingMiddleware::class,
        RateLimitMiddleware::class,
    ]);
```

**Tasks**:
- [ ] Create `src/Contracts/Middleware.php`
- [ ] Add middleware pipeline to Agent
- [ ] Built-in: LoggingMiddleware, RateLimitMiddleware
- [ ] Before/after hooks
- [ ] Middleware ordering

---

## ⚡ Quick Wins (30-60 min each)

Priority ordered:

1. [ ] **GitHub Actions** - Automated testing
   ```yaml
   .github/workflows/tests.yml
   - PHP 8.3
   - Run Pest
   - Run PHPStan
   ```

2. [ ] **Packagist Publishing** - Make installable
   - Register on packagist.org
   - Set up auto-sync with GitHub

3. [ ] **Add tool timeout** - Prevent hanging
   ```php
   ->tool('slow', 'Slow tool', fn() => /* ... */)
     ->timeout(5); // seconds
   ```

4. [ ] **Return type validation** - Type safety
   ```php
   // Throw if tool returns wrong type
   ->tool('get_age', 'Get age', fn(): int => "30") // Error!
   ```

5. [ ] **Add `clearTools()` method** - Reset agent tools
   ```php
   agent('bot')->clearTools();
   ```

6. [ ] **Better error messages** - More actionable
   ```php
   // Instead of: "Tool 'unknown' not found"
   // Show: "Tool 'unknown' not found. Available tools: calculate, get_weather"
   ```

---

## 📊 Progress Tracking

### v0.2.0 ✅
- [x] Tool calling implementation
- [x] Anthropic & OpenAI integration
- [x] Automatic execution loop
- [x] Working examples
- [x] Documentation

### v0.2.1 (Next)
- [ ] GitHub Actions CI/CD
- [ ] Packagist publishing
- [ ] Architecture diagram
- [ ] Video demo

### v0.3.0 (Target: 2 weeks)
- [ ] Safety guards
- [ ] Evaluation framework
- [ ] Enhanced error handling
- [ ] Production hardening

---

## 🎯 Suggested Next Session Plan

### Session Goal: Safety First (v0.3.0-alpha)

**Hour 1: Guards Foundation**
- Create Guard interface
- Implement PIIGuard
- Add guard() method to Agent

**Hour 2: Guard Execution**
- Execute guards in prompt flow
- Add fallback mechanism
- Write tests

**Hour 3: Built-in Guards**
- ContentFilterGuard
- PromptInjectionGuard
- Guard configuration options

**Hour 4: Testing & Polish**
- Comprehensive test suite
- Example with guards
- Documentation

**Deliverable**: Working guard system with 3 built-in guards

---

## 📁 Current File Structure

```
pagent/
├── src/
│   ├── Contracts/
│   │   └── Provider.php
│   ├── Providers/
│   │   ├── Anthropic.php ✨ (enhanced)
│   │   ├── OpenAI.php ✨ (enhanced)
│   │   └── Mock.php
│   ├── Tool/ ⭐ NEW
│   │   ├── Tool.php
│   │   └── ToolArgument.php
│   ├── Agent.php ✨ (enhanced)
│   ├── AgentBuilder.php
│   ├── Registry.php
│   └── functions.php
├── tests/
│   ├── Unit/ (51 tests)
│   └── Integration/ (24 tests)
├── examples/ ⭐ NEW
│   ├── 01-basic-chat.php
│   ├── 02-tool-calling.php
│   ├── 03-context-memory.php
│   ├── 04-multi-provider.php
│   ├── 05-complete-demo.php
│   └── README.md
├── ROADMAP.md ✨
├── NEXT_STEPS.md ✨
├── CHANGELOG.md ✨
├── AGENTS.md
└── README.md ✨
```

---

## 🏆 Achievements Unlocked

- ✅ **Tool Calling**: Working end-to-end with real APIs
- ✅ **Type Safety**: Full PHP 8.3 reflection-based inference
- ✅ **Multi-Provider**: Seamless Anthropic & OpenAI support
- ✅ **Test Coverage**: 100% pass rate on 75 tests
- ✅ **Documentation**: Comprehensive guides and examples
- ✅ **Latest Models**: claude-sonnet-4-20250514

---

## 🚀 Ready for Next Phase!

**Current version**: v0.2.0  
**Next milestone**: v0.3.0 (Safety & Evaluation)  
**Status**: All systems go! 🎉
