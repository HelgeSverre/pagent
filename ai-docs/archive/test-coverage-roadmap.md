# Consolidated Test Coverage Implementation Plan (Revised)

**Generated:** 2025-10-28
**Revised:** 2025-10-28 (Updated for real Claude Code capabilities)
**Coverage Goal:** 85% → 95% (Overall), Tools 65% → 90%
**Total New Tests:** 112 (25 from core analysis + 87 from tools analysis)
**Estimated Effort:** 30-40 hours across 4 weeks

---

## Executive Summary

This consolidated plan merges two comprehensive test coverage analyses:

1. **Core Analysis** - 25 tests for Agent, Providers, Orchestration, Workflows
2. **Tools Analysis** - 87 tests for Tools component (src/Tools/, src/Tool/)

Combined, these improvements will raise overall coverage from **85% to 95%** and eliminate critical security and reliability gaps.

**Important:** This plan accounts for PHP/Pest testing constraints (sequential execution due to SQLite database locking) and uses real Claude Code agent invocation patterns.

---

## Coverage Gap Summary

| Component                 | Current | Target | Priority    | Tests Needed |
| ------------------------- | ------- | ------ | ----------- | ------------ |
| **ToolArgument**          | 0%      | 100%   | 🔴 CRITICAL | 13           |
| **Tool Security**         | 45%     | 95%    | 🔴 CRITICAL | 25           |
| **Provider Unit Tests**   | 20%     | 85%    | 🔴 CRITICAL | 12           |
| **Core Agent Edge Cases** | 75%     | 95%    | 🟡 HIGH     | 15           |
| **Orchestration**         | 85%     | 95%    | 🟡 HIGH     | 12           |
| **Built-in Tools**        | 70%     | 90%    | 🟢 MEDIUM   | 35           |

---

## Claude Code Agent Strategy

### Available Agent Invocation Methods

This plan uses **natural language invocation** with the general-purpose Claude Code agent. Examples:

```
"Write comprehensive Pest tests for ToolArgument class at src/Tool/ToolArgument.php"
"Implement security tests for Bash tool in tests/Unit/Tools/BashTest.php"
"Add provider error handling tests to tests/Unit/Providers/AnthropicTest.php"
```

### Optional: Custom Subagent Creation

For reusable workflows, you can create custom subagents in `.claude/agents/`:

**Example: `.claude/agents/php-test-writer.md`**

```markdown
---
name: php-test-writer
description: PHP test specialist for Pest/PHPUnit. Generates comprehensive unit tests.
tools: Read, Grep, Glob, Bash, Write, Edit
model: sonnet
---

You are an expert PHP test engineer specializing in Pest testing framework.

## Your Mission

Write comprehensive, production-ready PHP tests using Pest syntax that:

- Follow existing test patterns in the codebase
- Achieve high code coverage
- Test edge cases and error conditions
- Use proper mocking for external dependencies
- Include clear descriptions using `it()` syntax

## Approach

1. Read the source file to understand implementation
2. Check existing test patterns in tests/ directory
3. Write tests following Pest best practices
4. Run tests to verify they pass: `./vendor/bin/pest <test-file>`
5. Report coverage results

## PHP/Pest Constraints

- Tests MUST run sequentially (SQLite database locking)
- Never run tests in parallel
- Use `./vendor/bin/pest tests/Unit/...` for specific files
- Always verify tests pass before marking complete
```

**Invocation:** "Use the php-test-writer subagent to implement ToolArgument tests"

---

## PHP/Pest Testing Constraints

### ⚠️ CRITICAL: Sequential Test Execution Required

**Why:** SQLite database locking prevents parallel test execution in PHPUnit/Pest.

**What CAN run in parallel:**

- Static analysis: `vendor/bin/phpstan analyze src/`
- Code style checks: `vendor/bin/phpcs src/`
- Documentation generation
- File search/grep operations

**What MUST run sequentially:**

- All Pest/PHPUnit test files (`.php` files in `tests/`)
- Coverage report generation
- Integration tests with database

### Real Test Execution Commands

```bash
# Run specific test file (preferred during development)
./vendor/bin/pest tests/Unit/Tool/ToolArgumentTest.php

# Run all tests in directory
./vendor/bin/pest tests/Unit/Tools/

# Run with coverage
./vendor/bin/pest --coverage

# Run with minimum coverage threshold
./vendor/bin/pest --coverage --min=90

# Composer shortcuts
composer test                # Run all tests
composer test:coverage       # Generate coverage report

# Static analysis (CAN be parallel)
vendor/bin/phpstan analyze src/Tools/
vendor/bin/phpcs src/Tool/
```

---

## Phase 1: Critical Security & Foundation (Week 1)

**Priority:** 🔴 CRITICAL
**Estimated Effort:** 10-12 hours
**Execution Strategy:** Static analysis in parallel, then tests sequentially

### Pre-Phase: Setup (Optional)

If using custom subagents, create:

```bash
# Create custom subagents directory (if not exists)
mkdir -p .claude/agents

# Create php-test-writer.md (see template above)
# Create php-test-reviewer.md
# Create php-security-auditor.md
```

---

### 1.1 ToolArgument Complete Coverage (CRITICAL - 0% → 100%)

**Task:** Write comprehensive unit tests for ToolArgument class
**Location:** `tests/Unit/Tool/ToolArgumentTest.php` (NEW FILE)
**Tests:** 13 test cases

**Implementation Request:**

> Write comprehensive Pest tests for the ToolArgument class at `src/Tool/ToolArgument.php`. This is CRITICAL - the class currently has 0% test coverage and is used by ALL tools for LLM integration.
>
> Test file: `tests/Unit/Tool/ToolArgumentTest.php`
>
> Required test cases:
>
> - Type conversion: PHP types → JSON schema types (int→integer, float→number, etc.)
> - Description inclusion/omission in JSON schema
> - Constructor parameter handling
> - Nullable type handling
> - Default value validation
> - Union type conversion (PHP 8.0+)
> - Array types with items schema
> - Object types with properties
> - stdClass to object conversion
> - Unknown type defaulting to string
> - Mixed type handling
> - Enum value validation
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tool/ToolArgumentTest.php`

**Rationale:** ToolArgument is used by ALL tools for LLM integration. Zero coverage is unacceptable.

---

### 1.2 Tool Call Infinite Loop Protection (CRITICAL)

**Task:** Add infinite loop protection tests to Agent
**Location:** `tests/Unit/AgentTest.php`
**Tests:** 3 test cases

**Implementation Request:**

> Add infinite loop protection tests to `tests/Unit/AgentTest.php` for the Agent class.
>
> Required test cases:
>
> - Prevent infinite tool call loops (max depth exceeded)
> - Handle tool removal during execution gracefully
> - Detect circular tool call chains
>
> After implementation, run: `./vendor/bin/pest tests/Unit/AgentTest.php --filter="infinite loop"`

**Rationale:** Prevents resource exhaustion and system hangs in production.

---

### 1.3 Bash Security Tests (CRITICAL)

**Task:** Implement comprehensive security tests for Bash tool
**Location:** `tests/Unit/Tools/BashTest.php`
**Tests:** 8 test cases

**Implementation Request:**

> Add security tests to `tests/Unit/Tools/BashTest.php` for the Bash tool. This tool executes arbitrary commands, so security is paramount.
>
> Required test cases:
>
> - Timeout enforcement for long-running commands
> - Special character handling (pipes, quotes)
> - Working directory respect
> - Non-zero exit code handling
> - Command argument validation
> - Prevent commands with allowed prefix but different base
> - Multi-line output handling
> - Shell injection prevention via arguments
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/BashTest.php`

**Rationale:** Bash tool executes arbitrary commands - security is paramount.

---

### 1.4 WebFetch Security Tests (CRITICAL)

**Task:** Implement SSRF protection tests for WebFetch
**Location:** `tests/Unit/Tools/WebFetchTest.php`
**Tests:** 12 test cases

**Implementation Request:**

> Add comprehensive SSRF protection tests to `tests/Unit/Tools/WebFetchTest.php`.
>
> Required test cases:
>
> - Block 10.0.0.0/8 private range
> - Block 172.16.0.0/12 private range
> - Block 192.168.0.0/16 private range
> - Block link-local addresses (169.254.x.x)
> - Block localhost/127.0.0.1
> - Allow public IPs
> - HTTP redirect handling (max redirects)
> - Custom header validation and sanitization
> - Header injection prevention (newlines)
> - Timeout enforcement
> - Response size limit enforcement (maxSize)
> - DNS resolution failure handling
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/WebFetchTest.php`

**Rationale:** WebFetch makes external HTTP requests - comprehensive SSRF protection is critical.

---

### 1.5 Provider Error Handling (CRITICAL)

**Task:** Add provider error handling tests
**Location:** `tests/Unit/Providers/AnthropicTest.php`, `tests/Unit/Providers/OpenAITest.php`
**Tests:** 9 test cases (6 Anthropic + 3 OpenAI)

**Implementation Request:**

> Add error handling tests to provider test files. Provider failures cause cascading errors and must be handled gracefully.
>
> **Anthropic tests** (`tests/Unit/Providers/AnthropicTest.php`):
>
> - 401 authentication errors
> - 429 rate limit errors
> - 500 server errors
> - Connection timeout
> - Unknown content block types
> - Malformed API responses
>
> **OpenAI tests** (`tests/Unit/Providers/OpenAITest.php`):
>
> - Malformed tool call arguments (invalid JSON)
> - response_format option pass-through
> - seed option pass-through
>
> Run tests separately (SQLite locking):
>
> ```bash
> ./vendor/bin/pest tests/Unit/Providers/AnthropicTest.php
> ./vendor/bin/pest tests/Unit/Providers/OpenAITest.php
> ```

**Rationale:** Provider failures cause cascading errors - must handle gracefully.

---

### Phase 1 Execution Strategy

```
┌─────────────────────────────────────────────────────────┐
│ PARALLEL: Static Analysis (No Database)                │
├─────────────────────────────────────────────────────────┤
│ • PHPStan analysis on src/Tool/                        │
│ • PHPStan analysis on src/Tools/                       │
│ • PHPStan analysis on src/Providers/                   │
│ • PHPCS code style checks                              │
└─────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Test Implementation & Execution            │
├─────────────────────────────────────────────────────────┤
│ 1. Implement ToolArgumentTest.php                      │
│    → Run: ./vendor/bin/pest tests/Unit/Tool/          │
│    → Verify: All 13 tests pass                         │
│                                                         │
│ 2. Implement Agent infinite loop tests                 │
│    → Run: ./vendor/bin/pest tests/Unit/AgentTest.php  │
│    → Verify: New tests pass                            │
│                                                         │
│ 3. Implement BashTest.php security tests               │
│    → Run: ./vendor/bin/pest tests/Unit/Tools/BashTest │
│    → Verify: All 8 security tests pass                 │
│                                                         │
│ 4. Implement WebFetchTest.php SSRF tests               │
│    → Run: ./vendor/bin/pest tests/Unit/Tools/WebFetch │
│    → Verify: All 12 SSRF tests pass                    │
│                                                         │
│ 5. Implement Anthropic provider tests                  │
│    → Run: ./vendor/bin/pest tests/Unit/Providers/     │
│    → Verify: 6 error handling tests pass               │
│                                                         │
│ 6. Implement OpenAI provider tests                     │
│    → Run: ./vendor/bin/pest tests/Unit/Providers/     │
│    → Verify: 3 additional tests pass                   │
└─────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────┐
│ FINAL: Coverage Report                                  │
├─────────────────────────────────────────────────────────┤
│ composer test:coverage                                  │
│ Verify: ToolArgument 0% → 100%                         │
│ Verify: Tools security 45% → 70%                       │
└─────────────────────────────────────────────────────────┘

Total: 45 critical tests | Sequential execution required
```

---

## Phase 2: High-Value Error Handling (Week 2)

**Priority:** 🟡 HIGH
**Estimated Effort:** 8-10 hours
**Execution Strategy:** Sequential test implementation

### 2.1 Tool Validation Edge Cases

**Task:** Add edge case tests for ToolValidator
**Location:** `tests/Unit/Tool/ToolValidatorTest.php`
**Tests:** 8 test cases

**Implementation Request:**

> Add edge case tests to `tests/Unit/Tool/ToolValidatorTest.php` for robust type validation.
>
> Required test cases:
>
> - Mixed array keys (associative + indexed)
> - Mixed array with missing required parameters (should throw)
> - Empty arrays when all parameters optional
> - Empty array when parameters required (should throw)
> - Int accepted for float parameter (type coercion)
> - String rejected for int parameter
> - Any type accepted for untyped/mixed parameters
> - Nested object parameter validation
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tool/ToolValidatorTest.php`

---

### 2.2 DataExtract Comprehensive Tests

**Task:** Add comprehensive tests for DataExtract tool
**Location:** `tests/Unit/Tools/DataExtractTest.php`
**Tests:** 8 test cases

**Implementation Request:**

> Add comprehensive tests to `tests/Unit/Tools/DataExtractTest.php`. This tool integrates with OpenAI for structured data extraction.
>
> Required test cases:
>
> - Schema structure validation (nested properties)
> - Schema missing type field (should reject)
> - Schema missing properties field (should reject)
> - Custom instructions in prompt
> - Default instructions when not provided
> - Malformed JSON responses (should throw)
> - Parsed data on valid JSON
> - Correct model configuration pass-through
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/DataExtractTest.php`

**Rationale:** DataExtract integrates with OpenAI - JSON parsing and schema validation critical.

---

### 2.3 Grep Error Handling

**Task:** Add error handling tests for Grep tool
**Location:** `tests/Unit/Tools/GrepTest.php`
**Tests:** 7 test cases

**Implementation Request:**

> Add error handling tests to `tests/Unit/Tools/GrepTest.php`. User-provided regex can crash the application if not handled properly.
>
> Required test cases:
>
> - Invalid regex pattern handling
> - Binary file handling
> - Very long lines (100k+ chars)
> - maxResults limit respect
> - Stop searching after maxResults reached
> - Unreadable file handling
> - Skip unreadable files without crashing
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/GrepTest.php`

**Rationale:** User-provided regex can crash application - must handle invalid patterns.

---

### 2.4 PdfReader External Command Tests

**Task:** Add external command execution tests for PdfReader
**Location:** `tests/Unit/Tools/PdfReaderTest.php`
**Tests:** 6 test cases

**Implementation Request:**

> Add external command tests to `tests/Unit/Tools/PdfReaderTest.php`. This tool shells out to `pdftotext` binary.
>
> Required test cases:
>
> - Validate pdftotext is installed (throw when not found)
> - Corrupted PDF handling (should throw)
> - Empty PDF handling
> - File size limit enforcement (maxSize)
> - Custom pdftotext path usage
> - Extracted text with correct metadata
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/PdfReaderTest.php`

**Rationale:** Shells out to external binary - command execution and validation critical.

---

### 2.5 Guard Execution Order & Behavior

**Task:** Add guard execution order tests
**Location:** `tests/Unit/AgentTest.php`
**Tests:** 5 test cases

**Implementation Request:**

> Add guard execution order tests to `tests/Unit/AgentTest.php`.
>
> Required test cases:
>
> - Stop guard execution at first violation (short-circuit)
> - Don't call subsequent guards after failure
> - Empty response content handling
> - Null response content handling
> - Don't add empty content to history
>
> After implementation, run: `./vendor/bin/pest tests/Unit/AgentTest.php --filter="guard"`

---

### Phase 2 Execution Strategy

```
┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Test Implementation (SQLite Locking)       │
├─────────────────────────────────────────────────────────┤
│ 1. ToolValidatorTest.php → Run & verify (8 tests)     │
│ 2. DataExtractTest.php → Run & verify (8 tests)       │
│ 3. GrepTest.php → Run & verify (7 tests)              │
│ 4. PdfReaderTest.php → Run & verify (6 tests)         │
│ 5. AgentTest.php guards → Run & verify (5 tests)      │
└─────────────────────────────────────────────────────────┘

Total: 34 tests | Must run sequentially
```

---

## Phase 3: Tools Edge Cases & Robustness (Week 3)

**Priority:** 🟢 MEDIUM
**Estimated Effort:** 10-12 hours
**Execution Strategy:** Sequential implementation

### 3.1 FileRead Edge Cases

**Task:** Add edge case tests for FileRead tool
**Location:** `tests/Unit/Tools/FileReadTest.php`
**Tests:** 6 test cases

**Implementation Request:**

> Add edge case tests to `tests/Unit/Tools/FileReadTest.php`.
>
> Required test cases:
>
> - Follow symlinks to read target file
> - Prevent symlink traversal outside baseDir
> - Read from /dev/null without errors (Unix)
> - Reject reading directories
> - Handle files with exactly maxSize bytes
> - Reject file with maxSize + 1 bytes
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/FileReadTest.php`

---

### 3.2 FileWrite Edge Cases

**Task:** Add edge case tests for FileWrite tool
**Location:** `tests/Unit/Tools/FileWriteTest.php`
**Tests:** 6 test cases

**Implementation Request:**

> Add edge case tests to `tests/Unit/Tools/FileWriteTest.php`.
>
> Required test cases:
>
> - Non-writable directory (should throw)
> - Parent directory creation failure
> - Overwrite file created by another process
> - Create empty file when content is empty string
> - Normalize paths with multiple consecutive slashes
> - Handle concurrent writes safely
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/FileWriteTest.php`

---

### 3.3 Glob Pattern Matching

**Task:** Add pattern matching tests for Glob tool
**Location:** `tests/Unit/Tools/GlobTest.php`
**Tests:** 5 test cases

**Implementation Request:**

> Add pattern matching tests to `tests/Unit/Tools/GlobTest.php`.
>
> Required test cases:
>
> - Skip unreadable directories without crashing
> - Match multiple extensions with braces (\*.{php,js})
> - Find files in deeply nested directories
> - Respect directory prefix in \*\* pattern
> - Handle patterns with prefix paths
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/GlobTest.php`

---

### 3.4 Tools Base Class Schema Generation

**Task:** Test abstract tool schema generation
**Location:** `tests/Unit/Tools/AbstractToolTest.php` (NEW FILE)
**Tests:** 4 test cases

**Implementation Request:**

> Create `tests/Unit/Tools/AbstractToolTest.php` to test schema generation in the abstract tool base class.
>
> Required test cases:
>
> - Generate correct Anthropic schema from parameters method
> - Generate Anthropic schema with empty parameters
> - Generate correct OpenAI schema with function wrapper
> - Generate OpenAI schema structure correctly
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tools/AbstractToolTest.php`

---

### 3.5 Tool::fromClosure Edge Cases

**Task:** Add edge case tests for Tool::fromClosure
**Location:** `tests/Unit/Tool/ToolTest.php`
**Tests:** 5 test cases

**Implementation Request:**

> Add edge case tests to `tests/Unit/Tool/ToolTest.php` for the Tool::fromClosure method.
>
> Required test cases:
>
> - Create tool from parameterless closure
> - Handle closures with union types (int|float)
> - Extract type from union type parameters
> - Detect nullable from question mark syntax (?Type)
> - Handle closures with default values
>
> After implementation, run: `./vendor/bin/pest tests/Unit/Tool/ToolTest.php`

---

### Phase 3 Execution Strategy

```
┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Test Implementation (SQLite Locking)       │
├─────────────────────────────────────────────────────────┤
│ 1. FileReadTest.php → Run & verify (6 tests)          │
│ 2. FileWriteTest.php → Run & verify (6 tests)         │
│ 3. GlobTest.php → Run & verify (5 tests)              │
│ 4. AbstractToolTest.php → Run & verify (4 tests)      │
│ 5. ToolTest.php fromClosure → Run & verify (5 tests)  │
└─────────────────────────────────────────────────────────┘

Total: 26 tests | Must run sequentially
```

---

## Phase 4: Integration & Complex Scenarios (Week 4)

**Priority:** 🟡 HIGH
**Estimated Effort:** 8-10 hours
**Execution Strategy:** Sequential integration tests

### 4.1 Orchestration Complex Scenarios

**Task:** Add complex orchestration tests
**Location:** `tests/Integration/OrchestrationTest.php`
**Tests:** 8 test cases

**Implementation Request:**

> Add complex scenario tests to `tests/Integration/OrchestrationTest.php` for pipeline, delegation, and handoff patterns.
>
> **Pipeline tests:**
>
> - Transform function exceptions with error handler
> - Throw transform exceptions without error handler
> - Empty pipeline handling
>
> **Delegation tests:**
>
> - Multiple supervisor feedback rounds (3+ rejections)
> - Worker tool usage during delegation
> - Tool results in supervisor review
>
> **Handoff tests:**
>
> - Circular handoff chains (A→B→A)
> - Handoff chain in context
>
> After implementation, run: `./vendor/bin/pest tests/Integration/OrchestrationTest.php`

---

### 4.2 Tool Chaining Integration Tests

**Task:** Create tool chaining integration tests
**Location:** `tests/Integration/ToolChainingTest.php` (NEW FILE)
**Tests:** 5 test cases

**Implementation Request:**

> Create `tests/Integration/ToolChainingTest.php` to test tool chaining scenarios.
>
> Required test cases:
>
> - Chain file write, bash processing, and file read
> - Combine glob and grep for project-wide search
> - Generate consistent schemas for multiple tools
> - Maintain conversation history through multiple tool calls
> - Handle tool execution with mixed providers
>
> After implementation, run: `./vendor/bin/pest tests/Integration/ToolChainingTest.php`

---

### 4.3 Provider-Specific Behavior

**Task:** Add provider-specific behavior tests
**Location:** `tests/Integration/ProviderFeaturesTest.php`
**Tests:** 6 test cases

**Implementation Request:**

> Create `tests/Integration/ProviderFeaturesTest.php` to test provider-specific formatting and behavior.
>
> Required test cases:
>
> - Format tool calls correctly for Anthropic (raw_content array)
> - Format tool calls correctly for OpenAI (tool_calls structure)
> - Format tool results correctly for Anthropic
> - Format tool results correctly for OpenAI
> - Support mixed providers in pipeline
> - Verify each stage used different provider
>
> After implementation, run: `./vendor/bin/pest tests/Integration/ProviderFeaturesTest.php`

---

### 4.4 Middleware & Guards Integration

**Task:** Create middleware and guards integration tests
**Location:** `tests/Integration/MiddlewareGuardTest.php` (NEW FILE)
**Tests:** 4 test cases

**Implementation Request:**

> Create `tests/Integration/MiddlewareGuardTest.php` to test middleware and guard interaction.
>
> Required test cases:
>
> - Handle guard violations with middleware correctly
> - Call middleware before() but not after() on guard failure
> - Run middleware after() on successful guard checks
> - Preserve middleware state across guard checks
>
> After implementation, run: `./vendor/bin/pest tests/Integration/MiddlewareGuardTest.php`

---

### 4.5 Conversation & History Edge Cases

**Task:** Add conversation history edge case tests
**Location:** `tests/Unit/AgentTest.php`
**Tests:** 5 test cases

**Implementation Request:**

> Add conversation history edge case tests to `tests/Unit/AgentTest.php`.
>
> Required test cases:
>
> - Handle various invalid conversation JSON formats
> - Validate message structure in imported conversations
> - Handle large message history efficiently (100+ messages)
> - Maintain memory usage below threshold with large history
> - Complete 100-turn conversation in reasonable time
>
> After implementation, run: `./vendor/bin/pest tests/Unit/AgentTest.php --filter="conversation"`

---

### Phase 4 Execution Strategy

```
┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Integration Tests (SQLite Locking)         │
├─────────────────────────────────────────────────────────┤
│ 1. OrchestrationTest.php → Run & verify (8 tests)     │
│ 2. ToolChainingTest.php → Run & verify (5 tests)      │
│ 3. ProviderFeaturesTest.php → Run & verify (6 tests)  │
│ 4. MiddlewareGuardTest.php → Run & verify (4 tests)   │
│ 5. AgentTest.php conversation → Run & verify (5 tests)│
└─────────────────────────────────────────────────────────┘

Total: 28 tests | Must run sequentially
```

---

## Phase 5: Remaining Coverage & Documentation (Optional)

**Priority:** 🟢 LOW
**Estimated Effort:** 6-8 hours

### Untested Logic Paths

- IP address detection in PIIGuard
- Content filter strict mode behavior
- Provider config passing through builder
- Empty pipeline/chain execution

**Note:** These are lower priority - implement if time permits.

---

## Multi-Agent Execution Strategy (Revised)

### ⚠️ Important Constraint: SQLite Database Locking

PHP/Pest tests **MUST run sequentially** due to SQLite database locking. Only static analysis can run in parallel.

### Week 1: Critical Security (Phase 1)

```
┌─────────────────────────────────────────────────────────┐
│ PARALLEL: Static Analysis (No Database)                │
├─────────────────────────────────────────────────────────┤
│ Work on these in parallel:                             │
│ 1. Run PHPStan analysis on src/Tools/                  │
│ 2. Run PHPStan analysis on src/Tool/                   │
│ 3. Run PHPStan analysis on src/Providers/              │
│ 4. Run PHP_CodeSniffer on entire src/ directory        │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Test Implementation (Database Access)      │
├─────────────────────────────────────────────────────────┤
│ Task 1: Implement ToolArgument tests (13 tests)       │
│   → Run: ./vendor/bin/pest tests/Unit/Tool/           │
│   → Verify: All tests pass                             │
│                                                         │
│ Task 2: Implement infinite loop protection (3 tests)   │
│   → Run: ./vendor/bin/pest tests/Unit/AgentTest.php   │
│   → Verify: New tests pass                             │
│                                                         │
│ Task 3: Implement Bash security (8 tests)             │
│   → Run: ./vendor/bin/pest tests/Unit/Tools/BashTest  │
│   → Verify: All security tests pass                    │
│                                                         │
│ Task 4: Implement WebFetch SSRF (12 tests)            │
│   → Run: ./vendor/bin/pest tests/Unit/Tools/WebFetch  │
│   → Verify: All SSRF tests pass                        │
│                                                         │
│ Task 5: Implement Provider errors (9 tests)            │
│   → Run: ./vendor/bin/pest tests/Unit/Providers/      │
│   → Verify: Error handling works                       │
└─────────────────────────────────────────────────────────┘

Total: 45 tests | Sequential execution required
```

### Week 2: High-Value Error Handling (Phase 2)

```
┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Test Implementation (Database Access)      │
├─────────────────────────────────────────────────────────┤
│ Task 1: ToolValidator edge cases (8 tests)            │
│ Task 2: DataExtract comprehensive (8 tests)            │
│ Task 3: Grep error handling (7 tests)                  │
│ Task 4: PdfReader external command (6 tests)           │
│ Task 5: Guard execution order (5 tests)                │
└─────────────────────────────────────────────────────────┘

Total: 34 tests | Sequential execution required
```

### Week 3: Tools Edge Cases (Phase 3)

```
┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Test Implementation (Database Access)      │
├─────────────────────────────────────────────────────────┤
│ Task 1: FileRead edge cases (6 tests)                  │
│ Task 2: FileWrite edge cases (6 tests)                 │
│ Task 3: Glob pattern matching (5 tests)                │
│ Task 4: AbstractTool schema (4 tests)                  │
│ Task 5: Tool::fromClosure (5 tests)                    │
└─────────────────────────────────────────────────────────┘

Total: 26 tests | Sequential execution required
```

### Week 4: Integration Tests (Phase 4)

```
┌─────────────────────────────────────────────────────────┐
│ SEQUENTIAL: Integration Tests (Database Access)        │
├─────────────────────────────────────────────────────────┤
│ Task 1: Orchestration complex scenarios (8 tests)      │
│ Task 2: Tool chaining integration (5 tests)            │
│ Task 3: Provider-specific behavior (6 tests)           │
│ Task 4: Middleware & guards (4 tests)                  │
│ Task 5: Conversation history (5 tests)                 │
└─────────────────────────────────────────────────────────┘

Total: 28 tests | Sequential execution required
```

---

## Success Metrics

### Coverage Targets

- **Overall:** 85% → 95% (+10%)
- **Tools Component:** 65% → 90% (+25%)
- **ToolArgument:** 0% → 100% (+100%)
- **Provider Unit Tests:** 20% → 85% (+65%)
- **Security Paths:** 75% → 100% (+25%)

### Quality Gates

✅ All critical security tests pass
✅ No infinite loops possible in tool execution
✅ Provider errors handled gracefully
✅ All tools have input validation tests
✅ SSRF protection comprehensive
✅ External command execution secured

---

## Implementation Commands

### Run Specific Test File

```bash
# Run single test file
./vendor/bin/pest tests/Unit/Tool/ToolArgumentTest.php

# Run with filter
./vendor/bin/pest tests/Unit/AgentTest.php --filter="infinite loop"

# Run directory
./vendor/bin/pest tests/Unit/Tools/
```

### Run All Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Run with minimum coverage threshold
./vendor/bin/pest --coverage --min=90
```

### Static Analysis (Can Run in Parallel)

```bash
# Run PHPStan
vendor/bin/phpstan analyze src/Tools/
vendor/bin/phpstan analyze src/Tool/
vendor/bin/phpstan analyze src/Providers/

# Run PHP_CodeSniffer
vendor/bin/phpcs src/Tool/
vendor/bin/phpcs src/Tools/
vendor/bin/phpcs src/Providers/
```

### Verify Coverage After Each Phase

```bash
# Generate coverage report
composer test:coverage

# Check specific file coverage
./vendor/bin/pest tests/Unit/Tool/ToolArgumentTest.php --coverage

# Verify minimum threshold
./vendor/bin/pest --coverage --min=85
```

---

## Natural Language Agent Invocation Examples

### Example 1: Direct Implementation Request

```
Write comprehensive Pest tests for the ToolArgument class at src/Tool/ToolArgument.php.
The test file should be tests/Unit/Tool/ToolArgumentTest.php and include 13 test cases
covering type conversion, nullable types, union types, and enum validation.

After implementation, run ./vendor/bin/pest tests/Unit/Tool/ToolArgumentTest.php to verify.
```

### Example 2: Using Custom Subagent (If Created)

```
Use the php-test-writer subagent to implement security tests for the Bash tool.
Location: tests/Unit/Tools/BashTest.php
Focus on: timeout enforcement, special character handling, shell injection prevention.
Run tests after: ./vendor/bin/pest tests/Unit/Tools/BashTest.php
```

### Example 3: Sequential Task Coordination

```
Implement provider error handling tests sequentially:

1. First, add Anthropic error tests to tests/Unit/Providers/AnthropicTest.php
   - Run: ./vendor/bin/pest tests/Unit/Providers/AnthropicTest.php
   - Verify: 6 tests pass

2. Then, add OpenAI error tests to tests/Unit/Providers/OpenAITest.php
   - Run: ./vendor/bin/pest tests/Unit/Providers/OpenAITest.php
   - Verify: 3 tests pass

Do NOT run tests in parallel due to SQLite database locking.
```

---

## Risk Mitigation

### High-Risk Changes

1. **ToolArgument refactoring** - May break existing tools
   - **Mitigation:** Run full test suite after implementation
   - Command: `composer test`

2. **Provider mocking** - Complex HTTP mocking required
   - **Mitigation:** Use established mocking library (Mockery)
   - Verify with: `./vendor/bin/pest tests/Unit/Providers/`

3. **Security test false positives** - May block legitimate use cases
   - **Mitigation:** Parameterize security settings, allow overrides
   - Test with: `./vendor/bin/pest tests/Unit/Tools/ --group=security`

### Rollback Plan

- Each phase is independent
- Can merge phases 1-3 without phase 4
- Phase 1 is critical, phases 2-4 are enhancements
- All tests must pass before merging: `composer test`

---

## Conclusion

This consolidated plan combines **112 test cases** from two comprehensive analyses:

- **45 critical security tests** (Week 1)
- **34 high-value error handling tests** (Week 2)
- **26 tools edge case tests** (Week 3)
- **28 integration tests** (Week 4)

**Total estimated effort:** 30-40 hours across 4 weeks

### Key Differences from Original Plan

1. ✅ **Removed fictional agents** (linus-code-architect, typescript-pro, python-pro)
2. ✅ **Added real execution constraints** (SQLite locking requires sequential tests)
3. ✅ **Natural language invocation** patterns for Claude Code
4. ✅ **Real Pest/Composer commands** for test execution
5. ✅ **Parallel static analysis** separated from sequential test runs
6. ✅ **Optional custom subagent creation** guide included

### Key Priorities

1. 🔴 **ToolArgument** (0% coverage is unacceptable)
2. 🔴 **Tool security** (Bash, WebFetch command injection)
3. 🔴 **Provider error handling** (production stability)
4. 🟡 **Edge cases and validation** (robustness)
5. 🟢 **Integration scenarios** (quality assurance)

### Implementation Approach

All tests should be implemented **sequentially** due to SQLite database locking constraints. Use natural language requests to Claude Code for each task, following the patterns shown in this document.

**Next Steps:**

1. Optionally create custom subagents in `.claude/agents/`
2. Start with Phase 1, Task 1.1 (ToolArgument tests)
3. Run tests after each implementation: `./vendor/bin/pest <test-file>`
4. Generate coverage report after each phase: `composer test:coverage`
5. Verify coverage targets are met before moving to next phase
