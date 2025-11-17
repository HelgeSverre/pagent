# Chapter 8: Recursive Tool Execution

**Learning Objectives:**
- Enable recursive tool calling
- Manage execution depth limits
- Handle circular dependencies
- Optimize recursive execution
- Debug recursive call chains

**Prerequisites:** Chapters 6-7 (Tool Implementation, Tool Categories)

**Time Estimate:** 45 minutes

**Final Result:** A robust system for recursive tool execution with depth management and circular dependency prevention

## What You'll Learn

By the end of this chapter, you'll be able to build agents that can recursively call tools to solve complex, multi-step problems. You'll understand how to manage execution depth, prevent infinite loops, and optimize recursive workflows for performance and reliability.

## Understanding Recursive Tool Execution

Recursive tool execution allows agents to call tools that, in turn, trigger additional tool calls. This pattern is essential for complex workflows where the solution requires multiple steps with interdependent results.

### Real-World Analogies

Think of recursive tool execution like a research assistant who needs to gather information from multiple sources. They might:
1. Search for initial information
2. Discover references that need investigation
3. Follow each reference to gather more details
4. Repeat until sufficient information is collected

## Core Components

### 1. Execution Context Manager

First, let's build a context manager that tracks recursive execution state:

```php
<?php

declare(strict_types=1);

namespace Pagent\Tools\Execution;

use Pagent\Tools\Tool;
use RuntimeException;

final class ExecutionContext
{
    private array $callStack = [];
    private array $executionGraph = [];
    private int $currentDepth = 0;
    private array $results = [];

    public function __construct(
        private readonly int $maxDepth = 10,
        private readonly int $maxIterations = 100,
    ) {}

    public function push(Tool $tool, array $parameters): void
    {
        if ($this->currentDepth >= $this->maxDepth) {
            throw new RuntimeException(
                "Maximum recursion depth ({$this->maxDepth}) exceeded"
            );
        }

        $callSignature = $this->getCallSignature($tool, $parameters);

        if ($this->detectCircularDependency($callSignature)) {
            throw new RuntimeException(
                "Circular dependency detected: {$callSignature}"
            );
        }

        $this->callStack[] = [
            'tool' => $tool->getName(),
            'parameters' => $parameters,
            'depth' => $this->currentDepth,
            'timestamp' => microtime(true),
            'signature' => $callSignature,
        ];

        $this->currentDepth++;
    }

    public function pop(): void
    {
        if ($this->currentDepth > 0) {
            $this->currentDepth--;
            $call = array_pop($this->callStack);
            $this->recordInGraph($call);
        }
    }

    private function getCallSignature(Tool $tool, array $parameters): string
    {
        // Create a unique signature for circular dependency detection
        $paramHash = md5(json_encode($parameters));
        return "{$tool->getName()}:{$paramHash}";
    }

    private function detectCircularDependency(string $signature): bool
    {
        // Check if this exact call is already in the current stack
        foreach ($this->callStack as $call) {
            if ($call['signature'] === $signature) {
                return true;
            }
        }
        return false;
    }

    private function recordInGraph(array $call): void
    {
        $this->executionGraph[] = [
            'tool' => $call['tool'],
            'depth' => $call['depth'],
            'duration' => microtime(true) - $call['timestamp'],
            'parameters' => $call['parameters'],
        ];
    }

    public function storeResult(string $toolName, mixed $result): void
    {
        $this->results[$toolName][] = $result;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getExecutionGraph(): array
    {
        return $this->executionGraph;
    }

    public function getCurrentDepth(): int
    {
        return $this->currentDepth;
    }
}
```

### 2. Recursive Tool Executor

Now let's implement the recursive executor that manages tool calls:

```php
<?php

declare(strict_types=1);

namespace Pagent\Tools\Execution;

use Pagent\Agent;
use Pagent\Tools\Tool;
use Pagent\Tools\ToolResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RecursiveExecutor
{
    private ExecutionContext $context;
    private int $totalExecutions = 0;

    public function __construct(
        private readonly Agent $agent,
        private readonly LoggerInterface $logger = new NullLogger(),
        int $maxDepth = 10,
        int $maxIterations = 100,
    ) {
        $this->context = new ExecutionContext($maxDepth, $maxIterations);
    }

    public function execute(Tool $tool, array $parameters = []): ToolResult
    {
        $this->totalExecutions++;

        if ($this->totalExecutions > $this->context->maxIterations) {
            throw new RuntimeException(
                "Maximum iterations ({$this->context->maxIterations}) exceeded"
            );
        }

        $this->logger->info("Executing tool", [
            'tool' => $tool->getName(),
            'depth' => $this->context->getCurrentDepth(),
            'parameters' => $parameters,
        ]);

        try {
            $this->context->push($tool, $parameters);

            // Execute the tool with recursive capability
            $result = $this->executeWithRecursion($tool, $parameters);

            $this->context->storeResult($tool->getName(), $result);

            return $result;
        } finally {
            $this->context->pop();
        }
    }

    private function executeWithRecursion(Tool $tool, array $parameters): ToolResult
    {
        // Inject recursive executor into tool context
        $enrichedParams = array_merge($parameters, [
            '_executor' => $this,
            '_context' => $this->context,
            '_agent' => $this->agent,
        ]);

        // Execute the tool
        $result = $tool->execute($enrichedParams);

        // Process any recursive calls in the result
        if ($result->hasNestedCalls()) {
            return $this->processNestedCalls($result);
        }

        return $result;
    }

    private function processNestedCalls(ToolResult $result): ToolResult
    {
        $nestedResults = [];

        foreach ($result->getNestedCalls() as $call) {
            $tool = $this->agent->getTool($call['tool']);
            $nestedResult = $this->execute($tool, $call['parameters'] ?? []);
            $nestedResults[] = $nestedResult;
        }

        // Merge nested results back into the original result
        return $result->withNestedResults($nestedResults);
    }

    public function getExecutionReport(): array
    {
        return [
            'total_executions' => $this->totalExecutions,
            'max_depth_reached' => max(array_column(
                $this->context->getExecutionGraph(),
                'depth'
            )),
            'execution_graph' => $this->context->getExecutionGraph(),
            'results' => $this->context->getResults(),
        ];
    }
}
```

## Practical Examples

### Example 1: Multi-Step Research Assistant

Let's build a research assistant that recursively gathers information:

```php
<?php

declare(strict_types=1);

namespace Pagent\Tools\Examples;

use Pagent\Tools\Tool;
use Pagent\Tools\ToolResult;

final class ResearchTool extends Tool
{
    public function getName(): string
    {
        return 'research';
    }

    public function execute(array $parameters): ToolResult
    {
        $topic = $parameters['topic'];
        $depth = $parameters['depth'] ?? 0;
        $executor = $parameters['_executor'];

        // Initial search
        $searchResults = $this->performSearch($topic);

        // If we find related topics and haven't reached max depth
        if ($depth < 3 && !empty($searchResults['related_topics'])) {
            $nestedCalls = [];

            foreach ($searchResults['related_topics'] as $relatedTopic) {
                $nestedCalls[] = [
                    'tool' => 'research',
                    'parameters' => [
                        'topic' => $relatedTopic,
                        'depth' => $depth + 1,
                    ],
                ];
            }

            return new ToolResult(
                success: true,
                data: $searchResults,
                nestedCalls: $nestedCalls,
            );
        }

        return new ToolResult(
            success: true,
            data: $searchResults,
        );
    }

    private function performSearch(string $topic): array
    {
        // Simulate search results
        return [
            'topic' => $topic,
            'summary' => "Information about {$topic}",
            'sources' => ["source1.com", "source2.org"],
            'related_topics' => $this->findRelatedTopics($topic),
        ];
    }

    private function findRelatedTopics(string $topic): array
    {
        // Simulate finding related topics
        $topics = [
            'AI' => ['machine learning', 'deep learning', 'neural networks'],
            'machine learning' => ['supervised learning', 'unsupervised learning'],
            'deep learning' => ['CNNs', 'RNNs', 'transformers'],
        ];

        return $topics[$topic] ?? [];
    }
}
```

### Example 2: Recursive Web Scraper

Build a web scraper that follows links recursively:

```php
<?php

declare(strict_types=1);

namespace Pagent\Tools\Examples;

use Pagent\Tools\Tool;
use Pagent\Tools\ToolResult;

final class WebScraperTool extends Tool
{
    private array $visitedUrls = [];

    public function getName(): string
    {
        return 'scraper';
    }

    public function execute(array $parameters): ToolResult
    {
        $url = $parameters['url'];
        $followLinks = $parameters['follow_links'] ?? true;
        $maxPages = $parameters['max_pages'] ?? 10;
        $context = $parameters['_context'];

        // Check if already visited
        if (in_array($url, $this->visitedUrls, true)) {
            return new ToolResult(
                success: true,
                data: ['message' => 'Already visited'],
            );
        }

        $this->visitedUrls[] = $url;

        // Scrape the page
        $pageData = $this->scrapePage($url);

        $nestedCalls = [];
        if ($followLinks && count($this->visitedUrls) < $maxPages) {
            foreach ($pageData['links'] as $link) {
                if ($this->shouldFollow($link)) {
                    $nestedCalls[] = [
                        'tool' => 'scraper',
                        'parameters' => [
                            'url' => $link,
                            'follow_links' => true,
                            'max_pages' => $maxPages,
                        ],
                    ];
                }
            }
        }

        return new ToolResult(
            success: true,
            data: $pageData,
            nestedCalls: $nestedCalls,
        );
    }

    private function scrapePage(string $url): array
    {
        // Simulate page scraping
        return [
            'url' => $url,
            'title' => "Page at {$url}",
            'content' => "Content from {$url}",
            'links' => $this->extractLinks($url),
        ];
    }

    private function extractLinks(string $url): array
    {
        // Simulate link extraction
        $domain = parse_url($url, PHP_URL_HOST);
        return [
            "https://{$domain}/page1",
            "https://{$domain}/page2",
            "https://{$domain}/page3",
        ];
    }

    private function shouldFollow(string $url): bool
    {
        // Add logic to determine if link should be followed
        return !in_array($url, $this->visitedUrls, true);
    }
}
```

### Example 3: Nested API Orchestrator

Create an orchestrator that coordinates multiple API calls:

```php
<?php

declare(strict_types=1);

namespace Pagent\Tools\Examples;

use Pagent\Tools\Tool;
use Pagent\Tools\ToolResult;

final class ApiOrchestratorTool extends Tool
{
    public function getName(): string
    {
        return 'api_orchestrator';
    }

    public function execute(array $parameters): ToolResult
    {
        $workflow = $parameters['workflow'];
        $context = $parameters['_context'];

        $results = [];
        $nestedCalls = [];

        foreach ($workflow['steps'] as $step) {
            if ($this->shouldExecute($step, $results)) {
                $nestedCalls[] = $this->buildApiCall($step, $results);
            }
        }

        return new ToolResult(
            success: true,
            data: ['workflow' => $workflow['name']],
            nestedCalls: $nestedCalls,
        );
    }

    private function shouldExecute(array $step, array $results): bool
    {
        if (empty($step['depends_on'])) {
            return true;
        }

        foreach ($step['depends_on'] as $dependency) {
            if (!isset($results[$dependency])) {
                return false;
            }
        }

        return true;
    }

    private function buildApiCall(array $step, array $results): array
    {
        $parameters = $step['parameters'];

        // Inject results from dependencies
        foreach ($step['depends_on'] ?? [] as $dependency) {
            $parameters["{$dependency}_result"] = $results[$dependency];
        }

        return [
            'tool' => 'api_call',
            'parameters' => $parameters,
        ];
    }
}
```

## Optimization Strategies

### 1. Memoization for Repeated Calls

Cache results to avoid redundant recursive calls:

```php
final class MemoizedExecutor extends RecursiveExecutor
{
    private array $cache = [];

    public function execute(Tool $tool, array $parameters = []): ToolResult
    {
        $cacheKey = $this->getCacheKey($tool, $parameters);

        if (isset($this->cache[$cacheKey])) {
            $this->logger->info("Cache hit", ['tool' => $tool->getName()]);
            return $this->cache[$cacheKey];
        }

        $result = parent::execute($tool, $parameters);
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    private function getCacheKey(Tool $tool, array $parameters): string
    {
        return md5($tool->getName() . json_encode($parameters));
    }
}
```

### 2. Parallel Execution for Independent Calls

Execute independent recursive calls in parallel:

```php
final class ParallelExecutor extends RecursiveExecutor
{
    protected function processNestedCalls(ToolResult $result): ToolResult
    {
        $groups = $this->groupIndependentCalls($result->getNestedCalls());
        $allResults = [];

        foreach ($groups as $group) {
            $groupResults = $this->executeParallel($group);
            $allResults = array_merge($allResults, $groupResults);
        }

        return $result->withNestedResults($allResults);
    }

    private function groupIndependentCalls(array $calls): array
    {
        // Group calls that can be executed in parallel
        // Implementation depends on dependency analysis
        return [$calls]; // Simplified
    }

    private function executeParallel(array $calls): array
    {
        // Execute calls in parallel using async/await or process forking
        // Simplified synchronous version:
        return array_map(
            fn($call) => $this->execute(
                $this->agent->getTool($call['tool']),
                $call['parameters']
            ),
            $calls
        );
    }
}
```

## Debugging Recursive Chains

### Execution Visualizer

Create a tool to visualize the execution graph:

```php
final class ExecutionVisualizer
{
    public function visualize(array $executionGraph): string
    {
        $output = "Execution Graph:\n";
        $output .= str_repeat("=", 50) . "\n";

        foreach ($executionGraph as $index => $node) {
            $indent = str_repeat("  ", $node['depth']);
            $output .= sprintf(
                "%s[%d] %s (%.3fs)\n",
                $indent,
                $index,
                $node['tool'],
                $node['duration']
            );
        }

        return $output;
    }

    public function generateDotGraph(array $executionGraph): string
    {
        $dot = "digraph ExecutionGraph {\n";
        $dot .= "  rankdir=TB;\n";

        $lastNodeByDepth = [];
        foreach ($executionGraph as $index => $node) {
            $dot .= sprintf(
                '  node%d [label="%s\ndepth:%d\n%.3fs"];\n',
                $index,
                $node['tool'],
                $node['depth'],
                $node['duration']
            );

            if (isset($lastNodeByDepth[$node['depth'] - 1])) {
                $parentIndex = $lastNodeByDepth[$node['depth'] - 1];
                $dot .= sprintf("  node%d -> node%d;\n", $parentIndex, $index);
            }

            $lastNodeByDepth[$node['depth']] = $index;
        }

        $dot .= "}\n";
        return $dot;
    }
}
```

## Testing Recursive Execution

```php
test('prevents infinite recursion', function () {
    $executor = new RecursiveExecutor(agent(), maxDepth: 5);
    $tool = new InfiniteRecursionTool();

    expect(fn() => $executor->execute($tool))
        ->toThrow(RuntimeException::class, 'Maximum recursion depth');
});

test('detects circular dependencies', function () {
    $executor = new RecursiveExecutor(agent());
    $tool = new CircularDependencyTool();

    expect(fn() => $executor->execute($tool, ['id' => 'A']))
        ->toThrow(RuntimeException::class, 'Circular dependency detected');
});

test('executes complex workflow', function () {
    $executor = new RecursiveExecutor(agent());
    $orchestrator = new ApiOrchestratorTool();

    $workflow = [
        'name' => 'user_onboarding',
        'steps' => [
            ['name' => 'create_user', 'depends_on' => []],
            ['name' => 'send_email', 'depends_on' => ['create_user']],
            ['name' => 'create_profile', 'depends_on' => ['create_user']],
        ],
    ];

    $result = $executor->execute($orchestrator, ['workflow' => $workflow]);

    expect($result->isSuccess())->toBeTrue();
    expect($executor->getExecutionReport()['total_executions'])->toBe(4);
});
```

## Summary

You've learned how to implement recursive tool execution in Pagent, including:
- Building execution context managers
- Preventing infinite loops and circular dependencies
- Optimizing recursive workflows with memoization and parallelization
- Debugging complex execution chains
- Testing recursive execution patterns

## Next Steps

In Chapter 9, we'll explore async tool execution patterns, learning how to handle long-running operations and build responsive agents that can manage multiple concurrent tasks efficiently.

## Additional Resources

- [Pagent Documentation - Advanced Tools](https://github.com/hhelge/pagent/docs/tools)
- [Graph Theory for Developers](https://example.com/graph-theory)
- [Async PHP Patterns](https://example.com/async-php)
- [Recursion Best Practices](https://example.com/recursion)