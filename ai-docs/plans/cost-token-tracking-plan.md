# Cost and Token Tracking Implementation Plan

**Created:** 2025-10-29
**Target Version:** v0.7.0
**Estimated Effort:** 18-24 hours
**Priority:** High
**Status:** Planned

---

## Goal

Implement comprehensive cost and token usage tracking for Pagent agents, enabling developers to monitor, analyze, and control LLM costs across single conversations, sessions, and entire applications. This feature will provide real-time visibility into token consumption and associated costs, support budget enforcement, and enable detailed usage analytics.

---

## Background

LLM API costs can accumulate quickly, especially in production applications with high traffic. Developers need visibility into:

- Token usage (input tokens, output tokens, cached tokens)
- Real-time cost calculations based on provider pricing
- Budget limits and warnings to prevent cost overruns
- Historical tracking for analytics and optimization
- Per-agent, per-session, and application-wide usage metrics

Currently, Pagent returns basic token counts in response objects but lacks:
- Cost calculation capabilities
- Usage aggregation and persistence
- Budget enforcement mechanisms
- Historical tracking and reporting
- Support for provider-specific pricing models (e.g., Anthropic's prompt caching)

This feature will integrate seamlessly with Pagent's existing architecture while remaining optional for users who don't need cost tracking.

---

## Scope

### In Scope

- **Token Tracking**: Input, output, cached (Anthropic), and total token counts
- **Cost Calculation**: Provider-specific pricing models with configurable rates
- **Budget Enforcement**: Soft warnings and hard limits (throw exceptions)
- **Usage Aggregation**: Per-conversation, per-session, per-agent, and global
- **Persistence**: In-memory, SQLite, File, and custom storage adapters
- **Fluent API**: Chainable methods for easy configuration
- **Provider Support**: Anthropic, OpenAI, Ollama (free), Mock (test)
- **Caching Attribution**: Detect and cost Anthropic prompt cache hits
- **Export Capabilities**: JSON, CSV, and SQLite formats
- **Historical Tracking**: Time-series data for analytics
- **Callbacks/Events**: User-defined handlers for budget warnings

### Out of Scope

- Real-time cost notifications via external services (email, SMS, webhooks)
- Advanced analytics dashboards (users can build their own)
- Automatic cost optimization recommendations
- Multi-currency support (USD only)
- Integration with billing systems
- Historical data retention policies (user's responsibility)
- OpenTelemetry integration (separate feature in v0.7.0)

---

## Implementation Phases

### Phase 1: Core Tracking System (Estimated: 6-8 hours)

- [ ] Create `UsageData` DTO with token and cost fields
- [ ] Create `UsageTracker` class for aggregation
- [ ] Create `PricingModel` interface and implementations
- [ ] Implement provider-specific pricing models
- [ ] Add usage tracking to `Agent::prompt()` method
- [ ] Add usage tracking to `Agent::streamTo()` method
- [ ] Create basic in-memory storage

**Deliverables:**

- `src/Usage/UsageData.php` - Token/cost data structure
- `src/Usage/UsageTracker.php` - Core tracking logic
- `src/Contracts/PricingModel.php` - Pricing interface
- `src/Usage/Pricing/AnthropicPricing.php` - Claude pricing
- `src/Usage/Pricing/OpenAIPricing.php` - GPT pricing
- `src/Usage/Pricing/OllamaPricing.php` - Local (free)
- `src/Usage/Pricing/MockPricing.php` - Testing

### Phase 2: Budget Enforcement & Callbacks (Estimated: 4-5 hours)

- [ ] Implement `BudgetLimits` configuration class
- [ ] Add budget checking to `UsageTracker`
- [ ] Create `BudgetExceededException` for hard limits
- [ ] Implement warning callbacks at configurable thresholds
- [ ] Add budget methods to `Agent` fluent API
- [ ] Support conversation-level and session-level budgets
- [ ] Add tests for budget enforcement scenarios

**Deliverables:**

- `src/Usage/BudgetLimits.php` - Budget configuration
- `src/Exceptions/BudgetExceededException.php` - Exception
- `Agent::trackUsage()` - Enable tracking with config
- `Agent::onBudgetWarning()` - Callback registration
- Unit tests for budget scenarios

### Phase 3: Persistence & Storage (Estimated: 3-4 hours)

- [ ] Create `UsageStorage` interface
- [ ] Implement `InMemoryUsageStorage` (default)
- [ ] Implement `SqliteUsageStorage` (production)
- [ ] Implement `FileUsageStorage` (development)
- [ ] Add custom storage support
- [ ] Implement automatic persistence on usage update
- [ ] Add migration support for SQLite schema

**Deliverables:**

- `src/Contracts/UsageStorage.php` - Storage interface
- `src/Usage/Storage/InMemoryUsageStorage.php` - Default
- `src/Usage/Storage/SqliteUsageStorage.php` - Production
- `src/Usage/Storage/FileUsageStorage.php` - Development
- Migration scripts for SQLite tables

### Phase 4: Querying & Reporting (Estimated: 3-4 hours)

- [ ] Implement usage query API for retrieving historical data
- [ ] Add aggregation methods (by agent, by session, by date)
- [ ] Create `UsageReport` class for formatted output
- [ ] Implement export to JSON, CSV, SQLite
- [ ] Add statistical methods (average, min, max, total)
- [ ] Create usage summary methods
- [ ] Add time-range filtering

**Deliverables:**

- `src/Usage/UsageQuery.php` - Query builder
- `src/Usage/UsageReport.php` - Report generation
- `UsageTracker::summary()` - Quick stats
- `UsageTracker::byAgent()` - Agent breakdown
- `UsageTracker::bySession()` - Session breakdown
- `UsageTracker::exportJson()` - JSON export
- `UsageTracker::exportCsv()` - CSV export

### Phase 5: Testing & Documentation (Estimated: 2-3 hours)

- [ ] Unit tests for all core components (90%+ coverage)
- [ ] Integration tests with real provider responses
- [ ] Budget enforcement edge case tests
- [ ] Pricing calculation accuracy tests
- [ ] Storage persistence tests
- [ ] Create usage tracking documentation
- [ ] Add code examples to docs
- [ ] Update README with feature overview

**Deliverables:**

- `tests/Unit/Usage/*Test.php` - Unit tests
- `tests/Integration/UsageTrackingTest.php` - Integration tests
- `docs/usage-tracking.md` - Complete guide
- `examples/13-cost-tracking.php` - Working example
- `examples/14-budget-limits.php` - Budget example
- Updated README.md with feature section

---

## Technical Approach

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                         Agent                                │
│  - trackUsage(config)                                        │
│  - getUsage() → UsageData                                    │
│  - onBudgetWarning(callback)                                 │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ↓
         ┌────────────────┐
         │ UsageTracker   │ ← tracks all agents
         │                │
         │ - track()      │
         │ - getUsage()   │
         │ - checkBudget()│
         └────┬───────────┘
              │
      ┌───────┴───────┬─────────────┐
      ↓               ↓             ↓
┌──────────┐   ┌─────────────┐  ┌──────────────┐
│ UsageData│   │PricingModel │  │UsageStorage  │
│          │   │             │  │              │
│- tokens  │   │- calculate()│  │- save()      │
│- cost    │   │             │  │- load()      │
│- metadata│   │             │  │- query()     │
└──────────┘   └─────────────┘  └──────────────┘
                     ↑                  ↑
          ┌──────────┼──────────┐      │
          │          │          │      │
    ┌─────┴─┐  ┌────┴───┐  ┌───┴──┐  ├─────┬─────┐
    │Anthropic OpenAI  │Ollama │   │SQLite│File │Memory│
    │Pricing │ Pricing │Pricing│   └──────┴─────┴──────┘
    └────────┘ └────────┘└───────┘
```

### Key Components

1. **UsageData** - Immutable DTO holding usage information
   - Input tokens, output tokens, cached tokens
   - Calculated cost (in USD)
   - Provider name, model name
   - Timestamp, agent name, session ID
   - Metadata (stop reason, finish reason, etc.)

2. **UsageTracker** - Central tracking and aggregation
   - Singleton pattern for global tracking
   - Per-agent instance tracking
   - Budget checking and warnings
   - Storage coordination
   - Query interface

3. **PricingModel** - Provider-specific cost calculation
   - Interface for extensibility
   - Concrete implementations per provider
   - Support for tiered pricing (e.g., GPT-4 vs GPT-3.5)
   - Caching cost detection (Anthropic)
   - Custom pricing override support

4. **UsageStorage** - Persistence layer
   - Interface for custom implementations
   - In-memory (default, no persistence)
   - SQLite (production-ready)
   - File-based (JSON, development)
   - Query capabilities

5. **BudgetLimits** - Budget enforcement
   - Soft limits (warnings via callbacks)
   - Hard limits (exceptions)
   - Per-conversation, per-session, global
   - Percentage-based warnings (e.g., 80%)

---

## API Design

### Basic Usage Tracking

```php
use Pagent\Usage\UsageTracker;

// Enable tracking for an agent
$response = agent('support-bot')
    ->trackUsage()  // Enable with defaults
    ->prompt('Hello, how can I help?');

// Access usage data
$usage = agent('support-bot')->getUsage();
echo "Tokens: {$usage->totalTokens}, Cost: \${$usage->cost}";

// Global usage across all agents
$totalCost = UsageTracker::global()->getTotalCost();
$totalTokens = UsageTracker::global()->getTotalTokens();
```

### Budget Limits

```php
use Pagent\Usage\BudgetLimits;
use Pagent\Exceptions\BudgetExceededException;

// Conversation-level budget (single agent instance)
agent('assistant')
    ->trackUsage([
        'budget' => 5.00,        // $5 max
        'warn_at' => 0.8,        // Warn at 80%
    ])
    ->onBudgetWarning(function ($usage) {
        Log::warning("Budget at {$usage->percentUsed}%");
    })
    ->prompt('Hello');

// Session-level budget (across multiple conversations)
agent('assistant')
    ->sessionId('user-123')
    ->sessionBudget(10.00)
    ->trackUsage()
    ->prompt('First message');

// This continues the session budget
agent('assistant')
    ->sessionId('user-123')
    ->trackUsage()
    ->prompt('Second message');  // Accumulates against same $10 budget

// Hard limit throws exception
try {
    agent('assistant')
        ->trackUsage(['budget' => 1.00])
        ->prompt('Very long prompt...');  // Exceeds budget
} catch (BudgetExceededException $e) {
    echo "Budget exceeded: {$e->getMessage()}";
    echo "Current cost: {$e->currentCost}";
    echo "Budget limit: {$e->budgetLimit}";
}
```

### Custom Pricing

```php
use Pagent\Usage\Pricing\CustomPricing;

// Override default pricing
agent('assistant')
    ->trackUsage([
        'pricing' => new CustomPricing([
            'input_per_mtok' => 3.00,   // $3 per million input tokens
            'output_per_mtok' => 15.00, // $15 per million output tokens
        ])
    ])
    ->prompt('Hello');

// Model-specific pricing
agent('assistant')
    ->model('gpt-4-turbo')
    ->trackUsage([
        'pricing' => [
            'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
            'gpt-4' => ['input' => 30.00, 'output' => 60.00],
        ]
    ])
    ->prompt('Hello');
```

### Persistence

```php
use Pagent\Usage\Storage\SqliteUsageStorage;
use Pagent\Usage\Storage\FileUsageStorage;

// SQLite storage (production)
agent('assistant')
    ->trackUsage([
        'storage' => new SqliteUsageStorage([
            'database' => '/path/to/usage.db',
        ])
    ])
    ->prompt('Hello');

// File storage (development)
agent('assistant')
    ->trackUsage([
        'storage' => new FileUsageStorage([
            'path' => storage_path('usage/'),
        ])
    ])
    ->prompt('Hello');

// Custom storage
class RedisUsageStorage implements UsageStorage {
    public function save(UsageData $usage): void { /* ... */ }
    public function load(string $agentName): array { /* ... */ }
    public function query(): UsageQuery { /* ... */ }
}

agent('assistant')
    ->trackUsage(['storage' => new RedisUsageStorage()])
    ->prompt('Hello');
```

### Querying and Reporting

```php
use Pagent\Usage\UsageTracker;
use Carbon\Carbon;

// Get usage summary
$summary = UsageTracker::global()->summary();
// [
//     'total_requests' => 1523,
//     'total_tokens' => 1_234_567,
//     'total_cost' => 45.67,
//     'avg_cost_per_request' => 0.03,
//     'agents' => ['assistant', 'translator', 'analyzer'],
// ]

// Query by agent
$agentUsage = UsageTracker::global()
    ->byAgent('assistant')
    ->last(24, 'hours')
    ->get();

foreach ($agentUsage as $usage) {
    echo "{$usage->timestamp}: {$usage->cost} USD\n";
}

// Query by session
$sessionUsage = UsageTracker::global()
    ->bySession('user-123')
    ->between(Carbon::yesterday(), Carbon::now())
    ->get();

// Query by date range
$monthlyUsage = UsageTracker::global()
    ->between(
        Carbon::now()->startOfMonth(),
        Carbon::now()->endOfMonth()
    )
    ->groupBy('day')
    ->get();

// Export to JSON
$json = UsageTracker::global()
    ->byAgent('assistant')
    ->last(7, 'days')
    ->exportJson();

file_put_contents('usage-report.json', $json);

// Export to CSV
$csv = UsageTracker::global()
    ->last(30, 'days')
    ->exportCsv();

file_put_contents('usage-report.csv', $csv);

// Generate HTML report
$report = UsageTracker::global()
    ->last(7, 'days')
    ->report()
    ->toHtml();

echo $report; // Formatted HTML with charts
```

### Integration with Streaming

```php
// Streaming automatically tracks usage when enabled
$content = agent('assistant')
    ->trackUsage()
    ->streamTo('Translate to Spanish', function ($chunk) {
        echo $chunk->content;
    });

// Usage available after stream completes
$usage = agent('assistant')->getUsage();
echo "Stream cost: \${$usage->cost}";
```

### Global Configuration

```php
use Pagent\Usage\UsageTracker;

// Enable tracking globally for all agents
UsageTracker::enableGlobal([
    'storage' => new SqliteUsageStorage(['database' => 'usage.db']),
    'default_budget' => 100.00,
    'warn_at' => 0.85,
]);

// Now all agents track automatically
agent('bot1')->prompt('Hello');
agent('bot2')->prompt('World');

// Access global stats
$stats = UsageTracker::global()->summary();
```

---

## Provider-Specific Details

### Anthropic Claude

**Pricing (as of 2025-10-29):**

| Model                  | Input (per 1M tokens) | Output (per 1M tokens) | Cache Write | Cache Read |
| ---------------------- | --------------------- | ---------------------- | ----------- | ---------- |
| claude-sonnet-4        | $3.00                 | $15.00                 | $3.75       | $0.30      |
| claude-opus-4          | $15.00                | $75.00                 | $18.75      | $1.50      |
| claude-3.5-sonnet      | $3.00                 | $15.00                 | $3.75       | $0.30      |
| claude-3.5-haiku       | $1.00                 | $5.00                  | $1.25       | $0.10      |

**Token Usage Fields:**

```php
// Anthropic response includes:
$data['usage'] = [
    'input_tokens' => 150,
    'output_tokens' => 200,
    'cache_creation_input_tokens' => 1000, // Writing to cache
    'cache_read_input_tokens' => 500,      // Reading from cache
];
```

**Cost Calculation:**

```php
class AnthropicPricing implements PricingModel
{
    private const PRICING = [
        'claude-sonnet-4-20250514' => [
            'input' => 3.00,
            'output' => 15.00,
            'cache_write' => 3.75,
            'cache_read' => 0.30,
        ],
        // ... more models
    ];

    public function calculate(array $usage, string $model): float
    {
        $pricing = self::PRICING[$model] ?? self::PRICING['claude-sonnet-4-20250514'];

        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cacheWrite = $usage['cache_creation_input_tokens'] ?? 0;
        $cacheRead = $usage['cache_read_input_tokens'] ?? 0;

        // Subtract cache tokens from regular input
        $regularInput = $inputTokens - $cacheWrite - $cacheRead;

        $cost = 0;
        $cost += ($regularInput / 1_000_000) * $pricing['input'];
        $cost += ($outputTokens / 1_000_000) * $pricing['output'];
        $cost += ($cacheWrite / 1_000_000) * $pricing['cache_write'];
        $cost += ($cacheRead / 1_000_000) * $pricing['cache_read'];

        return round($cost, 6); // 6 decimal places for accuracy
    }
}
```

### OpenAI GPT

**Pricing (as of 2025-10-29):**

| Model           | Input (per 1M tokens) | Output (per 1M tokens) |
| --------------- | --------------------- | ---------------------- |
| gpt-4-turbo     | $10.00                | $30.00                 |
| gpt-4           | $30.00                | $60.00                 |
| gpt-3.5-turbo   | $0.50                 | $1.50                  |
| gpt-4o          | $5.00                 | $15.00                 |
| gpt-4o-mini     | $0.15                 | $0.60                  |

**Token Usage Fields:**

```php
// OpenAI response includes:
$data['usage'] = [
    'prompt_tokens' => 150,
    'completion_tokens' => 200,
    'total_tokens' => 350,
];
```

**Cost Calculation:**

```php
class OpenAIPricing implements PricingModel
{
    private const PRICING = [
        'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        'gpt-4' => ['input' => 30.00, 'output' => 60.00],
        'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
        'gpt-4o' => ['input' => 5.00, 'output' => 15.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
    ];

    public function calculate(array $usage, string $model): float
    {
        // Extract base model name (remove dates, versions)
        $baseModel = $this->normalizeModelName($model);
        $pricing = self::PRICING[$baseModel] ?? self::PRICING['gpt-3.5-turbo'];

        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;

        $cost = 0;
        $cost += ($inputTokens / 1_000_000) * $pricing['input'];
        $cost += ($outputTokens / 1_000_000) * $pricing['output'];

        return round($cost, 6);
    }

    private function normalizeModelName(string $model): string
    {
        // 'gpt-4-turbo-2024-04-09' -> 'gpt-4-turbo'
        if (preg_match('/^(gpt-[^-]+-[^-]+)/', $model, $matches)) {
            return $matches[1];
        }
        return $model;
    }
}
```

### Ollama (Local LLMs)

**Pricing:** Free (running locally)

**Token Usage Fields:**

```php
// Ollama response includes:
$data['prompt_eval_count'] = 150;    // Input tokens
$data['eval_count'] = 200;           // Output tokens
```

**Cost Calculation:**

```php
class OllamaPricing implements PricingModel
{
    public function calculate(array $usage, string $model): float
    {
        // Ollama is free (local execution)
        return 0.0;
    }
}
```

**Note:** While Ollama is free in terms of API costs, users may want to track token usage for:
- Comparing local vs. cloud costs
- Monitoring resource usage
- Testing applications before deploying to paid providers

### Mock Provider

**Pricing:** Configurable for testing

**Cost Calculation:**

```php
class MockPricing implements PricingModel
{
    private float $costPerToken;

    public function __construct(float $costPerToken = 0.001)
    {
        $this->costPerToken = $costPerToken;
    }

    public function calculate(array $usage, string $model): float
    {
        $totalTokens = $usage['total_tokens'] ?? 0;
        return $totalTokens * $this->costPerToken;
    }
}
```

---

## Data Models

### UsageData DTO

```php
namespace Pagent\Usage;

final readonly class UsageData
{
    public function __construct(
        public string $agentName,
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedTokens,      // Anthropic only
        public int $totalTokens,
        public float $cost,              // USD
        public float $timestamp,
        public ?string $sessionId,
        public array $metadata,          // Additional context
    ) {}

    public static function fromResponse(
        string $agentName,
        object $response,
        float $cost,
        ?string $sessionId = null
    ): self {
        $usage = $response->usage ?? [];

        return new self(
            agentName: $agentName,
            provider: $response->provider,
            model: $response->model,
            inputTokens: $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0,
            cachedTokens: ($usage['cache_creation_input_tokens'] ?? 0)
                        + ($usage['cache_read_input_tokens'] ?? 0),
            totalTokens: $response->tokens ?? 0,
            cost: $cost,
            timestamp: microtime(true),
            sessionId: $sessionId,
            metadata: [
                'stop_reason' => $response->stop_reason ?? $response->finish_reason ?? null,
                'has_tool_calls' => !empty($response->tool_calls ?? []),
            ],
        );
    }

    public function toArray(): array
    {
        return [
            'agent_name' => $this->agentName,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cached_tokens' => $this->cachedTokens,
            'total_tokens' => $this->totalTokens,
            'cost' => $this->cost,
            'timestamp' => $this->timestamp,
            'session_id' => $this->sessionId,
            'metadata' => $this->metadata,
        ];
    }
}
```

### BudgetLimits Configuration

```php
namespace Pagent\Usage;

final readonly class BudgetLimits
{
    public function __construct(
        public ?float $maxCost = null,           // Hard limit (exception)
        public ?float $warningThreshold = 0.8,   // Soft limit (callback)
        public ?int $maxTokens = null,           // Token-based limit
        public bool $enabled = true,
    ) {}

    public function shouldWarn(float $currentCost, float $budget): bool
    {
        if ($this->warningThreshold === null) {
            return false;
        }

        $percentUsed = $currentCost / $budget;
        return $percentUsed >= $this->warningThreshold;
    }

    public function shouldBlock(float $currentCost, float $budget): bool
    {
        if ($this->maxCost === null) {
            return false;
        }

        return $currentCost >= $budget;
    }
}
```

---

## Test Strategy

### Unit Tests

1. **UsageData Creation and Serialization**
   - Test DTO construction
   - Test `fromResponse()` factory method
   - Test `toArray()` serialization
   - Test with different provider response formats

2. **PricingModel Calculations**
   - Test each provider's pricing calculation
   - Test with real pricing examples (verify exact costs)
   - Test cache token cost attribution (Anthropic)
   - Test model name normalization (OpenAI)
   - Test edge cases (zero tokens, negative values)

3. **UsageTracker Aggregation**
   - Test tracking single conversation
   - Test tracking multiple conversations
   - Test per-agent aggregation
   - Test per-session aggregation
   - Test global aggregation

4. **Budget Enforcement**
   - Test soft limits (warnings)
   - Test hard limits (exceptions)
   - Test warning thresholds (80%, 90%, etc.)
   - Test budget checking at different stages
   - Test callback invocation

5. **Storage Implementations**
   - Test in-memory storage (default)
   - Test SQLite storage (CRUD operations)
   - Test file storage (JSON persistence)
   - Test custom storage interface

6. **Query and Reporting**
   - Test date range queries
   - Test agent filtering
   - Test session filtering
   - Test aggregation methods
   - Test export formats (JSON, CSV)

### Integration Tests

1. **End-to-End with Real Provider Responses**
   ```php
   it('tracks cost for real Anthropic API call', function (): void {
       $agent = agent('test')
           ->provider('anthropic', ['api_key' => env('ANTHROPIC_API_KEY')])
           ->trackUsage()
           ->model('claude-sonnet-4-20250514')
           ->prompt('Say hello');

       $usage = $agent->getUsage();

       expect($usage->provider)->toBe('anthropic');
       expect($usage->inputTokens)->toBeGreaterThan(0);
       expect($usage->outputTokens)->toBeGreaterThan(0);
       expect($usage->cost)->toBeGreaterThan(0);

       // Verify cost calculation is accurate within 0.01 cents
       $expectedCost = ($usage->inputTokens / 1_000_000) * 3.00
                     + ($usage->outputTokens / 1_000_000) * 15.00;
       expect($usage->cost)->toBeCloseTo($expectedCost, 5);
   })->group('api');
   ```

2. **Budget Enforcement in Real Scenarios**
   ```php
   it('throws exception when budget exceeded', function (): void {
       expect(fn () => agent('test')
           ->provider('anthropic', ['api_key' => env('ANTHROPIC_API_KEY')])
           ->trackUsage(['budget' => 0.0001]) // Very low budget
           ->prompt('Write a long essay about PHP')
       )->toThrow(BudgetExceededException::class);
   })->group('api');
   ```

3. **Session Budget Across Multiple Calls**
   ```php
   it('accumulates session budget across calls', function (): void {
       $sessionId = 'test-session-' . uniqid();

       // First call
       agent('test')
           ->provider('mock')
           ->sessionId($sessionId)
           ->trackUsage(['storage' => new InMemoryUsageStorage()])
           ->prompt('Hello');

       $usage1 = UsageTracker::global()->bySession($sessionId)->total();

       // Second call
       agent('test')
           ->provider('mock')
           ->sessionId($sessionId)
           ->trackUsage(['storage' => new InMemoryUsageStorage()])
           ->prompt('World');

       $usage2 = UsageTracker::global()->bySession($sessionId)->total();

       expect($usage2->totalTokens)->toBeGreaterThan($usage1->totalTokens);
       expect($usage2->cost)->toBeGreaterThan($usage1->cost);
   });
   ```

4. **Persistent Storage with SQLite**
   ```php
   it('persists usage to SQLite database', function (): void {
       $dbPath = sys_get_temp_dir() . '/pagent-test-' . uniqid() . '.db';
       $storage = new SqliteUsageStorage(['database' => $dbPath]);

       agent('test')
           ->provider('mock')
           ->trackUsage(['storage' => $storage])
           ->prompt('Hello');

       // Verify data was saved
       $query = $storage->query()->byAgent('test')->get();

       expect($query)->toHaveCount(1);
       expect($query[0]->agentName)->toBe('test');

       unlink($dbPath);
   });
   ```

### Example Test Cases with Specific Pricing Scenarios

```php
// Anthropic with cache hit
it('calculates Anthropic cost with cache read correctly', function (): void {
    $usage = [
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 800, // 800 from cache
    ];

    $pricing = new AnthropicPricing();
    $cost = $pricing->calculate($usage, 'claude-sonnet-4-20250514');

    // Regular input: 1000 - 800 = 200 tokens @ $3/M = $0.0006
    // Cache read: 800 tokens @ $0.30/M = $0.00024
    // Output: 500 tokens @ $15/M = $0.0075
    // Total: $0.00834

    expect($cost)->toBeCloseTo(0.00834, 5);
});

// OpenAI model name normalization
it('normalizes OpenAI model names for pricing', function (): void {
    $usage = ['prompt_tokens' => 1000, 'completion_tokens' => 500];

    $pricing = new OpenAIPricing();

    // Different model name formats should use same pricing
    $cost1 = $pricing->calculate($usage, 'gpt-4-turbo');
    $cost2 = $pricing->calculate($usage, 'gpt-4-turbo-2024-04-09');
    $cost3 = $pricing->calculate($usage, 'gpt-4-turbo-preview');

    expect($cost1)->toBe($cost2);
    expect($cost1)->toBe($cost3);
});

// Budget warning at 80%
it('triggers warning callback at 80% budget', function (): void {
    $warningTriggered = false;

    agent('test')
        ->provider('mock')
        ->trackUsage(['budget' => 1.00, 'warn_at' => 0.8])
        ->onBudgetWarning(function ($usage) use (&$warningTriggered) {
            $warningTriggered = true;
        })
        ->prompt('Expensive operation'); // Costs $0.85

    expect($warningTriggered)->toBeTrue();
});
```

---

## Migration Path

This feature is designed to be **100% backward compatible** and **opt-in**:

1. **No Breaking Changes**
   - Existing code continues to work unchanged
   - Usage tracking is disabled by default
   - No changes to response objects or agent behavior

2. **Gradual Adoption**
   ```php
   // Step 1: Enable tracking without any enforcement
   agent('bot')->trackUsage()->prompt('Hello');

   // Step 2: Add budget monitoring
   agent('bot')->trackUsage(['warn_at' => 0.9])->prompt('Hello');

   // Step 3: Add hard limits
   agent('bot')->trackUsage(['budget' => 10.00])->prompt('Hello');

   // Step 4: Add persistence
   agent('bot')->trackUsage([
       'budget' => 10.00,
       'storage' => new SqliteUsageStorage(['database' => 'usage.db']),
   ])->prompt('Hello');
   ```

3. **Global Configuration (Optional)**
   ```php
   // Enable for all agents in bootstrap/config
   UsageTracker::enableGlobal([
       'storage' => new SqliteUsageStorage(['database' => 'usage.db']),
       'default_budget' => 100.00,
   ]);

   // Individual agents can opt-out
   agent('bot')->trackUsage(false)->prompt('Hello');
   ```

4. **Testing Applications**
   - Mock provider returns zero cost by default
   - Test applications won't be affected
   - Integration tests can use real providers with tracking

---

## Dependencies

### Existing Dependencies (No New Packages Required)

- `ext-json` - JSON encoding/decoding (already required)
- `ext-sqlite3` or `ext-pdo_sqlite` - SQLite storage (optional)
- `swaggest/json-schema` - Already in use for tool validation

### Optional Dependencies (User's Choice)

- `league/csv` - Better CSV export (user can add)
- `carbon` - Better date handling (user can add)
- `monolog/monolog` - Logging warnings (user can add)

**Decision:** No new required dependencies. Keep the library lightweight.

---

## Data Persistence

### Storage Options

#### 1. In-Memory (Default)

```php
class InMemoryUsageStorage implements UsageStorage
{
    private array $data = [];

    public function save(UsageData $usage): void
    {
        $key = $usage->agentName . ':' . $usage->timestamp;
        $this->data[$key] = $usage;
    }

    public function load(string $agentName): array
    {
        return array_filter(
            $this->data,
            fn($u) => $u->agentName === $agentName
        );
    }

    public function query(): UsageQuery
    {
        return new UsageQuery($this->data);
    }
}
```

**Pros:**
- Fast, no I/O
- No setup required
- Perfect for short-lived scripts

**Cons:**
- Data lost when process ends
- No persistence across requests
- Memory usage grows unbounded

#### 2. SQLite (Production)

```php
class SqliteUsageStorage implements UsageStorage
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = new PDO('sqlite:' . $config['database']);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS pagent_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                agent_name TEXT NOT NULL,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                input_tokens INTEGER NOT NULL,
                output_tokens INTEGER NOT NULL,
                cached_tokens INTEGER NOT NULL,
                total_tokens INTEGER NOT NULL,
                cost REAL NOT NULL,
                timestamp REAL NOT NULL,
                session_id TEXT,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_agent_name ON pagent_usage(agent_name);
            CREATE INDEX IF NOT EXISTS idx_session_id ON pagent_usage(session_id);
            CREATE INDEX IF NOT EXISTS idx_timestamp ON pagent_usage(timestamp);
        ');
    }

    public function save(UsageData $usage): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO pagent_usage (
                agent_name, provider, model, input_tokens, output_tokens,
                cached_tokens, total_tokens, cost, timestamp, session_id, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $usage->agentName,
            $usage->provider,
            $usage->model,
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->cachedTokens,
            $usage->totalTokens,
            $usage->cost,
            $usage->timestamp,
            $usage->sessionId,
            json_encode($usage->metadata),
        ]);
    }

    public function query(): UsageQuery
    {
        return new SqliteUsageQuery($this->db);
    }
}
```

**Pros:**
- Persistent across requests
- Efficient queries and indexing
- Production-ready
- No external service required

**Cons:**
- Requires write permissions
- Single-file locking (not for high concurrency)
- Manual backup required

#### 3. File Storage (Development)

```php
class FileUsageStorage implements UsageStorage
{
    private string $path;

    public function __construct(array $config)
    {
        $this->path = rtrim($config['path'], '/');

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    public function save(UsageData $usage): void
    {
        $filename = $this->path . '/' . date('Y-m-d') . '.json';

        $existing = file_exists($filename)
            ? json_decode(file_get_contents($filename), true)
            : [];

        $existing[] = $usage->toArray();

        file_put_contents(
            $filename,
            json_encode($existing, JSON_PRETTY_PRINT)
        );
    }

    public function query(): UsageQuery
    {
        $data = [];

        foreach (glob($this->path . '/*.json') as $file) {
            $fileData = json_decode(file_get_contents($file), true);
            $data = array_merge($data, $fileData);
        }

        return new UsageQuery($data);
    }
}
```

**Pros:**
- Human-readable JSON
- Easy to inspect and debug
- Simple backup (just copy files)

**Cons:**
- Slow for large datasets
- No indexing or efficient queries
- Concurrent writes can cause issues

#### 4. Custom Storage

Users can implement their own storage:

```php
interface UsageStorage
{
    public function save(UsageData $usage): void;
    public function load(string $agentName): array;
    public function query(): UsageQuery;
}

// Example: Redis
class RedisUsageStorage implements UsageStorage
{
    public function __construct(private Redis $redis) {}

    public function save(UsageData $usage): void
    {
        $key = "pagent:usage:{$usage->agentName}";
        $this->redis->rPush($key, json_encode($usage->toArray()));
        $this->redis->expire($key, 86400 * 30); // 30 days TTL
    }

    // ... implement other methods
}

// Example: MySQL
class MySQLUsageStorage implements UsageStorage
{
    public function __construct(private PDO $db) {}

    // Similar to SQLite but with MySQL-specific SQL
}
```

---

## Code Examples

### Example 1: Basic Cost Tracking

```php
<?php

require 'vendor/autoload.php';

use function Pagent\agent;

// Enable cost tracking for an agent
$agent = agent('translator')
    ->provider('anthropic', ['api_key' => getenv('ANTHROPIC_API_KEY')])
    ->model('claude-sonnet-4-20250514')
    ->trackUsage(); // Enable tracking

// Make a translation request
$response = $agent->prompt('Translate "Hello, world!" to Spanish');

// Access usage information
$usage = $agent->getUsage();

echo "Translation: {$response->content}\n";
echo "Input tokens: {$usage->inputTokens}\n";
echo "Output tokens: {$usage->outputTokens}\n";
echo "Total tokens: {$usage->totalTokens}\n";
echo "Cost: \${$usage->cost}\n";

// Output:
// Translation: ¡Hola, mundo!
// Input tokens: 15
// Output tokens: 8
// Total tokens: 23
// Cost: $0.000165
```

### Example 2: Budget Limits with Warnings

```php
<?php

require 'vendor/autoload.php';

use function Pagent\agent;
use Pagent\Exceptions\BudgetExceededException;

// Create agent with budget limits
$agent = agent('expensive-bot')
    ->provider('anthropic', ['api_key' => getenv('ANTHROPIC_API_KEY')])
    ->trackUsage([
        'budget' => 5.00,        // $5 maximum
        'warn_at' => 0.8,        // Warn at 80% ($4)
    ])
    ->onBudgetWarning(function ($usage) {
        $percent = ($usage->cost / 5.00) * 100;
        echo "⚠️  Budget warning: {$percent}% used (\${$usage->cost}/\$5.00)\n";
    });

try {
    // Make multiple requests
    for ($i = 1; $i <= 10; $i++) {
        $response = $agent->prompt("Request #{$i}");
        $usage = $agent->getUsage();
        echo "Request #{$i}: \${$usage->cost}\n";
    }
} catch (BudgetExceededException $e) {
    echo "❌ Budget exceeded!\n";
    echo "Current cost: \${$e->currentCost}\n";
    echo "Budget limit: \${$e->budgetLimit}\n";
    echo "Last request would have cost: \${$e->attemptedCost}\n";
}
```

### Example 3: Session-Level Budget Tracking

```php
<?php

require 'vendor/autoload.php';

use function Pagent\agent;
use Pagent\Usage\Storage\SqliteUsageStorage;

// Configure storage
$storage = new SqliteUsageStorage([
    'database' => '/path/to/usage.db',
]);

// User starts a conversation
$userId = 'user-12345';

$agent = agent('support-bot')
    ->provider('anthropic')
    ->sessionId($userId)
    ->trackUsage([
        'storage' => $storage,
        'budget' => 10.00, // $10 per user session
    ])
    ->onBudgetWarning(function ($usage) use ($userId) {
        // Send notification to user
        notifyUser($userId, "You've used \${$usage->cost} of your \$10 budget");
    });

// Handle user messages
$messages = [
    "Hello, I need help with my account",
    "I can't reset my password",
    "Can you send me a reset link?",
];

foreach ($messages as $message) {
    $response = $agent->prompt($message);
    echo "Bot: {$response->content}\n";

    $usage = $agent->getUsage();
    echo "Cost so far: \${$usage->cost}\n";
}

// Get session summary
$sessionUsage = \Pagent\Usage\UsageTracker::global()
    ->bySession($userId)
    ->total();

echo "Total session cost: \${$sessionUsage->cost}\n";
echo "Total tokens: {$sessionUsage->totalTokens}\n";
```

### Example 4: Analytics and Reporting

```php
<?php

require 'vendor/autoload.php';

use Pagent\Usage\UsageTracker;
use Carbon\Carbon;

// Get global usage statistics
$tracker = UsageTracker::global();

// Overall summary
$summary = $tracker->summary();
echo "Total requests: {$summary['total_requests']}\n";
echo "Total tokens: {$summary['total_tokens']}\n";
echo "Total cost: \${$summary['total_cost']}\n";
echo "Average cost per request: \${$summary['avg_cost_per_request']}\n";

// Usage by agent
$agentStats = $tracker->byAgent('support-bot')->last(7, 'days')->get();

echo "\nSupport Bot (Last 7 days):\n";
foreach ($agentStats as $usage) {
    $date = date('Y-m-d H:i', $usage->timestamp);
    echo "  {$date}: {$usage->totalTokens} tokens, \${$usage->cost}\n";
}

// Export to CSV for further analysis
$csv = $tracker
    ->last(30, 'days')
    ->exportCsv();

file_put_contents('usage-report-' . date('Y-m-d') . '.csv', $csv);

// Generate HTML report
$report = $tracker
    ->last(7, 'days')
    ->report()
    ->toHtml();

file_put_contents('usage-report.html', $report);

echo "\nReports generated successfully!\n";
```

### Example 5: Custom Pricing Override

```php
<?php

require 'vendor/autoload.php';

use function Pagent\agent;
use Pagent\Usage\Pricing\CustomPricing;

// Create custom pricing for special rates or private deployments
$customPricing = new CustomPricing([
    'input_per_mtok' => 2.50,   // $2.50 per million input tokens
    'output_per_mtok' => 12.00, // $12 per million output tokens
]);

$agent = agent('custom-bot')
    ->provider('anthropic')
    ->trackUsage([
        'pricing' => $customPricing,
    ])
    ->prompt('Hello');

$usage = $agent->getUsage();
echo "Cost with custom pricing: \${$usage->cost}\n";

// Or use model-specific pricing
$modelPricing = [
    'gpt-4-turbo' => ['input' => 8.00, 'output' => 24.00],
    'gpt-4o' => ['input' => 4.00, 'output' => 12.00],
];

$agent = agent('openai-bot')
    ->provider('openai')
    ->trackUsage([
        'pricing' => $modelPricing,
    ])
    ->model('gpt-4-turbo')
    ->prompt('Hello');
```

---

## Risks & Mitigation

| Risk                                               | Impact | Mitigation                                                                                   |
| -------------------------------------------------- | ------ | -------------------------------------------------------------------------------------------- |
| Pricing data becomes outdated                      | High   | Document where to update pricing; Add comments with source URLs; Create GitHub issue alerts |
| Performance overhead from tracking                 | Medium | Make tracking opt-in; Optimize storage writes; Use async writes where possible              |
| Storage fills up disk space                        | Medium | Document retention policies; Provide cleanup utilities; Support TTL in storage adapters     |
| Budget enforcement delays response                 | Medium | Check budget before API call; Cache budget checks; Make enforcement async where possible    |
| Concurrent writes corrupt data                     | Low    | Use database transactions; Add file locking for file storage; Document concurrency limits   |
| Token counting inaccuracies                        | Low    | Use provider-reported counts; Document known discrepancies; Add validation tests            |
| Breaking changes in provider response formats      | Low    | Add comprehensive tests; Use defensive parsing; Monitor provider API changelog              |
| Users expect real-time cost alerts (out of scope)  | Low    | Document callback patterns; Provide examples; Point to notification libraries               |
| Complex queries impact performance (large datasets | Low    | Add query optimization tips; Recommend indexes; Document pagination patterns                |

---

## Success Criteria

- [ ] All core components implemented and tested (90%+ code coverage)
- [ ] Provider-specific pricing accurate within 0.01%
- [ ] Budget enforcement works reliably (exceptions thrown at exact limit)
- [ ] SQLite storage handles 10,000+ records efficiently
- [ ] Query API returns results in < 100ms for typical datasets
- [ ] Zero performance impact when tracking disabled
- [ ] < 5ms overhead when tracking enabled
- [ ] Documentation complete with working examples
- [ ] Integration tests pass with real provider responses
- [ ] Backward compatible (no breaking changes)
- [ ] Can track costs across 1000+ agents without memory issues
- [ ] Export capabilities work for datasets with 10,000+ entries

---

## Timeline

| Phase                           | Duration | Target Date |
| ------------------------------- | -------- | ----------- |
| Phase 1: Core Tracking System   | 6-8 hrs  | Day 1-2     |
| Phase 2: Budget Enforcement     | 4-5 hrs  | Day 2-3     |
| Phase 3: Persistence & Storage  | 3-4 hrs  | Day 3-4     |
| Phase 4: Querying & Reporting   | 3-4 hrs  | Day 4-5     |
| Phase 5: Testing & Documentation| 2-3 hrs  | Day 5-6     |
| **Total**                       | **18-24 hrs** | **1 week** |

---

## Related Work

### OpenTelemetry Integration (Separate Feature)

Cost and token tracking will provide a data source for OpenTelemetry observability (planned separately in v0.7.0). The integration will:

- Export usage data as OTel metrics
- Add token/cost attributes to spans
- Support Langfuse, Langsmith, Phoenix platforms
- Remain optional (can use cost tracking without OTel)

**API Preview:**
```php
agent('bot')
    ->trackUsage()
    ->observability('langfuse', ['public_key' => env('LANGFUSE_KEY')])
    ->prompt('Hello');

// Usage automatically sent to Langfuse with cost metrics
```

### Future Enhancements (v0.8.0+)

- Cost forecasting based on historical usage
- Automatic budget allocation recommendations
- Cost optimization suggestions (model selection)
- Multi-currency support
- Integration with billing systems
- Real-time cost dashboards

---

**Created:** 2025-10-29
**Last Updated:** 2025-10-29
**Status:** Planned for v0.7.0
