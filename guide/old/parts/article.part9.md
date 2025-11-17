# Chapter 9: Tool Orchestration Patterns

## What You'll Learn

By the end of this chapter, you'll be able to:
- Implement sequential tool execution with proper dependency handling
- Build parallel tool operations for efficient data processing
- Create conditional tool flows based on runtime decisions
- Handle complex tool dependencies and error propagation
- Optimize tool call batching for better performance

**Prerequisites:** You should have completed Chapters 6-8 and understand tool creation, validation, and advanced features.

**Time Estimate:** 45-60 minutes

**Final Result:** You'll build several orchestration patterns including a data pipeline, multi-source aggregator, conditional workflow, and batch processing system.

## Understanding Tool Orchestration

Tool orchestration is about coordinating multiple tools to accomplish complex tasks. Think of it like conducting an orchestra - each instrument (tool) plays its part, but the real magic happens when they work together in harmony.

### Why Orchestration Matters

When building AI agents that interact with real-world systems, you rarely use just one tool. Consider these scenarios:

1. **Data Pipeline**: Fetch data → Transform it → Store results → Generate report
2. **Multi-Source Analysis**: Query multiple APIs in parallel → Aggregate results → Make decision
3. **Conditional Workflows**: Check condition → Execute different tool chains based on result
4. **Batch Operations**: Process multiple items → Collect results → Handle failures gracefully

Let's explore patterns for each scenario.

## Pattern 1: Sequential Tool Execution

Sequential execution is the simplest pattern - tools execute one after another, with each tool potentially using results from previous tools.

### Basic Sequential Pipeline

```php
<?php

namespace App\Orchestration;

use Pagent\Tool;
use Pagent\ToolExecutionResult;

final class SequentialPipeline
{
    private array $steps = [];
    private array $results = [];

    public function addStep(string $name, Tool $tool, array $params = []): self
    {
        $this->steps[] = [
            'name' => $name,
            'tool' => $tool,
            'params' => $params,
        ];

        return $this;
    }

    public function execute(array $initialContext = []): array
    {
        $context = $initialContext;

        foreach ($this->steps as $step) {
            // Resolve parameters using current context
            $resolvedParams = $this->resolveParams($step['params'], $context);

            // Execute tool
            $result = $step['tool']->execute($resolvedParams);

            // Store result
            $this->results[$step['name']] = $result;

            // Update context for next step
            $context[$step['name']] = $result->data;

            // Check for errors
            if ($result->error !== null) {
                throw new \RuntimeException(
                    "Pipeline failed at step '{$step['name']}': {$result->error}"
                );
            }
        }

        return $this->results;
    }

    private function resolveParams(array $params, array $context): array
    {
        $resolved = [];

        foreach ($params as $key => $value) {
            if (is_string($value) && str_starts_with($value, '$')) {
                // Reference to previous result
                $path = substr($value, 1);
                $resolved[$key] = $this->getValueByPath($context, $path);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    private function getValueByPath(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                throw new \RuntimeException("Path '$path' not found in context");
            }
            $current = $current[$key];
        }

        return $current;
    }
}
```

### Using the Sequential Pipeline

```php
// Create tools
$fetchTool = Tool::make('fetch_data')
    ->for('Fetch data from API')
    ->withParameter('endpoint', 'API endpoint')
    ->using(fn($params) => [
        'data' => file_get_contents('https://api.example.com/' . $params['endpoint'])
    ]);

$transformTool = Tool::make('transform')
    ->for('Transform JSON data')
    ->withParameter('data', 'Raw data to transform')
    ->using(fn($params) => [
        'transformed' => array_map(
            fn($item) => ['id' => $item['id'], 'name' => strtoupper($item['name'])],
            json_decode($params['data'], true)
        )
    ]);

$saveTool = Tool::make('save')
    ->for('Save data to file')
    ->withParameter('data', 'Data to save')
    ->withParameter('filename', 'Target filename')
    ->using(fn($params) => [
        'saved' => file_put_contents(
            $params['filename'],
            json_encode($params['data'])
        )
    ]);

// Build and execute pipeline
$pipeline = new SequentialPipeline();
$pipeline
    ->addStep('fetch', $fetchTool, ['endpoint' => 'users'])
    ->addStep('transform', $transformTool, ['data' => '$fetch.data'])
    ->addStep('save', $saveTool, [
        'data' => '$transform.transformed',
        'filename' => 'users.json'
    ]);

$results = $pipeline->execute();
```

Notice how each step can reference results from previous steps using the `$stepName.property` syntax.

## Pattern 2: Parallel Tool Execution

When tools don't depend on each other, executing them in parallel can significantly improve performance.

### Parallel Executor

```php
<?php

namespace App\Orchestration;

use Pagent\Tool;
use parallel\Runtime;
use parallel\Channel;

final class ParallelExecutor
{
    private array $tasks = [];
    private int $maxWorkers;

    public function __construct(int $maxWorkers = 4)
    {
        $this->maxWorkers = $maxWorkers;
    }

    public function addTask(string $name, Tool $tool, array $params): self
    {
        $this->tasks[$name] = [
            'tool' => $tool,
            'params' => $params,
        ];

        return $this;
    }

    public function execute(): array
    {
        $results = [];
        $chunks = array_chunk($this->tasks, $this->maxWorkers, true);

        foreach ($chunks as $chunk) {
            $promises = [];

            foreach ($chunk as $name => $task) {
                // Execute in separate thread
                $promises[$name] = $this->executeAsync($task['tool'], $task['params']);
            }

            // Wait for all to complete
            foreach ($promises as $name => $promise) {
                $results[$name] = $promise->value();
            }
        }

        return $results;
    }

    private function executeAsync(Tool $tool, array $params): \parallel\Future
    {
        $runtime = new Runtime();

        return $runtime->run(function() use ($tool, $params) {
            return $tool->execute($params);
        });
    }
}
```

### Multi-Source Aggregator Example

```php
final class MultiSourceAggregator
{
    private ParallelExecutor $executor;
    private array $sources = [];

    public function __construct()
    {
        $this->executor = new ParallelExecutor(10); // 10 concurrent requests
    }

    public function addSource(string $name, string $url, array $headers = []): self
    {
        $tool = Tool::make("fetch_$name")
            ->for("Fetch data from $name")
            ->withParameter('url', 'URL to fetch')
            ->withParameter('headers', 'Request headers')
            ->using(function($params) {
                $context = stream_context_create([
                    'http' => [
                        'header' => array_map(
                            fn($k, $v) => "$k: $v",
                            array_keys($params['headers']),
                            $params['headers']
                        )
                    ]
                ]);

                return [
                    'data' => file_get_contents($params['url'], false, $context),
                    'timestamp' => time(),
                ];
            });

        $this->executor->addTask($name, $tool, [
            'url' => $url,
            'headers' => $headers,
        ]);

        return $this;
    }

    public function aggregate(): array
    {
        $results = $this->executor->execute();

        // Transform results
        $aggregated = [
            'sources' => [],
            'total_items' => 0,
            'fetch_times' => [],
        ];

        foreach ($results as $source => $result) {
            if ($result->error === null) {
                $data = json_decode($result->data['data'], true);
                $aggregated['sources'][$source] = $data;
                $aggregated['total_items'] += count($data);
                $aggregated['fetch_times'][$source] = $result->data['timestamp'];
            } else {
                $aggregated['sources'][$source] = ['error' => $result->error];
            }
        }

        return $aggregated;
    }
}

// Usage
$aggregator = new MultiSourceAggregator();
$data = $aggregator
    ->addSource('github', 'https://api.github.com/users/octocat/repos')
    ->addSource('gitlab', 'https://gitlab.com/api/v4/users/1/projects')
    ->addSource('bitbucket', 'https://api.bitbucket.org/2.0/repositories/atlassian')
    ->aggregate();

echo "Fetched {$data['total_items']} items from " . count($data['sources']) . " sources\n";
```

## Pattern 3: Conditional Tool Flows

Real-world workflows often require branching based on conditions. Let's build a flexible conditional executor.

### Conditional Workflow Engine

```php
<?php

namespace App\Orchestration;

final class ConditionalWorkflow
{
    private array $steps = [];
    private array $context = [];

    public function when(callable $condition): ConditionalBranch
    {
        $branch = new ConditionalBranch($this, $condition);
        $this->steps[] = $branch;
        return $branch;
    }

    public function always(): SequentialBranch
    {
        $branch = new SequentialBranch($this);
        $this->steps[] = $branch;
        return $branch;
    }

    public function execute(array $initialContext = []): array
    {
        $this->context = $initialContext;

        foreach ($this->steps as $step) {
            $step->execute($this->context);
        }

        return $this->context;
    }

    public function updateContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }
}

final class ConditionalBranch
{
    private ConditionalWorkflow $workflow;
    private $condition;
    private array $thenTools = [];
    private array $elseTools = [];

    public function __construct(ConditionalWorkflow $workflow, callable $condition)
    {
        $this->workflow = $workflow;
        $this->condition = $condition;
    }

    public function then(Tool $tool, array $params = []): self
    {
        $this->thenTools[] = ['tool' => $tool, 'params' => $params];
        return $this;
    }

    public function otherwise(Tool $tool, array $params = []): self
    {
        $this->elseTools[] = ['tool' => $tool, 'params' => $params];
        return $this;
    }

    public function execute(array &$context): void
    {
        $tools = ($this->condition)($context) ? $this->thenTools : $this->elseTools;

        foreach ($tools as $toolConfig) {
            $result = $toolConfig['tool']->execute(
                $this->resolveParams($toolConfig['params'], $context)
            );

            // Update workflow context
            $this->workflow->updateContext(
                $toolConfig['tool']->name,
                $result->data
            );
        }
    }

    private function resolveParams(array $params, array $context): array
    {
        // Parameter resolution logic (same as SequentialPipeline)
        return $params;
    }
}
```

### Complex Conditional Workflow Example

```php
// Define tools
$checkInventoryTool = Tool::make('check_inventory')
    ->for('Check product availability')
    ->withParameter('product_id', 'Product ID')
    ->using(fn($p) => ['available' => rand(0, 100)]);

$fastShippingTool = Tool::make('fast_shipping')
    ->for('Calculate fast shipping')
    ->using(fn() => ['method' => 'express', 'days' => 1, 'cost' => 25]);

$standardShippingTool = Tool::make('standard_shipping')
    ->for('Calculate standard shipping')
    ->using(fn() => ['method' => 'standard', 'days' => 5, 'cost' => 10]);

$applyDiscountTool = Tool::make('apply_discount')
    ->for('Apply bulk discount')
    ->withParameter('quantity', 'Order quantity')
    ->using(fn($p) => ['discount' => $p['quantity'] > 10 ? 0.15 : 0]);

$calculateTotalTool = Tool::make('calculate_total')
    ->for('Calculate order total')
    ->withParameter('items', 'Order items')
    ->withParameter('shipping', 'Shipping cost')
    ->withParameter('discount', 'Discount rate')
    ->using(function($p) {
        $subtotal = array_sum(array_column($p['items'], 'price'));
        $discount = $subtotal * $p['discount'];
        return ['total' => $subtotal - $discount + $p['shipping']];
    });

// Build workflow
$workflow = new ConditionalWorkflow();

$workflow
    ->always()
        ->execute($checkInventoryTool, ['product_id' => 123])
    ->when(fn($ctx) => $ctx['check_inventory']['available'] > 50)
        ->then($fastShippingTool)
        ->then($applyDiscountTool, ['quantity' => 20])
        ->otherwise($standardShippingTool)
    ->when(fn($ctx) => isset($ctx['apply_discount']))
        ->then($calculateTotalTool, [
            'items' => [['price' => 100], ['price' => 200]],
            'shipping' => '$fast_shipping.cost',
            'discount' => '$apply_discount.discount'
        ]);

$result = $workflow->execute();
```

## Pattern 4: Batch Processing with Error Handling

When processing multiple items, you need robust error handling and progress tracking.

### Batch Processor

```php
final class BatchProcessor
{
    private Tool $tool;
    private array $items = [];
    private int $batchSize;
    private bool $continueOnError;
    private array $results = [];
    private array $errors = [];

    public function __construct(
        Tool $tool,
        int $batchSize = 10,
        bool $continueOnError = true
    ) {
        $this->tool = $tool;
        $this->batchSize = $batchSize;
        $this->continueOnError = $continueOnError;
    }

    public function addItems(array $items): self
    {
        $this->items = array_merge($this->items, $items);
        return $this;
    }

    public function process(callable $progressCallback = null): BatchResult
    {
        $batches = array_chunk($this->items, $this->batchSize);
        $totalBatches = count($batches);
        $processedItems = 0;

        foreach ($batches as $batchIndex => $batch) {
            $batchResults = $this->processBatch($batch);

            foreach ($batchResults as $index => $result) {
                if ($result['success']) {
                    $this->results[] = $result['data'];
                } else {
                    $this->errors[] = [
                        'item' => $batch[$index],
                        'error' => $result['error'],
                        'index' => $processedItems + $index,
                    ];

                    if (!$this->continueOnError) {
                        break 2; // Exit both loops
                    }
                }
            }

            $processedItems += count($batch);

            if ($progressCallback) {
                $progressCallback([
                    'batch' => $batchIndex + 1,
                    'total_batches' => $totalBatches,
                    'processed_items' => $processedItems,
                    'total_items' => count($this->items),
                    'errors' => count($this->errors),
                ]);
            }
        }

        return new BatchResult($this->results, $this->errors);
    }

    private function processBatch(array $batch): array
    {
        $results = [];

        // Process batch in parallel
        $executor = new ParallelExecutor(count($batch));

        foreach ($batch as $index => $item) {
            try {
                $result = $this->tool->execute(['item' => $item]);
                $results[$index] = [
                    'success' => $result->error === null,
                    'data' => $result->data,
                    'error' => $result->error,
                ];
            } catch (\Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'data' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}

final class BatchResult
{
    public function __construct(
        public readonly array $successful,
        public readonly array $failed
    ) {}

    public function getSuccessRate(): float
    {
        $total = count($this->successful) + count($this->failed);
        return $total > 0 ? count($this->successful) / $total : 0;
    }

    public function hasErrors(): bool
    {
        return count($this->failed) > 0;
    }
}
```

### Using the Batch Processor

```php
// Create a processing tool
$processTool = Tool::make('process_record')
    ->for('Process a single record')
    ->withParameter('item', 'Record to process')
    ->using(function($params) {
        $item = $params['item'];

        // Simulate processing with occasional failures
        if ($item['id'] % 7 === 0) {
            throw new \Exception("Invalid record format");
        }

        return [
            'id' => $item['id'],
            'processed' => strtoupper($item['name']),
            'timestamp' => time(),
        ];
    });

// Create processor
$processor = new BatchProcessor($processTool, batchSize: 25);

// Add items
$items = array_map(
    fn($i) => ['id' => $i, 'name' => "Item $i"],
    range(1, 100)
);

$processor->addItems($items);

// Process with progress tracking
$result = $processor->process(function($progress) {
    echo sprintf(
        "Batch %d/%d: Processed %d/%d items (%d errors)\n",
        $progress['batch'],
        $progress['total_batches'],
        $progress['processed_items'],
        $progress['total_items'],
        $progress['errors']
    );
});

echo sprintf(
    "Processing complete: %.1f%% success rate\n",
    $result->getSuccessRate() * 100
);

if ($result->hasErrors()) {
    echo "Errors encountered:\n";
    foreach ($result->failed as $error) {
        echo " - Item {$error['index']}: {$error['error']}\n";
    }
}
```

## Optimizing Tool Orchestration

### Performance Considerations

1. **Minimize Tool Calls**: Batch operations when possible
2. **Use Parallel Execution**: For independent operations
3. **Cache Results**: Avoid redundant tool executions
4. **Lazy Evaluation**: Only execute tools when needed

### Error Handling Strategies

1. **Fail Fast**: Stop on first error for critical workflows
2. **Graceful Degradation**: Continue with partial results
3. **Retry Logic**: Implement exponential backoff for transient failures
4. **Circuit Breakers**: Prevent cascading failures

### Example: Optimized Pipeline with Caching

```php
final class OptimizedPipeline
{
    private array $cache = [];
    private array $metrics = [
        'tool_calls' => 0,
        'cache_hits' => 0,
        'execution_time' => 0,
    ];

    public function executeTool(Tool $tool, array $params): mixed
    {
        $cacheKey = $this->getCacheKey($tool->name, $params);

        if (isset($this->cache[$cacheKey])) {
            $this->metrics['cache_hits']++;
            return $this->cache[$cacheKey];
        }

        $start = microtime(true);
        $result = $tool->execute($params);
        $this->metrics['execution_time'] += microtime(true) - $start;
        $this->metrics['tool_calls']++;

        $this->cache[$cacheKey] = $result;

        return $result;
    }

    private function getCacheKey(string $toolName, array $params): string
    {
        return $toolName . ':' . md5(serialize($params));
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
```

## Summary

You've learned four powerful orchestration patterns:

1. **Sequential Pipelines** for dependent operations
2. **Parallel Execution** for independent tasks
3. **Conditional Workflows** for dynamic branching
4. **Batch Processing** for handling multiple items

### Key Takeaways

- Choose the right pattern based on tool dependencies
- Always handle errors appropriately for your use case
- Monitor and optimize performance with caching and batching
- Test orchestration logic independently from tool implementations

## Next Steps

In Chapter 10, we'll explore **Testing and Debugging Strategies** to ensure your orchestrated workflows are reliable and maintainable. You'll learn:

- Unit testing individual tools
- Integration testing orchestration patterns
- Debugging complex tool interactions
- Performance profiling and optimization

## Practice Exercises

1. **Build a Data ETL Pipeline**: Create a sequential pipeline that extracts data from an API, transforms it, and loads it into a database.

2. **Implement a Scatter-Gather Pattern**: Fetch data from 5 different sources in parallel, then aggregate and deduplicate results.

3. **Create a Retry Mechanism**: Extend the batch processor with configurable retry logic and exponential backoff.

4. **Design a State Machine**: Build a workflow that maintains state across tool executions and can resume from failures.

## Additional Resources

- [Parallel Processing in PHP](https://www.php.net/manual/en/book.parallel.php)
- [Enterprise Integration Patterns](https://www.enterpriseintegrationpatterns.com/)
- [Workflow Patterns Documentation](http://www.workflowpatterns.com/)
- [Circuit Breaker Pattern](https://martinfowler.com/bliki/CircuitBreaker.html)