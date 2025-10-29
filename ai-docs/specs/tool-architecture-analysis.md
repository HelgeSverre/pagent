# Tool Architecture Analysis - Pagent

**Date:** 2025-10-12
**Version Analyzed:** v0.5.1 (main branch, commit 659b7cf)

---

## Executive Summary

Pagent currently has **two distinct tool systems** that serve different purposes but are **incompletely integrated**:

1. **Closure-based Tools** (`Pagent\Tool\Tool`) - Fully functional, reflection-based, with automatic schema generation
2. **Class-based Tools** (`Pagent\Tools\Tool`) - 8 built-in tools with proper interfaces, but **missing schema generation methods**

The core issue is that **class-based tools cannot be added to agents** because:

- `Agent->tool()` only accepts closures (line 174 in `src/Agent.php`)
- `Agent->getToolSchemas()` calls `toAnthropicSchema()` and `toOpenAISchema()` on all tools, but class-based tools don't have these methods
- The `showcase/papermint-03-production.php` example shows the **intended usage pattern** that is currently broken

---

## Part 1: Current Architecture

### 1.1 Closure-Based Tools (`Pagent\Tool\Tool`)

**Location:** `src/Tool/Tool.php`

**Purpose:** Lightweight, fluent tool definition using PHP closures with automatic schema generation.

**Key Features:**

- **Static factory:** `Tool::fromClosure(name, description, closure)`
- **Automatic argument detection** via `ReflectionFunction`
- **Type inference** from PHP type hints (string, int, float, bool, array)
- **Schema generation methods:**
  - `toAnthropicSchema()` - Generates Anthropic format
  - `toOpenAISchema()` - Generates OpenAI format
- **Validation:** `ToolValidator::validate()` checks types and required parameters
- **Execution:** `execute(array $arguments)` calls the closure with validated args

**Supporting Classes:**

- `ToolArgument` - Represents a single parameter with type, description, default value
- `ToolValidator` - Runtime validation of tool arguments

**Example Usage:**

```php
agent('assistant')
    ->tool('calculate', 'Perform math', fn(int $a, int $b) => $a + $b)
    ->tool('get_weather', 'Get weather', fn(string $city, bool $forecast = false) => '...');
```

**Type Mapping (PHP → JSON Schema):**

- `int` → `integer`
- `float` → `number`
- `bool` → `boolean`
- `array` → `array`
- `object`, `stdClass` → `object`
- anything else → `string`

**How It Works:**

1. User calls `agent()->tool($name, $description, $closure)`
2. `Agent::tool()` calls `Tool::fromClosure()` which reflects the closure
3. Reflection extracts parameter names, types, defaults, nullability
4. Tool is stored in `Agent->tools[]` array
5. On LLM call, `getToolSchemas()` converts all tools to provider-specific format
6. LLM requests tool execution, `executeTool()` validates and calls the closure

---

### 1.2 Class-Based Tools (`Pagent\Tools\Tool`)

**Location:** `src/Tools/Tool.php` (abstract base) + 8 concrete implementations

**Purpose:** Production-ready, reusable tools with configuration options and security guards.

**Abstract Interface:**

```php
abstract class Tool {
    abstract public function name(): string;
    abstract public function description(): string;
    abstract public function execute(array $params): mixed;

    public function parameters(): array {
        return []; // JSON Schema format
    }
}
```

**Built-in Tools (8 total):**

| Tool            | Purpose                    | Security Features                                           | Configuration                                       |
| --------------- | -------------------------- | ----------------------------------------------------------- | --------------------------------------------------- |
| **FileRead**    | Read file contents         | Path traversal prevention, size limits, baseDir restriction | `baseDir`, `maxSize` (10MB default)                 |
| **FileWrite**   | Write files                | Path traversal prevention, size limits, directory creation  | `baseDir`, `maxSize` (10MB default)                 |
| **Glob**        | Find files by pattern      | BaseDir restriction, result limits                          | `baseDir`, `maxResults` (1000 default)              |
| **Grep**        | Search in files            | BaseDir restriction, result limits, context lines           | `baseDir`, `maxResults` (100), `contextLines`       |
| **WebFetch**    | HTTP GET requests          | SSRF protection (blocks private IPs), size limits, timeout  | `timeout` (30s), `maxSize` (10MB), `ssrfProtection` |
| **Bash**        | Execute shell commands     | Command whitelisting, timeout, working directory control    | `workingDir`, `timeout` (60s), `allowedCommands`    |
| **PdfReader**   | Extract text from PDFs     | Path traversal prevention, size limits, requires pdftotext  | `baseDir`, `maxSize` (50MB), `pdftotextPath`        |
| **DataExtract** | Structured data extraction | Uses OpenAI structured outputs with JSON Schema validation  | `provider`, `model` (gpt-4o-mini)                   |

**Example Tool Implementation (FileRead):**

```php
final class FileRead extends Tool {
    public function __construct(
        private ?string $baseDir = null,
        private ?int $maxSize = null,
    ) {
        $this->maxSize = $maxSize ?? 10 * 1024 * 1024; // 10MB
    }

    public function name(): string {
        return 'file_read';
    }

    public function description(): string {
        return 'Read the contents of a file. Returns the full file contents as a string.';
    }

    public function parameters(): array {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to read',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): mixed {
        // Path validation, traversal prevention, size checks, read file
    }
}
```

**Why Class-Based Tools Exist:**

1. **Reusability** - Define once, use in multiple agents with different configs
2. **Encapsulation** - Complex logic, state, dependencies bundled together
3. **Security** - Centralized security guards (SSRF, path traversal, command whitelisting)
4. **Testing** - 46 dedicated unit tests for these tools
5. **Configuration** - Constructor params for limits, directories, timeouts
6. **Production-ready** - Battle-tested implementations with error handling

---

### 1.3 Agent Integration

**File:** `src/Agent.php`

**Tool Storage:**

```php
/** @var Tool[] */
private array $tools = [];
```

**Current `tool()` Method (Line 174):**

```php
public function tool(string $name, string $description, Closure $callable): self
{
    $this->tools[] = Tool::fromClosure($name, $description, $callable);
    return $this;
}
```

**Problem:** Only accepts closures, not class-based Tool instances.

**Tool Execution (Line 189):**

```php
public function executeTool(string $name, array $arguments): mixed
{
    foreach ($this->tools as $tool) {
        if ($tool->name === $name) {
            return $tool->execute($arguments);
        }
    }
    throw new RuntimeException("Tool '$name' not found");
}
```

**Schema Generation (Line 454):**

```php
private function getToolSchemas(): array
{
    $provider = get_class($this->provider);

    if (str_contains($provider, 'Anthropic')) {
        return array_map(fn ($tool) => $tool->toAnthropicSchema(), $this->tools);
    }

    if (str_contains($provider, 'OpenAI')) {
        return array_map(fn ($tool) => $tool->toOpenAISchema(), $this->tools);
    }

    return [];
}
```

**Problem:** Assumes all tools have `toAnthropicSchema()` and `toOpenAISchema()` methods, but class-based tools don't.

---

## Part 2: The Gap - Why Class-Based Tools Don't Work

### 2.1 Missing Integration Points

**Issue #1: Agent->tool() Method Signature**

```php
// Current (ONLY accepts closures)
public function tool(string $name, string $description, Closure $callable): self

// Needed (should accept both)
public function tool(
    string|\Pagent\Tools\Tool $nameOrTool,
    ?string $description = null,
    ?Closure $callable = null
): self
```

**Issue #2: Class-Based Tools Missing Schema Methods**

Class-based tools have `parameters()` which returns JSON Schema, but they don't have:

- `toAnthropicSchema()` - Required by `Agent->getToolSchemas()` for Anthropic
- `toOpenAISchema()` - Required by `Agent->getToolSchemas()` for OpenAI

The methods can't be added to the abstract `Tool` class because:

- They need the tool's `name()` and `description()` to wrap the schema
- Anthropic and OpenAI have different wrapping formats

**Issue #3: Type Incompatibility**

The `Agent->tools` array is typed as `Tool[]`, but this refers to `Pagent\Tool\Tool` (closure-based), not `Pagent\Tools\Tool` (class-based). They are completely different classes in different namespaces.

---

### 2.2 Evidence of Intended Usage

**File:** `showcase/papermint-03-production.php` (Lines 22-23)

```php
agent('extractor')->provider(openai())
    ->tool(new PdfReader(baseDir: '/receipts'))
    ->tool(new DataExtract(model: 'gpt-4o-mini'))
    ->system('Extract text from PDF and parse receipt data using tools');
```

This code shows the **intended pattern** but it **cannot currently work** because:

1. `Agent->tool()` expects `(string, string, Closure)` but receives `(PdfReader)`
2. Even if we fixed the signature, `getToolSchemas()` would fail calling `toAnthropicSchema()`
3. The `Tool[]` typehint would be violated

**Roadmap Confirmation:**

`DEVELOPMENT_ROADMAP.md` (Lines 159-165) shows this was planned:

```php
// 5. Built-in tool library
use Pagent\Tools\{FileReader, WebFetcher, Calculator, DateFormatter};

agent('assistant')
    ->tool(new FileReader(maxSize: 1024 * 1024)) // 1MB limit
    ->tool(new WebFetcher(timeout: 10))
    ->tool(new Calculator())
    ->tool(new DateFormatter());
```

---

## Part 3: Inconsistencies & Problems

### 3.1 Schema Format Differences

**Closure-Based Tool Schema (Anthropic):**

```php
[
    'name' => 'get_weather',
    'description' => 'Get current weather',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string'],
            'forecast' => ['type' => 'boolean'],
        ],
        'required' => ['city'],
    ],
]
```

**Class-Based Tool `parameters()` Returns:**

```php
[
    'type' => 'object',
    'properties' => [
        'path' => [
            'type' => 'string',
            'description' => 'Path to the file',
        ],
    ],
    'required' => ['path'],
]
```

**Gap:** Class-based tools return just the `input_schema` part, not the full wrapper with `name` and `description`.

---

### 3.2 Execution Interface Differences

**Closure-Based:**

```php
$tool->execute([10, 5]); // Positional array OR
$tool->execute(['a' => 10, 'b' => 5]); // Associative array
```

**Class-Based:**

```php
$tool->execute(['path' => '/file.txt']); // ONLY associative array
```

Both work, but class-based tools always expect named parameters.

---

### 3.3 Argument Metadata

**Closure-Based:**

- Stores `ToolArgument[]` with names, types, defaults, descriptions
- Descriptions are `null` (not extractable from function signature)
- Types inferred from PHP type hints

**Class-Based:**

- Descriptions are in the `parameters()` JSON Schema
- Types are in JSON Schema format (`string`, `integer`, etc.)
- No structured ToolArgument objects

---

### 3.4 Validation Approach

**Closure-Based:**

- `ToolValidator::validate()` checks types against `ToolArgument[]`
- Supports both positional and named arguments
- Runtime PHP type checking

**Class-Based:**

- No automatic validation before `execute()`
- Tools implement their own validation inside `execute()`
- Could theoretically validate against JSON Schema from `parameters()`

---

## Part 4: Recommended Refactoring Approach

### 4.1 Design Principles

1. **Backward Compatibility First** - Don't break existing closure-based tool usage
2. **Unified Interface** - Both tool types should work identically from Agent's perspective
3. **No Duplication** - Avoid monkey-patching or copy-pasting schema logic
4. **Type Safety** - Maintain PHPStan level 9 compliance
5. **Clear Separation** - Keep closure-based and class-based tools distinct but compatible

---

### 4.2 Proposed Solution: Adapter Pattern + Interface

**Step 1: Create a Common Interface**

```php
// src/Contracts/ToolInterface.php
namespace Pagent\Contracts;

interface ToolInterface {
    public function getName(): string;
    public function getDescription(): string;
    public function execute(array $arguments): mixed;
    public function toAnthropicSchema(): array;
    public function toOpenAISchema(): array;
}
```

**Step 2: Make Closure-Based Tool Implement Interface**

```php
// src/Tool/Tool.php
final readonly class Tool implements \Pagent\Contracts\ToolInterface
{
    // ... existing code ...

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    // toAnthropicSchema() and toOpenAISchema() already exist
}
```

**Step 3: Create Adapter for Class-Based Tools**

```php
// src/Tools/ToolAdapter.php
namespace Pagent\Tools;

use Pagent\Contracts\ToolInterface;

final readonly class ToolAdapter implements ToolInterface
{
    public function __construct(
        private Tool $tool,
    ) {}

    public function getName(): string {
        return $this->tool->name();
    }

    public function getDescription(): string {
        return $this->tool->description();
    }

    public function execute(array $arguments): mixed {
        return $this->tool->execute($arguments);
    }

    public function toAnthropicSchema(): array {
        return [
            'name' => $this->tool->name(),
            'description' => $this->tool->description(),
            'input_schema' => $this->tool->parameters(),
        ];
    }

    public function toOpenAISchema(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->tool->name(),
                'description' => $this->tool->description(),
                'parameters' => $this->tool->parameters(),
            ],
        ];
    }
}
```

**Step 4: Update Agent to Accept Both**

```php
// src/Agent.php
use Pagent\Contracts\ToolInterface;
use Pagent\Tools\Tool as ClassBasedTool;
use Pagent\Tools\ToolAdapter;

final class Agent
{
    /** @var ToolInterface[] */
    private array $tools = [];

    // Overload to support both signatures
    public function tool(
        string|ClassBasedTool $nameOrTool,
        ?string $description = null,
        ?Closure $callable = null
    ): self {
        if ($nameOrTool instanceof ClassBasedTool) {
            // Class-based tool
            $this->tools[] = new ToolAdapter($nameOrTool);
        } elseif (is_string($nameOrTool) && $description !== null && $callable !== null) {
            // Closure-based tool
            $this->tools[] = Tool::fromClosure($nameOrTool, $description, $callable);
        } else {
            throw new \InvalidArgumentException(
                'Invalid tool() signature. Use either: ' .
                '->tool(ToolInstance) or ->tool(name, description, closure)'
            );
        }

        return $this;
    }

    public function executeTool(string $name, array $arguments): mixed {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool->execute($arguments);
            }
        }

        // Better error message with suggestions (existing logic)
        $available = array_map(fn ($t) => $t->getName(), $this->tools);
        $suggestions = $this->findSimilarToolNames($name, $available);
        // ... existing error handling ...
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array {
        return $this->tools;
    }

    private function getToolSchemas(): array {
        $provider = get_class($this->provider);

        if (str_contains($provider, 'Anthropic')) {
            return array_map(fn ($tool) => $tool->toAnthropicSchema(), $this->tools);
        }

        if (str_contains($provider, 'OpenAI')) {
            return array_map(fn ($tool) => $tool->toOpenAISchema(), $this->tools);
        }

        return [];
    }
}
```

---

### 4.3 Alternative Solutions (Evaluated & Rejected)

**Option A: Add Schema Methods to Abstract Tool**

```php
abstract class Tool {
    public function toAnthropicSchema(): array {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_schema' => $this->parameters(),
        ];
    }

    public function toOpenAISchema(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $this->parameters(),
            ],
        ];
    }
}
```

**Why Rejected:**

- Mixes provider-specific logic into domain tool classes
- Violates single responsibility principle
- Would need to be updated if new providers are added
- Makes the abstract Tool class aware of external API formats

**Option B: Monkey-Patch in Agent->tool()**

```php
public function tool($nameOrTool, $description = null, $callable = null): self {
    if ($nameOrTool instanceof ClassBasedTool) {
        // Dynamically add methods (ugly!)
        $wrapped = new class($nameOrTool) {
            public function __construct(private Tool $tool) {}

            public function __call($method, $args) {
                if ($method === 'toAnthropicSchema') {
                    return ['name' => $this->tool->name(), /* ... */];
                }
                return $this->tool->$method(...$args);
            }
        };
        $this->tools[] = $wrapped;
    }
}
```

**Why Rejected:**

- Extremely fragile and hard to maintain
- Breaks type safety and static analysis
- No IDE support or autocomplete
- Debugging nightmare

**Option C: Union Type Array**

```php
/** @var array<Tool|\Pagent\Tools\Tool> */
private array $tools = [];
```

**Why Rejected:**

- Breaks type safety completely
- Forces runtime type checks everywhere
- Still need to handle schema generation differently for each type
- PHPStan would fail

---

## Part 5: Implementation Plan

### Phase 1: Create Infrastructure (Non-Breaking)

**Files to Create:**

1. `src/Contracts/ToolInterface.php` - Common interface
2. `src/Tools/ToolAdapter.php` - Adapter for class-based tools
3. `tests/Unit/Tools/ToolAdapterTest.php` - Adapter tests

**Files to Modify:**

1. `src/Tool/Tool.php` - Add `implements ToolInterface`
2. `src/Agent.php` - Update typehints to `ToolInterface[]`

**Estimated Time:** 2-3 hours

**Risk:** Low - Only adds new code, doesn't change existing behavior

---

### Phase 2: Update Agent Integration (Breaking Change)

**Files to Modify:**

1. `src/Agent.php` - Update `tool()` method signature
2. `src/Agent.php` - Update `executeTool()` to use `getName()`
3. `src/Agent.php` - `getToolSchemas()` already works via interface

**Breaking Changes:**

- `getTools()` return type changes from `Tool[]` to `ToolInterface[]`
- Internal `$tools` property changes from `Tool[]` to `ToolInterface[]`

**Backward Compatibility:**

- Closure-based usage is 100% backward compatible (no API changes)
- Existing code that doesn't inspect `getTools()` return type is unaffected

**Estimated Time:** 1-2 hours

**Risk:** Medium - Changes public API but maintains behavior

---

### Phase 3: Testing & Documentation

**Tests to Update:**

1. `tests/Unit/AgentToolsTest.php` - Update to test both tool types
2. Create `tests/Integration/ClassBasedToolsTest.php` - Test FileRead, Grep, etc. with agents

**Tests to Add:**

- Test adding class-based tools to agents
- Test schema generation for both tool types
- Test mixed usage (closure + class-based in same agent)
- Test all 8 built-in tools via agents

**Documentation to Update:**

1. `README.md` - Add class-based tool examples
2. `guide/02-recipes-task-oriented.md` - Add FileRead, WebFetch examples
3. `guide/05-api-reference.md` - Document `tool()` overloads

**Estimated Time:** 3-4 hours

**Risk:** Low - Improves coverage and documentation

---

### Phase 4: Showcase & Examples

**Files to Update:**

1. `showcase/papermint-03-production.php` - Make it work!
2. Create `examples/10-builtin-tools.php` - Demo all 8 tools

**Example Code:**

```php
// examples/10-builtin-tools.php
use Pagent\Tools\{FileRead, FileWrite, Glob, Grep, WebFetch, Bash, PdfReader};

agent('file-assistant')
    ->provider('openai')
    ->tool(new FileRead(baseDir: '/project'))
    ->tool(new Glob(baseDir: '/project'))
    ->tool(new Grep(baseDir: '/project'))
    ->system('Help users find and read files');

$response = agent('file-assistant')->prompt('Find all PHP files in src/');

agent('web-scraper')
    ->provider('anthropic')
    ->tool(new WebFetch(timeout: 10, ssrfProtection: true))
    ->system('Fetch and analyze web content');

$response = agent('web-scraper')->prompt('What is on example.com?');

agent('pdf-parser')
    ->provider('openai')
    ->tool(new PdfReader(baseDir: '/documents'))
    ->tool(new DataExtract(model: 'gpt-4o-mini'))
    ->system('Extract structured data from PDFs');

$response = agent('pdf-parser')->prompt('Extract invoice details from invoice.pdf');
```

**Estimated Time:** 2 hours

**Risk:** Low - Just examples

---

## Part 6: Breaking Changes vs Backward-Compatible Options

### 6.1 Breaking Changes in Recommended Approach

**Public API Changes:**

1. **`Agent->getTools()` Return Type**
   - **Before:** `Tool[]` (referring to `Pagent\Tool\Tool`)
   - **After:** `ToolInterface[]`
   - **Impact:** Code that inspects tool arrays must use interface methods
   - **Workaround:** Provide `getClosureTools()` and `getClassBasedTools()` if needed

2. **Agent Internal Property**
   - **Before:** `private array $tools = []` (with `@var Tool[]` annotation)
   - **After:** `private array $tools = []` (with `@var ToolInterface[]` annotation)
   - **Impact:** None (private property)

---

### 6.2 Fully Backward-Compatible Alternative

**Approach:** Keep two separate tool arrays internally.

```php
final class Agent
{
    /** @var \Pagent\Tool\Tool[] */
    private array $closureTools = [];

    /** @var \Pagent\Tools\ToolAdapter[] */
    private array $classBasedTools = [];

    public function tool(
        string|\Pagent\Tools\Tool $nameOrTool,
        ?string $description = null,
        ?Closure $callable = null
    ): self {
        if ($nameOrTool instanceof \Pagent\Tools\Tool) {
            $this->classBasedTools[] = new ToolAdapter($nameOrTool);
        } else {
            $this->closureTools[] = Tool::fromClosure($nameOrTool, $description, $callable);
        }
        return $this;
    }

    /**
     * @return \Pagent\Tool\Tool[]
     */
    public function getTools(): array {
        return $this->closureTools;
    }

    /**
     * @return \Pagent\Tools\Tool[]
     */
    public function getClassBasedTools(): array {
        return array_map(fn($adapter) => $adapter->getTool(), $this->classBasedTools);
    }

    /**
     * @return ToolInterface[]
     */
    public function getAllTools(): array {
        return array_merge($this->closureTools, $this->classBasedTools);
    }

    public function executeTool(string $name, array $arguments): mixed {
        foreach ($this->getAllTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool->execute($arguments);
            }
        }
        // ... error handling
    }

    private function getToolSchemas(): array {
        return array_map(
            fn($tool) => /* schema */,
            $this->getAllTools()
        );
    }
}
```

**Pros:**

- 100% backward compatible - `getTools()` still returns `Tool[]`
- Explicit separation of tool types
- No typehint changes

**Cons:**

- More complex internal state (two arrays)
- Duplication in schema generation and execution logic
- `getAllTools()` creates merged array on every call (performance)
- Harder to reason about tool order (were closure tools added first or class-based?)

**Recommendation:** **DON'T DO THIS**. The breaking change is minimal and the complexity cost is too high.

---

## Part 7: Migration Path for Existing Code

### 7.1 Impact Assessment

**Who is affected:**

- Internal tests that call `getTools()` and inspect array contents
- Any code that does `foreach ($agent->getTools() as $tool)` and accesses `Tool` specific properties

**Who is NOT affected:**

- All agent usage that just adds tools and prompts (99% of use cases)
- Closure-based tool definitions (zero changes needed)
- Tool execution (internal, no API change)

---

### 7.2 Migration Steps

**If you call `getTools()`:**

```php
// Before
$tools = $agent->getTools();
foreach ($tools as $tool) {
    echo $tool->name; // Direct property access
}

// After
$tools = $agent->getTools();
foreach ($tools as $tool) {
    echo $tool->getName(); // Use interface method
}
```

**If you inspect tool types:**

```php
// Before
$tools = $agent->getTools();
if ($tools[0] instanceof \Pagent\Tool\Tool) {
    // ...
}

// After
$tools = $agent->getTools();
if ($tools[0] instanceof \Pagent\Contracts\ToolInterface) {
    // Use interface methods: getName(), getDescription(), execute()
}

// If you REALLY need to distinguish:
if ($tools[0] instanceof \Pagent\Tool\Tool) {
    // Closure-based tool
} elseif ($tools[0] instanceof \Pagent\Tools\ToolAdapter) {
    // Class-based tool (wrapped)
}
```

---

### 7.3 Testing Strategy

**Update Existing Tests:**

1. `tests/Unit/AgentToolsTest.php` - Replace `$tool->name` with `$tool->getName()`
2. `tests/Integration/ToolCallingTest.php` - Should work unchanged (no inspection)

**Add New Tests:**

1. Test adding FileRead via `->tool(new FileRead())`
2. Test schema generation for class-based tools
3. Test execution of class-based tools via agent
4. Test mixing closure and class-based tools
5. Test all 8 built-in tools work through agents

**Validation:**

- Run full test suite (should still have 229+ tests passing)
- Run examples to ensure backward compatibility
- Run new showcase examples with class-based tools

---

## Part 8: Recommendations

### 8.1 Implementation Priority

**Priority 1: Fix the Core Integration (Phase 1 & 2)**

- Create `ToolInterface`, `ToolAdapter`
- Update `Agent->tool()` to accept both types
- Update schema generation to work via interface
- **Time:** 3-4 hours
- **Impact:** HIGH - Enables all 8 built-in tools to work with agents

**Priority 2: Testing & Documentation (Phase 3)**

- Add tests for class-based tools via agents
- Document both usage patterns
- **Time:** 3-4 hours
- **Impact:** MEDIUM - Ensures stability and discoverability

**Priority 3: Examples & Showcase (Phase 4)**

- Create comprehensive examples
- Fix papermint showcase
- **Time:** 2 hours
- **Impact:** LOW - Nice to have

---

### 8.2 Design Recommendations

1. **Use the Adapter Pattern** - Clean, extensible, follows SOLID principles
2. **Introduce ToolInterface** - Unifies both tool types under common contract
3. **Accept the Minimal Breaking Change** - Changing `getTools()` return type is acceptable
4. **Don't Add Schema Methods to Tool** - Keep provider logic out of domain classes
5. **Leverage Existing Tests** - 46 tool tests already exist, just need agent integration tests

---

### 8.3 Future Considerations

**After this refactor:**

1. **Tool Attributes (Roadmap v0.5.0)**
   - Could add `#[ToolDescription]` and `#[Param]` attributes
   - Works for both closure-based (via reflection) and class-based (on properties)

2. **Tool Registry**
   - Create global registry of built-in tools
   - `agent()->tool('file_read')` could auto-load from registry

3. **Tool Composition**
   - Create tools that wrap other tools
   - Middleware for tools (logging, rate limiting)

4. **Schema Validation**
   - Validate `parameters()` output is valid JSON Schema
   - Catch errors at tool registration, not execution

5. **OpenAPI/Swagger Generation**
   - Generate API documentation from tool schemas
   - Export tools as OpenAPI specs

---

## Appendix A: Complete File Changes

### A.1 New Files

**src/Contracts/ToolInterface.php:**

```php
<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function execute(array $arguments): mixed;

    public function toAnthropicSchema(): array;

    public function toOpenAISchema(): array;
}
```

**src/Tools/ToolAdapter.php:**

```php
<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Contracts\ToolInterface;

final readonly class ToolAdapter implements ToolInterface
{
    public function __construct(
        private Tool $tool,
    ) {}

    public function getName(): string
    {
        return $this->tool->name();
    }

    public function getDescription(): string
    {
        return $this->tool->description();
    }

    public function execute(array $arguments): mixed
    {
        return $this->tool->execute($arguments);
    }

    public function toAnthropicSchema(): array
    {
        return [
            'name' => $this->tool->name(),
            'description' => $this->tool->description(),
            'input_schema' => $this->tool->parameters(),
        ];
    }

    public function toOpenAISchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->tool->name(),
                'description' => $this->tool->description(),
                'parameters' => $this->tool->parameters(),
            ],
        ];
    }

    /**
     * Get the underlying tool instance (for testing/inspection).
     */
    public function unwrap(): Tool
    {
        return $this->tool;
    }
}
```

---

### A.2 Modified Files

**src/Tool/Tool.php - Add implements:**

```php
<?php

declare(strict_types=1);

namespace Pagent\Tool;

use Closure;
use Pagent\Contracts\ToolInterface;
use ReflectionFunction;
use ReflectionNamedType;

final readonly class Tool implements ToolInterface
{
    // ... existing constructor and properties ...

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    // ... rest of existing code unchanged ...
}
```

**src/Agent.php - Update tool() method:**

```php
use Closure;
use Pagent\Contracts\ToolInterface;
use Pagent\Tool\Tool;
use Pagent\Tools\Tool as ClassBasedTool;
use Pagent\Tools\ToolAdapter;

final class Agent
{
    /** @var ToolInterface[] */
    private array $tools = [];

    // ... existing code ...

    public function tool(
        string|ClassBasedTool $nameOrTool,
        ?string $description = null,
        ?Closure $callable = null
    ): self {
        if ($nameOrTool instanceof ClassBasedTool) {
            $this->tools[] = new ToolAdapter($nameOrTool);
        } elseif (is_string($nameOrTool) && $description !== null && $callable !== null) {
            $this->tools[] = Tool::fromClosure($nameOrTool, $description, $callable);
        } else {
            throw new \InvalidArgumentException(
                'Invalid tool() signature. Expected: ->tool(ToolInstance) or ->tool(name, description, closure)'
            );
        }

        return $this;
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function executeTool(string $name, array $arguments): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool->execute($arguments);
            }
        }

        // Better error message with suggestions
        $available = array_map(fn ($t) => $t->getName(), $this->tools);
        $suggestions = $this->findSimilarToolNames($name, $available);

        $message = "Tool '{$name}' not found";

        if (! empty($suggestions)) {
            $message .= '. Did you mean: '.implode(', ', $suggestions).'?';
        }

        if (! empty($available)) {
            $message .= ' Available tools: '.implode(', ', $available);
        }

        throw new RuntimeException($message);
    }

    // getToolSchemas() already works unchanged via interface
}
```

---

## Appendix B: Test Coverage Plan

### B.1 Unit Tests to Add

**tests/Unit/Tools/ToolAdapterTest.php:**

```php
test('adapter wraps class-based tool', function () {
    $tool = new FileRead(baseDir: '/tmp');
    $adapter = new ToolAdapter($tool);

    expect($adapter->getName())->toBe('file_read')
        ->and($adapter->getDescription())->toContain('Read');
});

test('adapter generates anthropic schema', function () {
    $tool = new FileRead;
    $adapter = new ToolAdapter($tool);
    $schema = $adapter->toAnthropicSchema();

    expect($schema)->toHaveKeys(['name', 'description', 'input_schema'])
        ->and($schema['name'])->toBe('file_read')
        ->and($schema['input_schema'])->toHaveKey('properties');
});

test('adapter generates openai schema', function () {
    $tool = new Grep;
    $adapter = new ToolAdapter($tool);
    $schema = $adapter->toOpenAISchema();

    expect($schema['type'])->toBe('function')
        ->and($schema['function'])->toHaveKeys(['name', 'description', 'parameters']);
});

test('adapter executes underlying tool', function () {
    $file = sys_get_temp_dir().'/test.txt';
    file_put_contents($file, 'content');

    $tool = new FileRead;
    $adapter = new ToolAdapter($tool);
    $result = $adapter->execute(['path' => $file]);

    expect($result)->toBe('content');
    unlink($file);
});
```

**tests/Unit/AgentToolsTest.php - Add tests:**

```php
test('agent accepts class-based tool', function () {
    $agent = testAgent('test');
    $agent->tool(new FileRead(baseDir: '/tmp'));

    expect($agent->getTools())->toHaveCount(1)
        ->and($agent->getTools()[0]->getName())->toBe('file_read');
});

test('agent mixes closure and class-based tools', function () {
    $agent = testAgent('mixed');
    $agent->tool('add', 'Add numbers', fn(int $a, int $b) => $a + $b);
    $agent->tool(new FileRead);

    expect($agent->getTools())->toHaveCount(2)
        ->and($agent->getTools()[0]->getName())->toBe('add')
        ->and($agent->getTools()[1]->getName())->toBe('file_read');
});

test('agent generates schemas for class-based tools', function () {
    $anthropic = mock(['test' => 'response']);
    $agent = testAgent('test')->provider($anthropic);
    $agent->tool(new Glob(baseDir: '/tmp'));

    // Internal test - schemas should be generated correctly
    $schemas = (fn() => $this->getToolSchemas())->call($agent);

    expect($schemas[0])->toHaveKey('input_schema');
});
```

### B.2 Integration Tests to Add

**tests/Integration/ClassBasedToolsTest.php:**

```php
test('file read tool works through agent', function () {
    skip('Requires OpenAI API key');

    $file = sys_get_temp_dir().'/test.txt';
    file_put_contents($file, 'Hello World');

    agent('reader')
        ->provider('openai')
        ->tool(new FileRead)
        ->system('Read files when asked');

    $response = agent('reader')->prompt("Read {$file}");

    expect($response->content)->toContain('Hello');
    unlink($file);
});

test('all 8 built-in tools work through agent', function () {
    skip('Requires API key and external tools');

    $agent = agent('toolbox')
        ->provider('anthropic')
        ->tool(new FileRead(baseDir: '/tmp'))
        ->tool(new FileWrite(baseDir: '/tmp'))
        ->tool(new Glob(baseDir: '/tmp'))
        ->tool(new Grep(baseDir: '/tmp'))
        ->tool(new WebFetch(ssrfProtection: false))
        ->tool(new Bash(allowedCommands: ['echo', 'ls']))
        ->system('Use tools to help user');

    expect($agent->getTools())->toHaveCount(6);
});
```

---

## Appendix C: Timeline & Effort Estimate

| Phase       | Tasks                                  | Time           | Complexity | Risk       |
| ----------- | -------------------------------------- | -------------- | ---------- | ---------- |
| **Phase 1** | Create interface, adapter, update Tool | 2-3 hours      | Low        | Low        |
| **Phase 2** | Update Agent integration               | 1-2 hours      | Medium     | Medium     |
| **Phase 3** | Testing & documentation                | 3-4 hours      | Medium     | Low        |
| **Phase 4** | Examples & showcase                    | 2 hours        | Low        | Low        |
| **Total**   | Full implementation                    | **8-11 hours** | Medium     | Low-Medium |

**Dependencies:**

- None (can be done immediately)

**Blockers:**

- None identified

**Recommended Approach:**

1. Implement Phases 1 & 2 in one session (half day)
2. Run test suite to validate
3. Implement Phase 3 (documentation/tests)
4. Implement Phase 4 (examples)

---

## Conclusion

The Pagent codebase has a well-designed dual tool system, but the integration between Agent and class-based tools is incomplete. The recommended solution uses the **Adapter Pattern** with a **unified ToolInterface** to bridge the gap without significant breaking changes.

**Key Benefits:**

- Enables all 8 built-in tools to work with agents
- Minimal API changes (only `getTools()` return type)
- Clean, maintainable architecture
- Follows SOLID principles
- 100% backward compatible for tool definition
- Clear path to future enhancements

**Implementation Status:**

- **Current:** Class-based tools exist but cannot be used with agents
- **After Refactor:** Both tool types work identically through unified interface
- **Estimated Effort:** 8-11 hours of focused development

**Next Steps:**

1. Review and approve this analysis
2. Implement Phases 1 & 2 (core integration)
3. Validate with test suite
4. Add documentation and examples
5. Release as v0.6.0 with enhanced tool system

---

**Analysis Completed:** 2025-10-12
**Analyzed By:** Claude Code (claude-sonnet-4-5-20250929)
**Total Files Examined:** 25+
**Lines of Code Analyzed:** 3000+
