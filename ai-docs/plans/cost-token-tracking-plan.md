# Cost & Token Usage Tracking/Monitoring Implementation Plan

**Feature**: Comprehensive cost and token usage tracking for LLM interactions
**Version**: v0.7.0
**Estimated Effort**: 4-6 hours
**Priority**: HIGH - Production monitoring and cost control
**Status**: 📋 Planned

---

## Executive Summary

Implement a comprehensive cost and token usage tracking system that enables developers to monitor their LLM API costs in real-time, enforce budgets, and analyze usage patterns. The system will be Pagent-focused (convenient in-library tracking) while remaining compatible with future OpenTelemetry observability integration.

### Key Goals

1. Track token usage per request, per session, per agent
2. Calculate costs based on provider-specific pricing
3. Enforce budget limits (soft warnings + hard limits)
4. Provide usage statistics and reporting
5. Export data for analysis
6. Prepare for OpenTelemetry integration

### Value Proposition

- **Cost Control**: Prevent unexpected API bills with budget enforcement
- **Visibility**: Real-time insight into token usage and costs
- **Optimization**: Identify expensive operations and optimize prompts
- **Debugging**: Track token consumption across multi-agent workflows
- **Production Ready**: Essential for commercial deployments

---

## Architecture

### Core Components

```
┌─────────────────────────────────────────────────────────┐
│                     Agent Layer                         │
│  - Agent::trackUsage()                                  │
│  - Agent::sessionBudget()                               │
│  - Agent::getUsage()                                    │
└─────────────────────────────────┬───────────────────────┘
                                  │
                    ┌─────────────▼────────────────┐
                    │   UsageTracker (Singleton)   │
                    │  - Track per request         │
                    │  - Track per session         │
                    │  - Track per agent           │
                    │  - Aggregate statistics      │
                    └──────────┬──────────┬────────┘
                               │          │
              ┌────────────────▼──┐    ┌─▼────────────────┐
              │  CostCalculator   │    │  BudgetEnforcer  │
              │  - Provider rates │    │  - Soft warnings │
              │  - Model pricing  │    │  - Hard limits   │
              │  - Tier detection │    │  - Callbacks     │
              └───────────────────┘    └──────────────────┘
                               │
                    ┌──────────▼──────────────┐
                    │  Storage Adapters       │
                    │  - Memory (default)     │
                    │  - SQLite               │
                    │  - File (JSON/CSV)      │
                    │  - OpenTelemetry hook   │
                    └─────────────────────────┘
```

### Data Flow

```
1. Agent makes LLM call
   ↓
2. Provider returns response with usage metadata
   ↓
3. UsageTracker records:
   - input_tokens, output_tokens, total_tokens
   - timestamp, model, provider
   - session_id, agent_name
   ↓
4. CostCalculator computes cost based on provider pricing
   ↓
5. BudgetEnforcer checks limits:
   - Warn at 80% (configurable)
   - Block at 100%
   ↓
6. Usage stored in adapter (memory/SQLite/file)
   ↓
7. Statistics available via UsageTracker API
```

---

## Provider Pricing Configuration

### Pricing Structure

```php
// src/Usage/ProviderPricing.php
class ProviderPricing
{
    private const PRICING = [
        'anthropic' => [
            'claude-3-5-sonnet-20241022' => [
                'input' => 0.003,   // $3 per million tokens
                'output' => 0.015,  // $15 per million tokens
            ],
            'claude-3-5-haiku-20241022' => [
                'input' => 0.0008,
                'output' => 0.004,
            ],
            'claude-3-opus-20240229' => [
                'input' => 0.015,
                'output' => 0.075,
            ],
            'claude-sonnet-4-20250514' => [
                'input' => 0.003,
                'output' => 0.015,
            ],
        ],
        'openai' => [
            'gpt-4-turbo' => [
                'input' => 0.01,
                'output' => 0.03,
            ],
            'gpt-4' => [
                'input' => 0.03,
                'output' => 0.06,
            ],
            'gpt-3.5-turbo' => [
                'input' => 0.0005,
                'output' => 0.0015,
            ],
            'gpt-4o' => [
                'input' => 0.0025,
                'output' => 0.010,
            ],
            'gpt-4o-mini' => [
                'input' => 0.00015,
                'output' => 0.0006,
            ],
        ],
        'ollama' => [
            '*' => [
                'input' => 0.0,   // Local models are free
                'output' => 0.0,
            ],
        ],
    ];

    public static function calculate(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens
    ): float {
        $pricing = self::getPricing($provider, $model);

        $inputCost = ($inputTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($outputTokens / 1_000_000) * $pricing['output'];

        return round($inputCost + $outputCost, 6);
    }

    public static function getPricing(string $provider, string $model): array
    {
        // Check exact match first
        if (isset(self::PRICING[$provider][$model])) {
            return self::PRICING[$provider][$model];
        }

        // Fallback to wildcard (for Ollama)
        if (isset(self::PRICING[$provider]['*'])) {
            return self::PRICING[$provider]['*'];
        }

        // Unknown model - estimate conservatively
        return match($provider) {
            'anthropic' => ['input' => 0.003, 'output' => 0.015],
            'openai' => ['input' => 0.01, 'output' => 0.03],
            default => ['input' => 0.0, 'output' => 0.0],
        };
    }

    public static function addCustomPricing(
        string $provider,
        string $model,
        float $inputPrice,
        float $outputPrice
    ): void {
        // Allow users to add custom pricing for new models
        self::PRICING[$provider][$model] = [
            'input' => $inputPrice,
            'output' => $outputPrice,
        ];
    }
}
```

---

## Core Classes

### 1. UsageRecord (Value Object)

```php
// src/Usage/UsageRecord.php
class UsageRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $totalTokens,
        public readonly float $cost,
        public readonly ?string $agentName = null,
        public readonly ?string $sessionId = null,
        public readonly ?array $metadata = null,
    ) {}

    public static function fromResponse(
        object $response,
        string $agentName,
        ?string $sessionId = null
    ): self {
        $provider = $response->provider ?? 'unknown';
        $model = $response->model ?? 'unknown';

        // Extract token counts (provider-specific)
        $inputTokens = match($provider) {
            'anthropic' => $response->usage['input_tokens'] ?? 0,
            'openai' => $response->usage['prompt_tokens'] ?? 0,
            'ollama' => $response->usage['prompt_tokens'] ?? 0,
            default => 0,
        };

        $outputTokens = match($provider) {
            'anthropic' => $response->usage['output_tokens'] ?? 0,
            'openai' => $response->usage['completion_tokens'] ?? 0,
            'ollama' => $response->usage['completion_tokens'] ?? 0,
            default => 0,
        };

        $totalTokens = $inputTokens + $outputTokens;

        // Calculate cost
        $cost = ProviderPricing::calculate(
            $provider,
            $model,
            $inputTokens,
            $outputTokens
        );

        return new self(
            id: uniqid('usage_', true),
            timestamp: date('c'),
            provider: $provider,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cost: $cost,
            agentName: $agentName,
            sessionId: $sessionId,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cost' => $this->cost,
            'agent_name' => $this->agentName,
            'session_id' => $this->sessionId,
            'metadata' => $this->metadata,
        ];
    }
}
```

### 2. UsageTracker (Core Service)

```php
// src/Usage/UsageTracker.php
class UsageTracker
{
    private static ?self $instance = null;

    private array $records = [];
    private array $budgets = [];
    private array $callbacks = [];
    private ?UsageStorageInterface $storage = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function track(UsageRecord $record): void
    {
        $this->records[] = $record;

        // Store in adapter if configured
        $this->storage?->store($record);

        // Check budgets
        $this->checkBudgets($record);

        // Trigger callbacks
        $this->triggerCallbacks($record);
    }

    public function setBudget(
        string $scope,
        string $identifier,
        float $limit,
        float $warnAt = 0.8
    ): void {
        $this->budgets[$scope][$identifier] = [
            'limit' => $limit,
            'warn_at' => $warnAt,
            'spent' => 0.0,
            'warned' => false,
        ];
    }

    public function getUsage(?string $agentName = null, ?string $sessionId = null): array
    {
        $filtered = array_filter($this->records, function($record) use ($agentName, $sessionId) {
            if ($agentName && $record->agentName !== $agentName) {
                return false;
            }
            if ($sessionId && $record->sessionId !== $sessionId) {
                return false;
            }
            return true;
        });

        return [
            'total_requests' => count($filtered),
            'input_tokens' => array_sum(array_map(fn($r) => $r->inputTokens, $filtered)),
            'output_tokens' => array_sum(array_map(fn($r) => $r->outputTokens, $filtered)),
            'total_tokens' => array_sum(array_map(fn($r) => $r->totalTokens, $filtered)),
            'total_cost' => array_sum(array_map(fn($r) => $r->cost, $filtered)),
            'records' => $filtered,
        ];
    }

    public function summary(): array
    {
        return [
            'global' => $this->getUsage(),
            'by_agent' => $this->byAgent(),
            'by_session' => $this->bySession(),
            'by_provider' => $this->byProvider(),
            'by_model' => $this->byModel(),
        ];
    }

    public function byAgent(): array
    {
        $groups = [];
        foreach ($this->records as $record) {
            $agent = $record->agentName ?? 'unknown';
            if (!isset($groups[$agent])) {
                $groups[$agent] = [];
            }
            $groups[$agent][] = $record;
        }

        return array_map(fn($records) => $this->aggregateRecords($records), $groups);
    }

    public function bySession(): array
    {
        $groups = [];
        foreach ($this->records as $record) {
            $session = $record->sessionId ?? 'unknown';
            if (!isset($groups[$session])) {
                $groups[$session] = [];
            }
            $groups[$session][] = $record;
        }

        return array_map(fn($records) => $this->aggregateRecords($records), $groups);
    }

    public function byProvider(): array
    {
        $groups = [];
        foreach ($this->records as $record) {
            if (!isset($groups[$record->provider])) {
                $groups[$record->provider] = [];
            }
            $groups[$record->provider][] = $record;
        }

        return array_map(fn($records) => $this->aggregateRecords($records), $groups);
    }

    public function byModel(): array
    {
        $groups = [];
        foreach ($this->records as $record) {
            $key = "{$record->provider}/{$record->model}";
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $record;
        }

        return array_map(fn($records) => $this->aggregateRecords($records), $groups);
    }

    private function aggregateRecords(array $records): array
    {
        return [
            'requests' => count($records),
            'input_tokens' => array_sum(array_map(fn($r) => $r->inputTokens, $records)),
            'output_tokens' => array_sum(array_map(fn($r) => $r->outputTokens, $records)),
            'total_tokens' => array_sum(array_map(fn($r) => $r->totalTokens, $records)),
            'total_cost' => array_sum(array_map(fn($r) => $r->cost, $records)),
        ];
    }

    private function checkBudgets(UsageRecord $record): void
    {
        // Check agent budget
        if (isset($this->budgets['agent'][$record->agentName])) {
            $this->checkBudget('agent', $record->agentName, $record->cost);
        }

        // Check session budget
        if ($record->sessionId && isset($this->budgets['session'][$record->sessionId])) {
            $this->checkBudget('session', $record->sessionId, $record->cost);
        }

        // Check global budget
        if (isset($this->budgets['global']['*'])) {
            $this->checkBudget('global', '*', $record->cost);
        }
    }

    private function checkBudget(string $scope, string $identifier, float $cost): void
    {
        $budget = &$this->budgets[$scope][$identifier];
        $budget['spent'] += $cost;

        $percentage = $budget['spent'] / $budget['limit'];

        // Soft warning
        if ($percentage >= $budget['warn_at'] && !$budget['warned']) {
            $budget['warned'] = true;
            $this->triggerWarning($scope, $identifier, $percentage);
        }

        // Hard limit
        if ($percentage >= 1.0) {
            throw new BudgetExceededException(
                "Budget exceeded for {$scope} '{$identifier}': " .
                "\${$budget['spent']} / \${$budget['limit']}"
            );
        }
    }

    public function onWarning(callable $callback): void
    {
        $this->callbacks['warning'][] = $callback;
    }

    public function onRecord(callable $callback): void
    {
        $this->callbacks['record'][] = $callback;
    }

    private function triggerWarning(string $scope, string $identifier, float $percentage): void
    {
        foreach ($this->callbacks['warning'] ?? [] as $callback) {
            $callback($scope, $identifier, $percentage);
        }
    }

    private function triggerCallbacks(UsageRecord $record): void
    {
        foreach ($this->callbacks['record'] ?? [] as $callback) {
            $callback($record);
        }
    }

    public function setStorage(UsageStorageInterface $storage): void
    {
        $this->storage = $storage;
    }

    public function export(string $format, string $path): void
    {
        $exporter = match($format) {
            'json' => new JsonExporter(),
            'csv' => new CsvExporter(),
            'sqlite' => new SqliteExporter(),
            default => throw new InvalidArgumentException("Unsupported format: {$format}"),
        };

        $exporter->export($this->records, $path);
    }

    public function reset(): void
    {
        $this->records = [];
        $this->budgets = [];
    }
}
```

### 3. Agent Integration

```php
// src/Agent.php - Add these methods

/**
 * Enable usage tracking for this agent
 */
public function trackUsage(array $config = []): self
{
    $this->usageTracking = [
        'enabled' => true,
        'budget' => $config['budget'] ?? null,
        'warn_at' => $config['warn_at'] ?? 0.8,
    ];

    // Set agent-level budget if provided
    if (isset($config['budget'])) {
        UsageTracker::instance()->setBudget(
            'agent',
            $this->name,
            $config['budget'],
            $config['warn_at']
        );
    }

    return $this;
}

/**
 * Set session-level budget
 */
public function sessionBudget(float $limit, float $warnAt = 0.8): self
{
    if (!$this->sessionId) {
        throw new RuntimeException('Session ID must be set before setting session budget');
    }

    UsageTracker::instance()->setBudget(
        'session',
        $this->sessionId,
        $limit,
        $warnAt
    );

    return $this;
}

/**
 * Get usage statistics for this agent
 */
public function getUsage(): array
{
    return UsageTracker::instance()->getUsage($this->name, $this->sessionId);
}

/**
 * Track usage after provider response
 */
private function trackProviderUsage(object $response): void
{
    if (!($this->usageTracking['enabled'] ?? false)) {
        return;
    }

    $record = UsageRecord::fromResponse($response, $this->name, $this->sessionId);
    UsageTracker::instance()->track($record);
}

// Modify existing prompt() method to track usage
public function prompt(string $message, array $options = []): object
{
    // ... existing code ...

    $response = $this->provider->prompt($message, $mergedOptions);

    // Track usage
    $this->trackProviderUsage($response);

    // ... rest of existing code ...

    return $response;
}

// Also track in streamTo() method
public function streamTo(string $message, callable $callback, array $options = []): string
{
    // ... existing code ...

    $streamResponse = $this->stream($message, $options);
    $streamResponse->streamTo($callback);
    $fullContent = $streamResponse->getFullContent();

    // Track usage from streaming response
    if ($this->usageTracking['enabled'] ?? false) {
        $metadata = $streamResponse->getMetadata();
        if (isset($metadata['usage'])) {
            $record = new UsageRecord(
                id: uniqid('usage_', true),
                timestamp: date('c'),
                provider: $metadata['provider'] ?? 'unknown',
                model: $metadata['model'] ?? 'unknown',
                inputTokens: $metadata['usage']['input_tokens'] ?? 0,
                outputTokens: $metadata['usage']['output_tokens'] ?? 0,
                totalTokens: ($metadata['usage']['input_tokens'] ?? 0) + ($metadata['usage']['output_tokens'] ?? 0),
                cost: ProviderPricing::calculate(
                    $metadata['provider'] ?? 'unknown',
                    $metadata['model'] ?? 'unknown',
                    $metadata['usage']['input_tokens'] ?? 0,
                    $metadata['usage']['output_tokens'] ?? 0
                ),
                agentName: $this->name,
                sessionId: $this->sessionId,
            );
            UsageTracker::instance()->track($record);
        }
    }

    // ... rest of existing code ...
}
```

---

## Storage Adapters

### Interface

```php
// src/Usage/Contracts/UsageStorageInterface.php
interface UsageStorageInterface
{
    public function store(UsageRecord $record): void;

    public function query(array $filters = []): array;

    public function aggregate(string $groupBy): array;

    public function clear(?string $scope = null): void;
}
```

### Memory Adapter (Default)

```php
// src/Usage/Storage/MemoryStorage.php
class MemoryStorage implements UsageStorageInterface
{
    private array $records = [];

    public function store(UsageRecord $record): void
    {
        $this->records[] = $record;
    }

    public function query(array $filters = []): array
    {
        return array_filter($this->records, function($record) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($record->{$key} !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    public function aggregate(string $groupBy): array
    {
        // Implementation
    }

    public function clear(?string $scope = null): void
    {
        $this->records = [];
    }
}
```

### SQLite Adapter

```php
// src/Usage/Storage/SqliteStorage.php
class SqliteStorage implements UsageStorageInterface
{
    private PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO("sqlite:{$path}");
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS usage_records (
                id TEXT PRIMARY KEY,
                timestamp TEXT NOT NULL,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                input_tokens INTEGER NOT NULL,
                output_tokens INTEGER NOT NULL,
                total_tokens INTEGER NOT NULL,
                cost REAL NOT NULL,
                agent_name TEXT,
                session_id TEXT,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_agent ON usage_records(agent_name)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_session ON usage_records(session_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_timestamp ON usage_records(timestamp)");
    }

    public function store(UsageRecord $record): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO usage_records (
                id, timestamp, provider, model,
                input_tokens, output_tokens, total_tokens, cost,
                agent_name, session_id, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $record->id,
            $record->timestamp,
            $record->provider,
            $record->model,
            $record->inputTokens,
            $record->outputTokens,
            $record->totalTokens,
            $record->cost,
            $record->agentName,
            $record->sessionId,
            json_encode($record->metadata),
        ]);
    }

    public function query(array $filters = []): array
    {
        // Build dynamic WHERE clause
        $where = [];
        $params = [];

        foreach ($filters as $key => $value) {
            $where[] = "{$key} = ?";
            $params[] = $value;
        }

        $sql = "SELECT * FROM usage_records";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY timestamp DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(fn($row) => $this->rowToRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function aggregate(string $groupBy): array
    {
        $sql = "
            SELECT
                {$groupBy},
                COUNT(*) as requests,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(cost) as total_cost
            FROM usage_records
            GROUP BY {$groupBy}
        ";

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clear(?string $scope = null): void
    {
        if ($scope === null) {
            $this->db->exec("DELETE FROM usage_records");
        } else {
            $stmt = $this->db->prepare("DELETE FROM usage_records WHERE {$scope} = ?");
            $stmt->execute([$scope]);
        }
    }

    private function rowToRecord(array $row): UsageRecord
    {
        return new UsageRecord(
            id: $row['id'],
            timestamp: $row['timestamp'],
            provider: $row['provider'],
            model: $row['model'],
            inputTokens: (int)$row['input_tokens'],
            outputTokens: (int)$row['output_tokens'],
            totalTokens: (int)$row['total_tokens'],
            cost: (float)$row['cost'],
            agentName: $row['agent_name'],
            sessionId: $row['session_id'],
            metadata: json_decode($row['metadata'], true),
        );
    }
}
```

---

## Export Capabilities

### JSON Exporter

```php
// src/Usage/Export/JsonExporter.php
class JsonExporter
{
    public function export(array $records, string $path): void
    {
        $data = [
            'exported_at' => date('c'),
            'total_records' => count($records),
            'records' => array_map(fn($r) => $r->toArray(), $records),
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }
}
```

### CSV Exporter

```php
// src/Usage/Export/CsvExporter.php
class CsvExporter
{
    public function export(array $records, string $path): void
    {
        $fp = fopen($path, 'w');

        // Header
        fputcsv($fp, [
            'timestamp', 'provider', 'model', 'agent', 'session',
            'input_tokens', 'output_tokens', 'total_tokens', 'cost'
        ]);

        // Data
        foreach ($records as $record) {
            fputcsv($fp, [
                $record->timestamp,
                $record->provider,
                $record->model,
                $record->agentName,
                $record->sessionId,
                $record->inputTokens,
                $record->outputTokens,
                $record->totalTokens,
                $record->cost,
            ]);
        }

        fclose($fp);
    }
}
```

---

## Integration with OpenTelemetry

### Observability Hook

```php
// src/Usage/Observability/OpenTelemetryHook.php
class OpenTelemetryHook implements UsageStorageInterface
{
    private $tracer;

    public function __construct($tracer)
    {
        $this->tracer = $tracer;
    }

    public function store(UsageRecord $record): void
    {
        $span = $this->tracer->startSpan('llm.request');

        $span->setAttribute('llm.provider', $record->provider);
        $span->setAttribute('llm.model', $record->model);
        $span->setAttribute('llm.input_tokens', $record->inputTokens);
        $span->setAttribute('llm.output_tokens', $record->outputTokens);
        $span->setAttribute('llm.total_tokens', $record->totalTokens);
        $span->setAttribute('llm.cost', $record->cost);
        $span->setAttribute('agent.name', $record->agentName);
        $span->setAttribute('session.id', $record->sessionId);

        $span->end();
    }

    // Other methods...
}
```

---

## API Examples

### Basic Usage Tracking

```php
// Enable tracking for an agent
agent('assistant')
    ->trackUsage([
        'budget' => 10.00,  // $10 max
        'warn_at' => 0.8,   // Warn at 80%
    ])
    ->prompt('Hello');

// Get usage statistics
$usage = agent('assistant')->getUsage();
// [
//     'total_requests' => 1,
//     'input_tokens' => 45,
//     'output_tokens' => 120,
//     'total_tokens' => 165,
//     'total_cost' => 0.002475,
//     'records' => [...]
// ]
```

### Session-Level Budget

```php
agent('support')
    ->sessionId('user-123')
    ->trackUsage()
    ->sessionBudget(5.00)  // $5 per session
    ->prompt('Help me with my order');

// Multiple interactions in same session
for ($i = 0; $i < 10; $i++) {
    try {
        agent('support')->prompt("Question $i");
    } catch (BudgetExceededException $e) {
        echo "Budget exhausted: {$e->getMessage()}";
        break;
    }
}
```

### Global Statistics

```php
// Summary of all usage
$summary = UsageTracker::instance()->summary();
// [
//     'global' => ['total_cost' => 15.50, ...],
//     'by_agent' => [
//         'assistant' => ['total_cost' => 5.20, ...],
//         'support' => ['total_cost' => 10.30, ...],
//     ],
//     'by_session' => [...],
//     'by_provider' => [...],
//     'by_model' => [...]
// ]

// Agent-specific stats
$agentStats = UsageTracker::instance()->byAgent();
print_r($agentStats['assistant']);

// Session-specific stats
$sessionStats = UsageTracker::instance()->bySession();
print_r($sessionStats['user-123']);
```

### Real-Time Monitoring

```php
// Set up callbacks
UsageTracker::instance()->onWarning(function($scope, $id, $percentage) {
    Log::warning("Budget warning: {$scope}/{$id} at " . ($percentage * 100) . "%");
});

UsageTracker::instance()->onRecord(function($record) {
    echo "Request cost: \${$record->cost}\n";
});

// Use agents as normal - callbacks fire automatically
agent('assistant')->prompt('Analyze this data');
```

### Export Usage Data

```php
// Export to JSON
UsageTracker::instance()->export('json', 'reports/usage-2025-01.json');

// Export to CSV for Excel
UsageTracker::instance()->export('csv', 'reports/usage-2025-01.csv');

// Export to SQLite database
UsageTracker::instance()->export('sqlite', 'reports/usage.db');
```

### Persistent Storage

```php
// Configure SQLite storage
UsageTracker::instance()->setStorage(
    new SqliteStorage('storage/usage.db')
);

// Now all usage is persisted to database
agent('assistant')->trackUsage()->prompt('Hello');

// Query historical data
$storage = new SqliteStorage('storage/usage.db');
$lastWeek = $storage->query([
    'timestamp' => '> ' . date('c', strtotime('-7 days'))
]);

// Aggregate by model
$byModel = $storage->aggregate('model');
```

### Custom Pricing

```php
// Add pricing for a new model
ProviderPricing::addCustomPricing(
    'anthropic',
    'claude-4-opus',
    inputPrice: 0.025,
    outputPrice: 0.125
);

// Now cost calculation works for the new model
agent('bot')
    ->model('claude-4-opus')
    ->trackUsage()
    ->prompt('Hello');
```

### Integration with Workflows

```php
// Track usage across multi-agent workflows
agent('researcher')->trackUsage(['budget' => 2.00]);
agent('writer')->trackUsage(['budget' => 3.00]);
agent('editor')->trackUsage(['budget' => 1.00]);

$result = pipeline('content-creation')
    ->agent(agent('researcher'))
    ->agent(agent('writer'))
    ->agent(agent('editor'))
    ->run('Create article about PHP agents');

// Get per-agent breakdown
echo "Researcher cost: $" . agent('researcher')->getUsage()['total_cost'];
echo "Writer cost: $" . agent('writer')->getUsage()['total_cost'];
echo "Editor cost: $" . agent('editor')->getUsage()['total_cost'];

// Total workflow cost
$workflowCost = UsageTracker::instance()->byAgent();
$totalCost = array_sum(array_column($workflowCost, 'total_cost'));
echo "Total workflow cost: \${$totalCost}";
```

---

## Testing Strategy

### Unit Tests

```php
// tests/Unit/Usage/ProviderPricingTest.php
it('calculates Anthropic costs correctly', function() {
    $cost = ProviderPricing::calculate(
        'anthropic',
        'claude-3-5-sonnet-20241022',
        inputTokens: 1000,
        outputTokens: 2000
    );

    expect($cost)->toBe(0.000003 * 1000 + 0.000015 * 2000); // $0.033
});

it('handles unknown models conservatively', function() {
    $cost = ProviderPricing::calculate(
        'anthropic',
        'claude-unknown-model',
        inputTokens: 1000,
        outputTokens: 1000
    );

    expect($cost)->toBeGreaterThan(0);
});

it('returns zero for Ollama models', function() {
    $cost = ProviderPricing::calculate(
        'ollama',
        'llama2',
        inputTokens: 1000,
        outputTokens: 1000
    );

    expect($cost)->toBe(0.0);
});

// tests/Unit/Usage/UsageTrackerTest.php
it('tracks usage records', function() {
    $tracker = UsageTracker::instance();
    $tracker->reset();

    $record = new UsageRecord(
        id: 'test-1',
        timestamp: date('c'),
        provider: 'anthropic',
        model: 'claude-3-5-sonnet-20241022',
        inputTokens: 100,
        outputTokens: 200,
        totalTokens: 300,
        cost: 0.0036,
        agentName: 'test',
    );

    $tracker->track($record);

    $usage = $tracker->getUsage('test');
    expect($usage['total_cost'])->toBe(0.0036);
    expect($usage['total_tokens'])->toBe(300);
});

it('enforces budget limits', function() {
    $tracker = UsageTracker::instance();
    $tracker->reset();
    $tracker->setBudget('agent', 'test', limit: 0.01, warnAt: 0.8);

    // Track $0.005 - should be OK
    $tracker->track(new UsageRecord(
        id: 'test-1',
        timestamp: date('c'),
        provider: 'anthropic',
        model: 'claude-3-5-sonnet-20241022',
        inputTokens: 100,
        outputTokens: 100,
        totalTokens: 200,
        cost: 0.005,
        agentName: 'test',
    ));

    // Track another $0.006 - should throw
    expect(fn() => $tracker->track(new UsageRecord(
        id: 'test-2',
        timestamp: date('c'),
        provider: 'anthropic',
        model: 'claude-3-5-sonnet-20241022',
        inputTokens: 200,
        outputTokens: 100,
        totalTokens: 300,
        cost: 0.006,
        agentName: 'test',
    )))->toThrow(BudgetExceededException::class);
});

it('aggregates by agent', function() {
    $tracker = UsageTracker::instance();
    $tracker->reset();

    $tracker->track(new UsageRecord(
        id: 'test-1',
        timestamp: date('c'),
        provider: 'anthropic',
        model: 'claude-3-5-sonnet-20241022',
        inputTokens: 100,
        outputTokens: 100,
        totalTokens: 200,
        cost: 0.002,
        agentName: 'agent1',
    ));

    $tracker->track(new UsageRecord(
        id: 'test-2',
        timestamp: date('c'),
        provider: 'openai',
        model: 'gpt-4',
        inputTokens: 200,
        outputTokens: 200,
        totalTokens: 400,
        cost: 0.018,
        agentName: 'agent2',
    ));

    $byAgent = $tracker->byAgent();

    expect($byAgent['agent1']['total_cost'])->toBe(0.002);
    expect($byAgent['agent2']['total_cost'])->toBe(0.018);
});
```

### Integration Tests

```php
// tests/Integration/UsageTrackingTest.php
it('tracks usage from real agent interactions', function() {
    $mock = mock(['Hello' => 'Hi there!']);

    UsageTracker::instance()->reset();

    agent('test')
        ->provider($mock)
        ->trackUsage(['budget' => 100.00])
        ->prompt('Hello');

    $usage = agent('test')->getUsage();

    expect($usage['total_requests'])->toBe(1);
    expect($usage['total_tokens'])->toBeGreaterThan(0);
});

it('tracks usage across multiple prompts', function() {
    $mock = mock([
        'First' => 'Response 1',
        'Second' => 'Response 2',
        'Third' => 'Response 3',
    ]);

    UsageTracker::instance()->reset();

    $agent = agent('test')
        ->provider($mock)
        ->trackUsage();

    $agent->prompt('First');
    $agent->prompt('Second');
    $agent->prompt('Third');

    $usage = $agent->getUsage();
    expect($usage['total_requests'])->toBe(3);
});

it('tracks session-level usage', function() {
    $mock = mock(['Test' => 'Response']);

    UsageTracker::instance()->reset();

    agent('session-test')
        ->provider($mock)
        ->sessionId('session-123')
        ->trackUsage()
        ->sessionBudget(50.00)
        ->prompt('Test');

    $usage = agent('session-test')->getUsage();

    expect($usage['total_requests'])->toBe(1);

    $bySession = UsageTracker::instance()->bySession();
    expect($bySession['session-123'])->toBeArray();
});

it('throws when budget is exceeded', function() {
    $mock = mock(['Test' => 'Response']);

    // Mock response to have high token usage
    $mock->setCustomResponse((object)[
        'content' => 'Response',
        'model' => 'claude-3-5-sonnet-20241022',
        'tokens' => 1000000,
        'provider' => 'anthropic',
        'usage' => [
            'input_tokens' => 500000,
            'output_tokens' => 500000,
        ],
    ]);

    UsageTracker::instance()->reset();

    $agent = agent('budget-test')
        ->provider($mock)
        ->trackUsage(['budget' => 0.01]);  // Very low budget

    expect(fn() => $agent->prompt('Test'))
        ->toThrow(BudgetExceededException::class);
});
```

### Storage Tests

```php
// tests/Unit/Usage/Storage/SqliteStorageTest.php
it('stores and retrieves usage records', function() {
    $storage = new SqliteStorage(':memory:');

    $record = new UsageRecord(
        id: 'test-1',
        timestamp: date('c'),
        provider: 'anthropic',
        model: 'claude-3-5-sonnet-20241022',
        inputTokens: 100,
        outputTokens: 200,
        totalTokens: 300,
        cost: 0.0036,
        agentName: 'test',
    );

    $storage->store($record);

    $records = $storage->query(['agent_name' => 'test']);

    expect($records)->toHaveCount(1);
    expect($records[0]->cost)->toBe(0.0036);
});

it('aggregates by provider', function() {
    $storage = new SqliteStorage(':memory:');

    $storage->store(new UsageRecord(
        id: 'test-1',
        timestamp: date('c'),
        provider: 'anthropic',
        model: 'claude-3-5-sonnet-20241022',
        inputTokens: 100,
        outputTokens: 100,
        totalTokens: 200,
        cost: 0.002,
    ));

    $storage->store(new UsageRecord(
        id: 'test-2',
        timestamp: date('c'),
        provider: 'openai',
        model: 'gpt-4',
        inputTokens: 100,
        outputTokens: 100,
        totalTokens: 200,
        cost: 0.009,
    ));

    $byProvider = $storage->aggregate('provider');

    expect($byProvider)->toHaveCount(2);
    expect($byProvider[0]['provider'])->toBeIn(['anthropic', 'openai']);
});
```

---

## Documentation

### User Guide

````markdown
# Cost & Token Usage Tracking

Track and control your LLM API costs with built-in usage monitoring.

## Quick Start

Enable tracking for an agent:

```php
agent('assistant')
    ->trackUsage([
        'budget' => 10.00,  // $10 max
        'warn_at' => 0.8,   // Warn at 80%
    ])
    ->prompt('Hello');
```
````

Get usage statistics:

```php
$usage = agent('assistant')->getUsage();
echo "Cost: \${$usage['total_cost']}";
echo "Tokens: {$usage['total_tokens']}";
```

## Features

- **Real-time cost tracking** - Know exactly what each request costs
- **Budget enforcement** - Set limits to prevent overspending
- **Multi-level tracking** - Per-agent, per-session, and global budgets
- **Usage analytics** - Aggregate by agent, session, provider, or model
- **Export capabilities** - JSON, CSV, SQLite for analysis
- **OpenTelemetry ready** - Integrates with observability platforms

## Budget Enforcement

### Agent-Level Budget

```php
agent('chatbot')
    ->trackUsage(['budget' => 50.00])
    ->prompt('Hello');
```

### Session-Level Budget

```php
agent('support')
    ->sessionId('user-123')
    ->trackUsage()
    ->sessionBudget(5.00)  // $5 per user session
    ->prompt('Help me');
```

### Global Budget

```php
UsageTracker::instance()->setBudget('global', '*', 1000.00);

// All agents now share this budget
```

## Warning Callbacks

Get notified when approaching budget limits:

```php
UsageTracker::instance()->onWarning(function($scope, $id, $percentage) {
    Log::warning("Budget at " . ($percentage * 100) . "%");

    // Send email notification
    Mail::to('admin@example.com')
        ->send(new BudgetWarningMail($scope, $id, $percentage));
});
```

## Usage Analytics

```php
// Global summary
$summary = UsageTracker::instance()->summary();

// By agent
$byAgent = UsageTracker::instance()->byAgent();
print_r($byAgent['assistant']);

// By session
$bySession = UsageTracker::instance()->bySession();
print_r($bySession['user-123']);

// By provider
$byProvider = UsageTracker::instance()->byProvider();
print_r($byProvider['anthropic']);

// By model
$byModel = UsageTracker::instance()->byModel();
print_r($byModel['anthropic/claude-3-5-sonnet-20241022']);
```

## Persistent Storage

Store usage data in SQLite:

```php
UsageTracker::instance()->setStorage(
    new SqliteStorage('storage/usage.db')
);

// All usage is now persisted
```

Query historical data:

```php
$storage = new SqliteStorage('storage/usage.db');

// Last 7 days
$recent = $storage->query([
    'timestamp' => '> ' . date('c', strtotime('-7 days'))
]);

// Specific agent
$agentUsage = $storage->query(['agent_name' => 'assistant']);

// Aggregate by model
$byModel = $storage->aggregate('model');
```

## Export Data

```php
// Export to JSON
UsageTracker::instance()->export('json', 'reports/usage.json');

// Export to CSV
UsageTracker::instance()->export('csv', 'reports/usage.csv');

// Export to SQLite
UsageTracker::instance()->export('sqlite', 'reports/usage.db');
```

## Custom Pricing

Add pricing for new models:

```php
ProviderPricing::addCustomPricing(
    'anthropic',
    'claude-4-opus',
    inputPrice: 0.025,   // $25 per million input tokens
    outputPrice: 0.125   // $125 per million output tokens
);
```

## Multi-Agent Workflows

Track costs across workflows:

```php
agent('researcher')->trackUsage(['budget' => 2.00]);
agent('writer')->trackUsage(['budget' => 3.00]);
agent('editor')->trackUsage(['budget' => 1.00]);

$result = pipeline('content')
    ->agent(agent('researcher'))
    ->agent(agent('writer'))
    ->agent(agent('editor'))
    ->run('Create article');

// Get breakdown
echo "Researcher: $" . agent('researcher')->getUsage()['total_cost'];
echo "Writer: $" . agent('writer')->getUsage()['total_cost'];
echo "Editor: $" . agent('editor')->getUsage()['total_cost'];
```

```

### API Reference

Create comprehensive API documentation for all classes and methods.

---

## Implementation Timeline

### Phase 1: Foundation (2 hours)

**Goal**: Core tracking infrastructure

- [ ] Create `UsageRecord` value object
- [ ] Implement `UsageTracker` singleton
- [ ] Add `ProviderPricing` with static pricing table
- [ ] Add `Agent::trackUsage()` and `Agent::getUsage()`
- [ ] Modify `Agent::prompt()` to track usage
- [ ] Write 10 unit tests

**Deliverable**: Basic usage tracking working

### Phase 2: Budget Enforcement (1 hour)

**Goal**: Budget limits and warnings

- [ ] Implement `BudgetEnforcer` logic in `UsageTracker`
- [ ] Add warning callbacks
- [ ] Add hard limit exceptions
- [ ] Add `Agent::sessionBudget()`
- [ ] Write 5 budget tests

**Deliverable**: Budget enforcement working

### Phase 3: Storage & Export (1.5 hours)

**Goal**: Persistent storage and data export

- [ ] Create `UsageStorageInterface`
- [ ] Implement `MemoryStorage` (default)
- [ ] Implement `SqliteStorage`
- [ ] Implement `JsonExporter` and `CsvExporter`
- [ ] Add `UsageTracker::export()`
- [ ] Write 8 storage tests

**Deliverable**: Data persistence working

### Phase 4: Analytics & Reporting (1 hour)

**Goal**: Usage analytics and aggregation

- [ ] Implement `UsageTracker::byAgent()`
- [ ] Implement `UsageTracker::bySession()`
- [ ] Implement `UsageTracker::byProvider()`
- [ ] Implement `UsageTracker::byModel()`
- [ ] Implement `UsageTracker::summary()`
- [ ] Write 5 analytics tests

**Deliverable**: Complete analytics API

### Phase 5: Documentation & Examples (0.5 hours)

**Goal**: User-facing documentation

- [ ] Write user guide (docs/usage-tracking.md)
- [ ] Create 3 examples:
  - Basic tracking
  - Budget enforcement
  - Multi-agent workflows
- [ ] Update README with usage tracking section

**Deliverable**: Complete documentation

---

## Success Criteria

### Functionality
- [ ] Track token usage from all providers (Anthropic, OpenAI, Ollama)
- [ ] Calculate costs accurately based on pricing table
- [ ] Enforce agent-level budgets
- [ ] Enforce session-level budgets
- [ ] Support global budgets
- [ ] Warning callbacks at configurable thresholds
- [ ] Exception thrown when budget exceeded
- [ ] Aggregate statistics (by agent, session, provider, model)
- [ ] Export to JSON, CSV, SQLite
- [ ] Persistent storage with SQLite
- [ ] Query historical usage data

### Code Quality
- [ ] 30+ unit tests passing
- [ ] 5+ integration tests passing
- [ ] PHPStan level 9 compliance
- [ ] All public APIs documented
- [ ] Type hints on all methods
- [ ] No deprecation warnings

### Documentation
- [ ] User guide written
- [ ] 3+ working examples
- [ ] API reference complete
- [ ] README updated

### Performance
- [ ] Minimal overhead (<5ms per tracked request)
- [ ] SQLite storage handles 10,000+ records
- [ ] Export completes in <1 second for 1,000 records

---

## Future Enhancements

### v0.8.0 - Advanced Features
- [ ] Semantic caching based on usage patterns
- [ ] Cost optimization suggestions
- [ ] Token usage forecasting
- [ ] Anomaly detection (unusual spending)
- [ ] Batch export to S3/Cloud Storage
- [ ] Dashboard UI (HTML report with charts)

### v0.9.0 - OpenTelemetry Integration
- [ ] Full OpenTelemetry span attributes
- [ ] Langfuse integration
- [ ] Langsmith integration
- [ ] Phoenix integration
- [ ] Custom OTLP exporters

### v1.0.0 - Enterprise Features
- [ ] Multi-tenancy cost tracking
- [ ] Chargeback/billing integration
- [ ] SLA monitoring (cost per SLA tier)
- [ ] Cost allocation tags
- [ ] Budget alerts via webhooks
- [ ] Automatic budget scaling

---

## Dependencies

### Required
- PHP 8.3+
- PDO SQLite extension (for SqliteStorage)
- JSON extension (for export)

### Optional
- OpenTelemetry SDK (for observability integration)
- Laravel (for framework integration)

### No New Dependencies
All functionality uses PHP built-ins and existing Pagent infrastructure.

---

## Breaking Changes

**None**. This is a new feature that is:
- Opt-in (must call `trackUsage()`)
- Backward compatible
- Non-invasive

---

## Risks & Mitigations

### Risk: Performance overhead
**Mitigation**:
- Lazy initialization of UsageTracker
- Minimal memory footprint (single record ~200 bytes)
- Optional persistent storage

### Risk: Pricing inaccuracies
**Mitigation**:
- Conservative estimates for unknown models
- Allow custom pricing override
- Document pricing update schedule

### Risk: Budget enforcement false positives
**Mitigation**:
- Configurable warning thresholds
- Soft warnings before hard limits
- Callback system for custom logic

### Risk: SQLite storage bottlenecks
**Mitigation**:
- Use Write-Ahead Logging (WAL)
- Batch inserts (future)
- Alternative storage adapters

---

## Open Questions

1. **Should we track tool execution costs separately?**
   - Tool calls consume tokens but aren't billed separately
   - Current plan: Include in request cost (correct)

2. **How to handle streaming token counts?**
   - Streaming responses may have incomplete usage metadata
   - Current plan: Track final usage in `streamTo()` method

3. **Should we support budget pools?**
   - Share budget across multiple agents
   - Current plan: Defer to v0.8.0

4. **What about rate limits (not cost)?**
   - Some providers have requests-per-minute limits
   - Current plan: Out of scope, use RateLimitMiddleware

---

## Appendix

### Pricing Sources (as of 2025-01-29)

- **Anthropic**: https://www.anthropic.com/pricing
- **OpenAI**: https://openai.com/pricing
- **Ollama**: Free (local)

### Related Features

- Middleware (for pre/post request hooks)
- Memory adapters (similar storage pattern)
- Evaluation metrics (similar aggregation logic)

### References

- [Mistral AI Observability](https://docs.mistral.ai/guides/observability/)
- [OpenTelemetry Semantic Conventions](https://opentelemetry.io/docs/specs/semconv/gen-ai/)
- [Langfuse Token Tracking](https://langfuse.com/docs/tracing/token-tracking)

---

**End of Plan**
```
