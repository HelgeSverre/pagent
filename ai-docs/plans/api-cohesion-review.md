# API Cohesion Review & Integration Plan

**Created:** 2025-10-30
**Purpose:** Ensure all v0.7.0+ features work together harmoniously
**Status:** Analysis Complete, Recommendations Ready

---

## Executive Summary

Reviewed 8 major implementation plans for v0.7.0-v0.8.0 to ensure cohesive API design and seamless integration. Identified key integration points, potential conflicts, and recommended improvements for a unified developer experience.

### Plans Reviewed

1. **OpenTelemetry Observability** (v0.7.0, 10-15 hours)
2. **Cost & Token Tracking** (v0.7.0, 18-24 hours)
3. **Events/Hooks System** (v0.8.0, 6-8 hours) - **Foundation for observability**
4. **MCP Server Support** (v0.7.0, 6-8 hours)
5. **TOON Integration** (v0.7.0, 3-4 hours)
6. **Attribute-Based Tools** (v0.7.0, 6-8 hours)
7. **Workflow Orchestration** (v0.6.0-v0.8.0, 10-15 hours)
8. **HTTP Client Migration** (Technical debt)

### Key Findings

✅ **Strengths:**

- Consistent fluent API patterns across features
- Good separation of concerns
- Opt-in design philosophy maintained
- Events system provides excellent foundation for observability

⚠️ **Integration Points Identified:**

- Observability + Events system (needs coordination)
- Cost tracking + OpenTelemetry (should share data)
- TOON + Attribute tools (complementary, not competitive)
- MCP tools + Attribute tools (need unified tool interface)

---

## Critical Integration Points

### 1. Events System → Observability Foundation

**Issue:** OpenTelemetry plan (v0.7.0) uses manual `TelemetryManager::instance()->startSpan()` calls throughout `Agent.php`, but Events plan (v0.8.0) introduces event-driven telemetry via `TelemetryEventBridge`.

**Recommendation:** **Reverse the timeline or implement both together**

#### Option A: Implement Events First (Recommended)

```
v0.7.0:
1. Events/Hooks System (6-8 hrs) ← DO FIRST
2. TelemetryEventBridge (included in events)
3. OpenTelemetry exporters only (2-3 hrs)
4. Skip manual span creation in Agent.php

Timeline: Events system becomes the foundation
```

**Benefits:**

- Clean architecture from day one
- No manual span code to maintain
- Extensible for user-defined telemetry
- Less code in `Agent.php`

**API Example:**

```php
// Enable observability via events (clean!)
use Pagent\Events\EventManager;
use Pagent\Events\Bridges\TelemetryEventBridge;

TelemetryManager::instance()->initialize(['exporter' => 'jaeger']);
EventManager::instance()->listen(new TelemetryEventBridge());

// All agent operations auto-create spans
agent('bot')->prompt('Hello'); // Automatic telemetry via events
```

#### Option B: Dual Implementation (Current Plans)

```
v0.7.0: Manual spans in Agent.php
v0.8.0: Refactor to events, deprecate manual spans
v0.9.0: Remove manual spans entirely
```

**Downsides:**

- Maintain two approaches temporarily
- More code churn
- Confusing for early adopters

**Recommendation:** **Choose Option A** - Implement events system FIRST as foundation for v0.7.0, then build OpenTelemetry on top using `TelemetryEventBridge`.

---

### 2. Cost Tracking + OpenTelemetry Integration

**Issue:** Both plans track token usage independently with potential duplication.

**Recommendation:** **Make cost tracking the data source for OpenTelemetry metrics**

#### Updated Architecture

```php
// Cost tracking becomes the authoritative source
agent('bot')
    ->trackUsage() // Enable cost tracking
    ->telemetry(true) // Enable observability
    ->prompt('Hello');

// Flow:
// 1. Agent executes prompt
// 2. AfterLLMResponseEvent fired (includes tokens from provider)
// 3. Cost tracker calculates cost, stores usage
// 4. TelemetryEventBridge adds cost/token attributes to span
// 5. Both systems have consistent data
```

#### Integration Code

```php
// In TelemetryEventBridge::handle()
public function handle(Event $event): void
{
    if ($event instanceof AfterLLMResponseEvent) {
        $span = $this->getActiveSpan();

        // Get cost data from agent's usage tracker (single source of truth)
        $usage = $event->agent->getUsage();

        $span->setAttributes([
            'gen_ai.usage.input_tokens' => $usage->inputTokens,
            'gen_ai.usage.output_tokens' => $usage->outputTokens,
            'gen_ai.usage.total_tokens' => $usage->totalTokens,
            'gen_ai.cost.usd' => $usage->cost, // NEW: Cost attribute
            'gen_ai.cost.model' => $usage->model,
        ]);
    }
}
```

**Changes Required:**

1. **OpenTelemetry Plan:** Add cost attributes to semantic conventions
2. **Cost Tracking Plan:** Ensure `UsageData` is available immediately after response
3. **Events Plan:** Ensure `AfterLLMResponseEvent` includes reference to agent for usage access

---

### 3. TOON + Attribute-Based Tools (Complementary Features)

**Issue:** Plans seem independent but both improve tool DX. Need to clarify relationship.

**Recommendation:** **Position as complementary, not competitive**

#### Feature Positioning

```
┌─────────────────────────────────────────────────────────┐
│ Tool Definition Approach                                │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  1. Manual Schema (Current)                             │
│     └─ parameters(): array { return [...]; }            │
│                                                         │
│  2. Attribute-Based (v0.7.0)                            │
│     └─ #[Tool] class with #[Parameter] on __invoke()   │
│     └─ Auto-generates JSON schema from attributes      │
│                                                         │
│  3. TOON Encoding (v0.7.0) - ORTHOGONAL                 │
│     └─ Encodes ANY schema (manual or attribute) to TOON│
│     └─ Reduces token usage by 30-60%                   │
│     └─ Works with both approaches above                │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

#### Combined Usage

```php
use Pagent\Attributes\Tool;
use Pagent\Attributes\Parameter;
use Pagent\Tools\AttributeTool;

#[Tool(name: 'calculator', description: 'Perform calculations')]
class CalculatorTool extends AttributeTool
{
    public function __invoke(
        #[Parameter(description: 'First number')]
        float $a,

        #[Parameter(description: 'Second number')]
        float $b,

        #[Parameter(description: 'Operation', enum: ['add', 'subtract'])]
        string $operation = 'add'
    ): float {
        return match ($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
        };
    }
}

// Use both features together
agent('calculator')
    ->useToon(true)  // Encode schemas in TOON format (fewer tokens)
    ->tool(new CalculatorTool())  // Auto-generates schema from attributes
    ->prompt('What is 5 + 3?');

// Tool schema sent in TOON format (attribute-based generation → TOON encoding)
```

**Documentation Update:** Add section to both plans explaining how they work together.

---

### 4. MCP Tools + Attribute Tools (Unified Tool Interface)

**Issue:** Both introduce new tool types. Need consistent integration with existing `ToolInterface`.

**Recommendation:** **Ensure both implement `ToolInterface` cleanly**

#### Tool Type Hierarchy

```php
interface ToolInterface {
    public function name(): string;
    public function description(): string;
    public function execute(array $params): mixed;
    public function toAnthropicSchema(): array;
    public function toOpenAISchema(): array;
}

abstract class Tool implements ToolInterface {
    public function parameters(): array { return []; }
    // Default schema implementations
}

// NEW: Attribute-based tools
abstract class AttributeTool extends Tool {
    // Schema auto-generated from #[Parameter] attributes
    public function parameters(): array {
        return (new SchemaGenerator())->generateFromClass(static::class);
    }
}

// NEW: MCP tools
class McpTool implements ToolInterface {
    public function __construct(
        private readonly McpClient $client,
        private readonly array $schema,
    ) {}

    public function execute(array $params): mixed {
        return $this->client->callTool($this->name(), $params);
    }
}

// Existing: Manual tools
class FileRead extends Tool {
    public function parameters(): array {
        return ['type' => 'object', ...]; // Manual schema
    }
}
```

#### Unified API

```php
// All tool types work the same way for users
agent('assistant')
    ->tool(new FileRead())                    // Manual tool
    ->tool(new CalculatorTool())              // Attribute-based tool
    ->mcpServer('fs', [...])                  // MCP tools (auto-registered)
    ->prompt('List files and calculate total size');
```

**Validation:** All tool types must pass the same test suite for consistency.

---

### 5. Workflow Orchestration + Events/Observability

**Issue:** Workflows need telemetry spans for each step.

**Recommendation:** **Workflows fire events, TelemetryEventBridge creates spans automatically**

#### Workflow Events

Add to events plan:

```php
// New workflow events
namespace Pagent\Events\Events\Workflow;

class WorkflowStartedEvent extends Event {
    public function __construct(
        public readonly string $workflowName,
        public readonly string $workflowType, // 'chain', 'pipeline', 'workflow', 'graph', 'parallel'
        public readonly mixed $input,
    ) {}
}

class WorkflowStepExecutingEvent extends Event {
    public function __construct(
        public readonly string $workflowName,
        public readonly string $stepName,
        public readonly mixed $input,
    ) {}
}

class WorkflowStepExecutedEvent extends Event {
    public function __construct(
        public readonly string $workflowName,
        public readonly string $stepName,
        public readonly mixed $result,
        public readonly float $duration,
    ) {}
}

class WorkflowCompletedEvent extends Event {
    public function __construct(
        public readonly string $workflowName,
        public readonly WorkflowResult $result,
        public readonly float $duration,
    ) {}
}
```

#### TelemetryEventBridge Updates

```php
// In TelemetryEventBridge
public function handle(Event $event): void
{
    match (true) {
        $event instanceof WorkflowStartedEvent => $this->handleWorkflowStart($event),
        $event instanceof WorkflowStepExecutingEvent => $this->handleStepStart($event),
        $event instanceof WorkflowStepExecutedEvent => $this->handleStepEnd($event),
        $event instanceof WorkflowCompletedEvent => $this->handleWorkflowEnd($event),
        // ... other events
    };
}

private function handleWorkflowStart(WorkflowStartedEvent $event): void
{
    $span = TelemetryManager::instance()->startSpan('workflow.execute', [
        'workflow.name' => $event->workflowName,
        'workflow.type' => $event->workflowType,
    ]);

    $this->spanStack[$event->workflowName] = $span;
}
```

**Result:** Clean separation - workflows focus on execution, telemetry happens via events.

---

## Recommended Implementation Order

### Phase 1: Foundation (v0.7.0 - Week 1-2)

```
Priority Order:
1. ✅ HTTP Client Migration (technical debt, 4-6 hrs) - DO FIRST
2. Events/Hooks System (6-8 hrs) - FOUNDATION
3. TOON Integration (3-4 hrs) - Independent, easy win
4. Attribute-Based Tools (6-8 hrs) - Builds on TOON

Total: ~22-30 hours
```

**Rationale:**

- HTTP client migration removes technical debt before adding features
- Events system becomes foundation for all observability
- TOON and attributes are independent, can be parallel

### Phase 2: Observability (v0.7.0 - Week 3-4)

```
Priority Order:
5. OpenTelemetry Exporters (3-4 hrs) - Uses events from Phase 1
6. TelemetryEventBridge (included in events system)
7. Cost & Token Tracking (18-24 hrs) - Integrates with OpenTelemetry
8. MCP Server Support (6-8 hrs) - Independent, can be parallel

Total: ~27-36 hours
```

**Rationale:**

- Observability built on events from Phase 1
- Cost tracking provides data for observability
- MCP independent, can run concurrently

### Phase 3: Advanced Features (v0.8.0 - Month 2)

```
Priority Order:
9. Workflow Orchestration (10-15 hrs) - Uses events for telemetry
10. Events for Workflow (included above)
11. Documentation & Examples (4-6 hrs)

Total: ~14-21 hours
```

**Total Estimated Effort:** 63-87 hours (~2-3 months part-time or 2-3 weeks full-time)

---

## API Consistency Guidelines

### 1. Fluent Method Naming

**Established Patterns:**

```php
// Configuration methods (return $this)
->provider()
->model()
->temperature()
->maxTokens()
->system()
->tool()
->guard()
->middleware()

// Feature toggle methods
->telemetry(bool $enabled = true)
->trackUsage(array|bool $config = true)
->useToon(bool $enabled = true)

// Action methods (return results)
->prompt(string $message)
->streamTo(string $message, Closure $handler)
->evaluate(EvaluationSet $set)
```

**New Additions (maintain consistency):**

```php
// Good (follows pattern)
->mcpServer(string $name, array $config)  ✅
->on(string $event, Closure $handler)     ✅
->toonOptions(EncodeOptions $opts)        ✅

// Bad (breaks pattern)
->addMcpServer()                          ❌ (redundant "add")
->registerEventListener()                 ❌ (too verbose)
->setToonEncoding()                       ❌ (avoid "set")
```

### 2. Configuration Arrays vs Objects

**Guideline:** Arrays for simple config, objects for complex config with validation.

```php
// Simple config → array
agent()->trackUsage([
    'budget' => 10.00,
    'warn_at' => 0.8,
]);

// Complex config → object
agent()->toonOptions(
    EncodeOptions::compact()
);

// Server config → object (has validation logic)
agent()->mcpServer('fs', [
    'transport' => 'stdio',
    'command' => 'npx',
    'args' => [...],
]);
```

### 3. Event Naming Convention

**Pattern:** `{noun}_{past_tense_verb}` or `{preposition}_{noun}`

```php
// Good
'before_prompt'        ✅
'after_prompt'         ✅
'tool_executed'        ✅
'guard_violated'       ✅
'workflow_completed'   ✅

// Bad
'prompt_before'        ❌ (wrong order)
'tool_execute'         ❌ (not past tense)
'guard_violation'      ❌ (not past tense)
```

### 4. Exception Naming

**Pattern:** `{Feature}Exception` for base, `{Feature}{Specific}Exception` for subtypes

```php
// Good hierarchy
McpException
├── McpConnectionException
├── McpTimeoutException
└── McpProtocolException

TelemetryException
├── TelemetryConfigurationException
└── TelemetryExporterException

// Bad
MCPError                    ❌ (acronym capitalization)
McpConnectionFailure        ❌ (not "Exception" suffix)
ConnectionException         ❌ (too generic, no feature prefix)
```

---

## Configuration Consolidation

### Single Configuration Entry Point

```php
// Recommended: Consolidate common config patterns

// Global config (optional, stored in AGENTS.md or config file)
Pagent::configure([
    'default_provider' => 'anthropic',
    'observability' => [
        'enabled' => true,
        'exporter' => 'jaeger',
        'jaeger' => ['endpoint' => 'http://localhost:14268/api/traces'],
    ],
    'usage_tracking' => [
        'enabled' => true,
        'storage' => 'sqlite',
        'database' => 'storage/usage.db',
    ],
    'toon' => [
        'enabled' => false, // Opt-in
        'options' => 'compact',
    ],
]);

// Per-agent overrides
agent('custom')
    ->telemetry(true)  // Override global
    ->trackUsage(['budget' => 5.00])  // Override global
    ->useToon(true)
    ->prompt('Hello');
```

**Implementation:** Add `Pagent::configure()` static method in v0.7.0.

---

## Breaking Changes & Migration

### v0.7.0 → v0.8.0 Breaking Changes

1. **Events System Introduction**
   - **Change:** Manual `TelemetryManager::startSpan()` deprecated
   - **Migration:** Use event listeners instead
   - **Timeline:** Deprecation warnings in v0.8.0, removal in v0.9.0

2. **Cost Tracking Integration**
   - **Change:** OpenTelemetry spans include cost attributes automatically
   - **Migration:** No action required (backward compatible)

### v0.8.0 → v0.9.0 Breaking Changes

1. **Events-Only Telemetry**
   - **Change:** Remove manual span creation code from `Agent.php`
   - **Migration:** Must use events system for custom telemetry
   - **Upgrade Path:** Documented in migration guide

---

## Testing Strategy for Integration

### Cross-Feature Integration Tests

```php
// tests/Integration/FeatureIntegrationTest.php

it('usage tracking works with OpenTelemetry', function () {
    $agent = agent('test')
        ->provider('mock')
        ->trackUsage()
        ->telemetry(true)
        ->prompt('Hello');

    $usage = $agent->getUsage();
    expect($usage->totalTokens)->toBeGreaterThan(0);
    expect($usage->cost)->toBeGreaterThan(0);

    // Verify telemetry span has cost attributes
    $spans = TelemetryManager::instance()->getExportedSpans();
    expect($spans[0]->attributes['gen_ai.cost.usd'])->toBe($usage->cost);
});

it('TOON encoding works with attribute-based tools', function () {
    $tool = new CalculatorTool(); // Attribute-based

    $agent = agent('test')
        ->provider('mock')
        ->useToon(true)
        ->tool($tool)
        ->prompt('Calculate 5 + 3');

    // Verify tool schema was encoded in TOON format
    $sentMessages = MockProvider::getSentMessages();
    expect($sentMessages[0]['tools'][0])->toContain('TOON-formatted schema');
});

it('MCP tools work with events and observability', function () {
    EventManager::instance()->listen(new TelemetryEventBridge());

    $agent = agent('test')
        ->provider('mock')
        ->mcpServer('fs', ['transport' => 'stdio', ...])
        ->telemetry(true)
        ->prompt('List files');

    // Verify MCP tool execution created events
    $events = EventManager::instance()->getDispatchedEvents();
    expect($events)->toContain(ToolExecutedEvent::class);

    // Verify telemetry span created for tool call
    $spans = TelemetryManager::instance()->getExportedSpans();
    expect($spans)->toHaveSpan('tool.execute');
});

it('workflows fire events and create telemetry spans', function () {
    EventManager::instance()->listen(new TelemetryEventBridge());

    $result = Workflow::create()
        ->start(agent('step1'))
        ->then(agent('step2'))
        ->run('input');

    // Verify workflow events fired
    $events = EventManager::instance()->getDispatchedEvents();
    expect($events)->toContain(WorkflowStartedEvent::class);
    expect($events)->toContain(WorkflowStepExecutedEvent::class);
    expect($events)->toContain(WorkflowCompletedEvent::class);

    // Verify telemetry spans created
    $spans = TelemetryManager::instance()->getExportedSpans();
    expect($spans)->toHaveSpan('workflow.execute');
    expect($spans)->toHaveSpan('workflow.step');
});
```

---

## Documentation Structure

### User-Facing Docs

```
docs/
├── getting-started.md           # Updated with new features
├── tools.md                      # Updated with attributes + MCP
│   ├── Manual Tools
│   ├── Attribute-Based Tools (NEW)
│   ├── MCP Tools (NEW)
│   └── TOON Encoding (NEW)
├── observability.md              # Comprehensive guide
│   ├── OpenTelemetry
│   ├── Events System
│   ├── Cost Tracking
│   └── Integration Examples
├── workflows.md                  # Orchestration patterns
│   ├── Chain
│   ├── Pipeline
│   ├── Workflow (branching)
│   ├── Graph (DAG)
│   └── Parallel
└── advanced/
    ├── events-hooks.md           # Events system deep dive
    ├── cost-optimization.md      # TOON + cost tracking
    └── mcp-integration.md        # MCP servers guide
```

### Integration Examples

```
examples/
├── 12-cost-tracking.php
├── 13-budget-limits.php
├── 14-toon-tools.php
├── 15-attribute-tools.php
├── 16-mcp-filesystem.php
├── 17-mcp-multi-server.php
├── 18-telemetry-console.php
├── 19-telemetry-jaeger.php
├── 20-events-custom-listener.php
├── 21-full-stack-observability.php  # NEW: All features together
└── 22-production-setup.php          # NEW: Complete production config
```

---

## Action Items

### For Implementation Teams

- [ ] **Events Team:** Add workflow events to events plan
- [ ] **OpenTelemetry Team:** Add cost attributes to semantic conventions
- [ ] **Cost Tracking Team:** Ensure `UsageData` available in events
- [ ] **TOON Team:** Add section explaining integration with attribute tools
- [ ] **Attribute Tools Team:** Add section explaining TOON integration
- [ ] **MCP Team:** Ensure `McpTool` fully implements `ToolInterface`
- [ ] **Workflow Team:** Fire events for all workflow operations
- [ ] **Documentation Team:** Create integration examples (examples 21-22)

### Timeline Adjustments

**Recommended:**

1. Move Events/Hooks from v0.8.0 → v0.7.0 (implement first)
2. Build OpenTelemetry on events foundation
3. Ensure cost tracking integrates with OpenTelemetry
4. Document all integration points

---

## Success Criteria

### API Consistency

- [ ] All fluent methods follow naming conventions
- [ ] All exceptions follow naming patterns
- [ ] All events follow naming conventions
- [ ] Configuration patterns consistent across features

### Integration

- [ ] Cost tracking data available in OpenTelemetry spans
- [ ] Events system foundation for all observability
- [ ] TOON works with both manual and attribute tools
- [ ] MCP tools seamlessly integrate with existing tool system
- [ ] Workflows fire events and integrate with telemetry

### Documentation

- [ ] Integration examples for all feature combinations
- [ ] Migration guides for breaking changes
- [ ] Clear positioning of complementary features (TOON + attributes)
- [ ] Production setup guide showing all features together

### Testing

- [ ] Integration tests for cross-feature compatibility
- [ ] All plans have consistent test coverage (90%+)
- [ ] PHPStan level 9 maintained across all features

---

**Created:** 2025-10-30
**Status:** Complete - Ready for Implementation
**Next Steps:** Update individual plans based on recommendations
