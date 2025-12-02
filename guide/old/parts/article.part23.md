# Chapter 23: Debugging and Monitoring

## What You'll Learn

By the end of this chapter, you'll be able to:

- Enable and use Pagent's debug mode to troubleshoot conversations
- Track token usage across different providers
- Calculate and monitor costs for LLM operations
- Identify and resolve performance bottlenecks
- Build custom alerting systems for production deployments

**Prerequisites:** Chapter 22 (Production Deployment)
**Time Estimate:** 45 minutes
**Final Result:** A comprehensive monitoring system with debugging tools, cost tracking, and performance analytics

## Introduction: Why Monitoring Matters

In production, visibility is everything. When an agent conversation fails, costs spike unexpectedly, or response times degrade, you need immediate insights. This chapter equips you with tools to maintain operational excellence.

## Part 1: Debug Mode Configuration

### Understanding Debug Levels

Pagent provides multiple debug levels for different insights:

```php
// src/Debug/DebugManager.php
<?php
declare(strict_types=1);

namespace YourApp\Debug;

use Pagent\Agent;
use Psr\Log\LoggerInterface;

final class DebugManager
{
    public const LEVEL_NONE = 0;
    public const LEVEL_BASIC = 1;
    public const LEVEL_VERBOSE = 2;
    public const LEVEL_TRACE = 3;

    private int $level;
    private LoggerInterface $logger;
    private array $metrics = [];

    public function __construct(LoggerInterface $logger, int $level = self::LEVEL_NONE)
    {
        $this->logger = $logger;
        $this->level = $level;
    }

    public function beforeRequest(Agent $agent, array $messages): void
    {
        if ($this->level === self::LEVEL_NONE) {
            return;
        }

        $context = [
            'agent_id' => spl_object_id($agent),
            'message_count' => count($messages),
            'timestamp' => microtime(true),
        ];

        if ($this->level >= self::LEVEL_BASIC) {
            $this->logger->info('Agent request initiated', $context);
        }

        if ($this->level >= self::LEVEL_VERBOSE) {
            $context['messages'] = array_map(fn($msg) => [
                'role' => $msg['role'],
                'length' => strlen($msg['content']),
            ], $messages);
            $this->logger->debug('Request details', $context);
        }

        if ($this->level >= self::LEVEL_TRACE) {
            $context['full_messages'] = $messages;
            $context['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $this->logger->debug('Full request trace', $context);
        }
    }

    public function afterResponse(Agent $agent, string $response, float $duration): void
    {
        if ($this->level === self::LEVEL_NONE) {
            return;
        }

        $this->metrics[] = [
            'agent_id' => spl_object_id($agent),
            'duration' => $duration,
            'response_length' => strlen($response),
            'timestamp' => microtime(true),
        ];

        if ($this->level >= self::LEVEL_BASIC) {
            $this->logger->info('Agent response received', [
                'duration_ms' => round($duration * 1000, 2),
                'response_length' => strlen($response),
            ]);
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
```

### Interactive Debug Dashboard

Build a real-time dashboard for monitoring conversations:

```php
// src/Debug/Dashboard.php
<?php
declare(strict_types=1);

namespace YourApp\Debug;

use Pagent\Agent;

final class Dashboard
{
    private array $conversations = [];
    private array $activeAgents = [];
    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function trackConversation(string $sessionId, Agent $agent): void
    {
        $this->conversations[$sessionId] = [
            'agent' => get_class($agent),
            'started_at' => microtime(true),
            'messages' => [],
            'tokens' => ['input' => 0, 'output' => 0],
            'cost' => 0.0,
            'errors' => [],
        ];

        $this->activeAgents[spl_object_id($agent)] = $sessionId;
    }

    public function recordMessage(string $sessionId, array $message): void
    {
        if (!isset($this->conversations[$sessionId])) {
            return;
        }

        $this->conversations[$sessionId]['messages'][] = [
            'role' => $message['role'],
            'content' => $message['content'],
            'timestamp' => microtime(true),
            'tokens' => $this->estimateTokens($message['content']),
        ];
    }

    public function recordError(string $sessionId, \Throwable $error): void
    {
        if (!isset($this->conversations[$sessionId])) {
            return;
        }

        $this->conversations[$sessionId]['errors'][] = [
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'timestamp' => microtime(true),
            'trace' => $error->getTraceAsString(),
        ];
    }

    public function render(): array
    {
        $uptime = microtime(true) - $this->startTime;

        return [
            'uptime_seconds' => $uptime,
            'active_sessions' => count($this->activeAgents),
            'total_conversations' => count($this->conversations),
            'conversations' => array_map(function ($conv) {
                return [
                    'agent' => $conv['agent'],
                    'duration' => microtime(true) - $conv['started_at'],
                    'message_count' => count($conv['messages']),
                    'total_tokens' => $conv['tokens'],
                    'estimated_cost' => $conv['cost'],
                    'error_count' => count($conv['errors']),
                    'last_activity' => end($conv['messages'])['timestamp'] ?? $conv['started_at'],
                ];
            }, $this->conversations),
            'error_summary' => $this->getErrorSummary(),
        ];
    }

    private function estimateTokens(string $text): int
    {
        // Rough estimation: 1 token ≈ 4 characters
        return (int) ceil(strlen($text) / 4);
    }

    private function getErrorSummary(): array
    {
        $errors = [];
        foreach ($this->conversations as $sessionId => $conv) {
            foreach ($conv['errors'] as $error) {
                $key = $error['message'];
                $errors[$key] = ($errors[$key] ?? 0) + 1;
            }
        }
        arsort($errors);
        return array_slice($errors, 0, 10); // Top 10 errors
    }
}
```

## Part 2: Token Tracking

### Multi-Provider Token Counter

Track token usage across different providers:

```php
// src/Monitoring/TokenTracker.php
<?php
declare(strict_types=1);

namespace YourApp\Monitoring;

final class TokenTracker
{
    private array $usage = [];
    private array $limits = [
        'anthropic' => ['minute' => 10000, 'hour' => 100000],
        'openai' => ['minute' => 60000, 'hour' => 600000],
        'ollama' => ['minute' => PHP_INT_MAX, 'hour' => PHP_INT_MAX],
    ];

    public function recordUsage(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens
    ): void {
        $timestamp = time();
        $minute = floor($timestamp / 60) * 60;
        $hour = floor($timestamp / 3600) * 3600;

        if (!isset($this->usage[$provider])) {
            $this->usage[$provider] = [];
        }

        if (!isset($this->usage[$provider][$model])) {
            $this->usage[$provider][$model] = [
                'total' => ['input' => 0, 'output' => 0],
                'minute' => [],
                'hour' => [],
            ];
        }

        // Update totals
        $this->usage[$provider][$model]['total']['input'] += $inputTokens;
        $this->usage[$provider][$model]['total']['output'] += $outputTokens;

        // Update minute bucket
        if (!isset($this->usage[$provider][$model]['minute'][$minute])) {
            $this->usage[$provider][$model]['minute'][$minute] = ['input' => 0, 'output' => 0];
        }
        $this->usage[$provider][$model]['minute'][$minute]['input'] += $inputTokens;
        $this->usage[$provider][$model]['minute'][$minute]['output'] += $outputTokens;

        // Update hour bucket
        if (!isset($this->usage[$provider][$model]['hour'][$hour])) {
            $this->usage[$provider][$model]['hour'][$hour] = ['input' => 0, 'output' => 0];
        }
        $this->usage[$provider][$model]['hour'][$hour]['input'] += $inputTokens;
        $this->usage[$provider][$model]['hour'][$hour]['output'] += $outputTokens;

        // Clean old buckets
        $this->cleanOldBuckets($provider, $model);
    }

    public function getUsageStats(string $provider, ?string $model = null): array
    {
        if ($model !== null) {
            return $this->usage[$provider][$model] ?? [];
        }

        return $this->usage[$provider] ?? [];
    }

    public function checkRateLimits(string $provider): array
    {
        $warnings = [];
        $currentMinute = floor(time() / 60) * 60;
        $currentHour = floor(time() / 3600) * 3600;

        if (!isset($this->usage[$provider]) || !isset($this->limits[$provider])) {
            return $warnings;
        }

        $minuteTotal = 0;
        $hourTotal = 0;

        foreach ($this->usage[$provider] as $model => $data) {
            if (isset($data['minute'][$currentMinute])) {
                $minuteTotal += $data['minute'][$currentMinute]['input'] +
                               $data['minute'][$currentMinute]['output'];
            }
            if (isset($data['hour'][$currentHour])) {
                $hourTotal += $data['hour'][$currentHour]['input'] +
                             $data['hour'][$currentHour]['output'];
            }
        }

        if ($minuteTotal > $this->limits[$provider]['minute'] * 0.8) {
            $warnings[] = [
                'level' => 'warning',
                'message' => "Approaching minute rate limit for {$provider}",
                'usage' => $minuteTotal,
                'limit' => $this->limits[$provider]['minute'],
                'percentage' => ($minuteTotal / $this->limits[$provider]['minute']) * 100,
            ];
        }

        if ($hourTotal > $this->limits[$provider]['hour'] * 0.8) {
            $warnings[] = [
                'level' => 'warning',
                'message' => "Approaching hourly rate limit for {$provider}",
                'usage' => $hourTotal,
                'limit' => $this->limits[$provider]['hour'],
                'percentage' => ($hourTotal / $this->limits[$provider]['hour']) * 100,
            ];
        }

        return $warnings;
    }

    private function cleanOldBuckets(string $provider, string $model): void
    {
        $currentMinute = floor(time() / 60) * 60;
        $currentHour = floor(time() / 3600) * 3600;

        // Keep only last 5 minutes
        foreach (array_keys($this->usage[$provider][$model]['minute']) as $minute) {
            if ($minute < $currentMinute - 300) {
                unset($this->usage[$provider][$model]['minute'][$minute]);
            }
        }

        // Keep only last 2 hours
        foreach (array_keys($this->usage[$provider][$model]['hour']) as $hour) {
            if ($hour < $currentHour - 7200) {
                unset($this->usage[$provider][$model]['hour'][$hour]);
            }
        }
    }
}
```

## Part 3: Cost Calculation

### Dynamic Cost Calculator

Calculate costs based on actual token usage:

```php
// src/Monitoring/CostCalculator.php
<?php
declare(strict_types=1);

namespace YourApp\Monitoring;

final class CostCalculator
{
    // Prices per 1M tokens in USD
    private array $pricing = [
        'anthropic' => [
            'claude-3-opus' => ['input' => 15.00, 'output' => 75.00],
            'claude-3-sonnet' => ['input' => 3.00, 'output' => 15.00],
            'claude-3-haiku' => ['input' => 0.25, 'output' => 1.25],
        ],
        'openai' => [
            'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
            'gpt-4' => ['input' => 30.00, 'output' => 60.00],
            'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
        ],
        'ollama' => [
            'llama3' => ['input' => 0.00, 'output' => 0.00], // Self-hosted
            'mistral' => ['input' => 0.00, 'output' => 0.00],
        ],
    ];

    private array $costs = [];
    private array $budgets = [];

    public function calculateCost(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens
    ): float {
        if (!isset($this->pricing[$provider][$model])) {
            return 0.0;
        }

        $rates = $this->pricing[$provider][$model];
        $inputCost = ($inputTokens / 1_000_000) * $rates['input'];
        $outputCost = ($outputTokens / 1_000_000) * $rates['output'];

        $totalCost = $inputCost + $outputCost;

        // Track costs
        $this->recordCost($provider, $model, $totalCost);

        return $totalCost;
    }

    public function setBudget(string $key, float $amount, string $period = 'daily'): void
    {
        $this->budgets[$key] = [
            'amount' => $amount,
            'period' => $period,
            'started_at' => time(),
        ];
    }

    public function checkBudget(string $key): array
    {
        if (!isset($this->budgets[$key])) {
            return ['status' => 'no_budget'];
        }

        $budget = $this->budgets[$key];
        $spent = $this->getSpent($key, $budget['period'], $budget['started_at']);
        $remaining = $budget['amount'] - $spent;
        $percentage = ($spent / $budget['amount']) * 100;

        $status = 'ok';
        if ($percentage >= 100) {
            $status = 'exceeded';
        } elseif ($percentage >= 80) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'budget' => $budget['amount'],
            'spent' => $spent,
            'remaining' => max(0, $remaining),
            'percentage' => min(100, $percentage),
            'period' => $budget['period'],
        ];
    }

    private function recordCost(string $provider, string $model, float $cost): void
    {
        $timestamp = time();
        $key = "{$provider}:{$model}";

        if (!isset($this->costs[$key])) {
            $this->costs[$key] = [];
        }

        $this->costs[$key][] = [
            'amount' => $cost,
            'timestamp' => $timestamp,
        ];

        // Clean old records (keep 30 days)
        $this->costs[$key] = array_filter(
            $this->costs[$key],
            fn($record) => $record['timestamp'] > $timestamp - (30 * 86400)
        );
    }

    private function getSpent(string $key, string $period, int $startTime): float
    {
        $total = 0.0;
        $cutoff = $this->getPeriodCutoff($period, $startTime);

        foreach ($this->costs as $costKey => $records) {
            if (str_starts_with($costKey, $key)) {
                foreach ($records as $record) {
                    if ($record['timestamp'] >= $cutoff) {
                        $total += $record['amount'];
                    }
                }
            }
        }

        return $total;
    }

    private function getPeriodCutoff(string $period, int $startTime): int
    {
        return match ($period) {
            'hourly' => time() - 3600,
            'daily' => time() - 86400,
            'weekly' => time() - (7 * 86400),
            'monthly' => time() - (30 * 86400),
            default => $startTime,
        };
    }
}
```

## Part 4: Performance Profiling

### Performance Analyzer

Identify and track performance bottlenecks:

```php
// src/Monitoring/PerformanceAnalyzer.php
<?php
declare(strict_types=1);

namespace YourApp\Monitoring;

final class PerformanceAnalyzer
{
    private array $timings = [];
    private array $slowQueries = [];
    private float $slowThreshold = 5.0; // seconds

    public function startTimer(string $operation): string
    {
        $id = uniqid('perf_', true);
        $this->timings[$id] = [
            'operation' => $operation,
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
        return $id;
    }

    public function endTimer(string $id): array
    {
        if (!isset($this->timings[$id])) {
            return [];
        }

        $timing = $this->timings[$id];
        $duration = microtime(true) - $timing['start'];
        $memoryUsed = memory_get_usage(true) - $timing['memory_start'];

        $result = [
            'operation' => $timing['operation'],
            'duration' => $duration,
            'memory_used' => $memoryUsed,
            'peak_memory' => memory_get_peak_usage(true),
        ];

        // Track slow operations
        if ($duration > $this->slowThreshold) {
            $this->slowQueries[] = $result;
        }

        unset($this->timings[$id]);
        return $result;
    }

    public function getBottlenecks(): array
    {
        $operations = [];

        foreach ($this->slowQueries as $query) {
            $op = $query['operation'];
            if (!isset($operations[$op])) {
                $operations[$op] = [
                    'count' => 0,
                    'total_time' => 0,
                    'avg_time' => 0,
                    'max_time' => 0,
                    'instances' => [],
                ];
            }

            $operations[$op]['count']++;
            $operations[$op]['total_time'] += $query['duration'];
            $operations[$op]['avg_time'] = $operations[$op]['total_time'] / $operations[$op]['count'];
            $operations[$op]['max_time'] = max($operations[$op]['max_time'], $query['duration']);
            $operations[$op]['instances'][] = [
                'duration' => $query['duration'],
                'memory' => $query['memory_used'],
            ];
        }

        // Sort by total time descending
        uasort($operations, fn($a, $b) => $b['total_time'] <=> $a['total_time']);

        return $operations;
    }
}
```

## Part 5: Alerting Pipeline

### Alert Manager

Create custom alerting rules:

```php
// src/Monitoring/AlertManager.php
<?php
declare(strict_types=1);

namespace YourApp\Monitoring;

final class AlertManager
{
    private array $rules = [];
    private array $handlers = [];
    private array $activeAlerts = [];

    public function addRule(string $name, callable $condition, array $metadata = []): void
    {
        $this->rules[$name] = [
            'condition' => $condition,
            'metadata' => $metadata,
            'last_checked' => null,
            'triggered_count' => 0,
        ];
    }

    public function addHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function check(array $metrics): void
    {
        foreach ($this->rules as $name => $rule) {
            $shouldAlert = ($rule['condition'])($metrics);

            if ($shouldAlert && !isset($this->activeAlerts[$name])) {
                $this->triggerAlert($name, $metrics);
            } elseif (!$shouldAlert && isset($this->activeAlerts[$name])) {
                $this->resolveAlert($name);
            }

            $this->rules[$name]['last_checked'] = time();
        }
    }

    private function triggerAlert(string $name, array $metrics): void
    {
        $alert = [
            'name' => $name,
            'triggered_at' => time(),
            'metadata' => $this->rules[$name]['metadata'],
            'metrics' => $metrics,
        ];

        $this->activeAlerts[$name] = $alert;
        $this->rules[$name]['triggered_count']++;

        // Send to all handlers
        foreach ($this->handlers as $handler) {
            ($handler)('trigger', $alert);
        }
    }

    private function resolveAlert(string $name): void
    {
        $alert = $this->activeAlerts[$name];
        $alert['resolved_at'] = time();
        $alert['duration'] = $alert['resolved_at'] - $alert['triggered_at'];

        unset($this->activeAlerts[$name]);

        // Notify handlers
        foreach ($this->handlers as $handler) {
            ($handler)('resolve', $alert);
        }
    }

    public function getActiveAlerts(): array
    {
        return $this->activeAlerts;
    }
}
```

## Putting It All Together

Here's how to integrate all monitoring components:

```php
// src/Monitoring/MonitoringService.php
<?php
declare(strict_types=1);

namespace YourApp\Monitoring;

use YourApp\Debug\DebugManager;
use YourApp\Debug\Dashboard;

final class MonitoringService
{
    private DebugManager $debugManager;
    private Dashboard $dashboard;
    private TokenTracker $tokenTracker;
    private CostCalculator $costCalculator;
    private PerformanceAnalyzer $performanceAnalyzer;
    private AlertManager $alertManager;

    public function __construct()
    {
        // Initialize components...
        $this->setupAlertRules();
    }

    private function setupAlertRules(): void
    {
        // High cost alert
        $this->alertManager->addRule(
            'high_cost',
            fn($metrics) => $metrics['hourly_cost'] > 10.00,
            ['severity' => 'warning', 'threshold' => 10.00]
        );

        // Performance degradation
        $this->alertManager->addRule(
            'slow_response',
            fn($metrics) => $metrics['avg_response_time'] > 10.0,
            ['severity' => 'critical', 'threshold' => 10.0]
        );

        // Error rate
        $this->alertManager->addRule(
            'high_error_rate',
            fn($metrics) => $metrics['error_rate'] > 0.05,
            ['severity' => 'critical', 'threshold' => 0.05]
        );
    }

    public function collectMetrics(): array
    {
        return [
            'debug' => $this->debugManager->getMetrics(),
            'dashboard' => $this->dashboard->render(),
            'tokens' => $this->tokenTracker->getUsageStats('anthropic'),
            'costs' => $this->costCalculator->checkBudget('anthropic'),
            'performance' => $this->performanceAnalyzer->getBottlenecks(),
            'alerts' => $this->alertManager->getActiveAlerts(),
        ];
    }
}
```

## Summary

You've built a comprehensive monitoring system that provides:

- **Debug tools** for troubleshooting conversations in real-time
- **Token tracking** to monitor usage across providers
- **Cost calculation** with budget alerts
- **Performance profiling** to identify bottlenecks
- **Custom alerting** for proactive issue detection

## Next Steps

In Chapter 24, we'll explore "Scaling and Optimization", learning how to handle high-volume workloads, implement caching strategies, and optimize for production scale.

## Practice Exercises

1. **Add Grafana Integration**: Export metrics to Grafana for visualization
2. **Build Cost Forecasting**: Predict monthly costs based on usage patterns
3. **Create Anomaly Detection**: Identify unusual patterns in conversation flows
4. **Implement SLA Monitoring**: Track and alert on service level objectives

## Key Takeaways

- Debug mode provides graduated levels of insight
- Token tracking prevents rate limit surprises
- Cost monitoring helps control expenses
- Performance profiling identifies optimization opportunities
- Proactive alerting catches issues before users notice
