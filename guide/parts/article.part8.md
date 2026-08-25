# Chapter 8: Recursive Tool Execution

**Learning Objectives:**

- Understand automatic recursive tool calling
- Manage execution depth limits
- Handle multi-step tool workflows
- Debug recursive call chains
- Optimize recursive execution patterns

**Prerequisites:** Chapters 6-7 (Tool Implementation, Tool Categories)

**Time Estimate:** 35 minutes

**Final Result:** A deep understanding of how Pagent automatically handles recursive tool calls and how to build agents that leverage this for complex multi-step workflows

## What You'll Learn

By the end of this chapter, you'll understand how Pagent's built-in recursive tool execution works, how to prevent infinite loops, and how to build complex multi-step workflows.

## Understanding Automatic Recursive Tool Calling

When an LLM response includes tool calls, Pagent doesn't just execute them once and stop. It continues calling the LLM with the tool results, allowing the model to make additional tool calls based on those results. This recursive loop continues until the LLM produces a final response without any tool calls.

### Real-World Analogy

Think of a researcher gathering information:

1. You ask: "Find information about renewable energy"
2. Researcher searches and finds initial articles
3. "Let me fetch the full text of this article"
4. Researcher fetches and reads the content
5. "This mentions a key study, let me look that up"
6. Researcher searches for the study
7. Finally synthesizes all information into a complete answer

Each step leads to the next based on what was learned. Pagent handles this flow automatically.

## How Pagent Implements Recursive Tool Calling

Pagent implements recursive tool calling through a simple but powerful loop in the `prompt()` method. Let's examine how it works:

```php
// From src/Agent.php:272-286
$toolCallDepth = 0;
while (! empty($response->tool_calls)) {
    $toolCallDepth++;

    if ($toolCallDepth > self::MAX_TOOL_CALL_DEPTH) {
        throw new RuntimeException(
            sprintf(
                'Maximum tool call depth exceeded (%d calls). Possible infinite loop detected.',
                self::MAX_TOOL_CALL_DEPTH
            )
        );
    }

    $response = $this->handleToolCalls($response);
}
```

### The Execution Flow

Here's what happens step by step:

1. **Initial Prompt**: You call `$agent->prompt("Do something complex")`
2. **First LLM Call**: Agent sends your message to the LLM with tool schemas
3. **Tool Calls Returned**: LLM responds with `tool_calls` instead of just text
4. **Execute Tools**: Agent executes each tool via `handleToolCalls()`
5. **Add Results to History**: Tool results are formatted and added to message history
6. **Next LLM Call**: Agent calls LLM again with the tool results
7. **Loop Continues**: Steps 3-6 repeat until LLM returns a response without tool calls
8. **Final Response**: Loop exits and final answer is returned to you

### The Depth Limit

Pagent protects against infinite loops with a hardcoded depth limit:

```php
// From src/Agent.php:58
private const MAX_TOOL_CALL_DEPTH = 10;
```

This means an agent can execute up to 10 rounds of tool calls in a single `prompt()` invocation. If the LLM keeps requesting tool calls beyond this limit, Pagent throws a `RuntimeException` to prevent runaway execution.

## Building Multi-Step Workflows

Let's build practical examples that leverage recursive tool execution.

### Example 1: Research Assistant

A research assistant that progressively gathers information:

```php
<?php

use Pagent\Tool\Tool;

$researcher = agent('researcher')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->system('You are a research assistant. Use tools to gather information.')
    ->tool(Tool::fromClosure(
        'search_web',
        'Search the web for information',
        function (string $query): string {
            return json_encode([
                'results' => [
                    ['title' => "Intro to {$query}", 'url' => 'https://example.com/1'],
                    ['title' => "Advanced {$query}", 'url' => 'https://example.com/2'],
                ],
            ]);
        }
    ))
    ->tool(Tool::fromClosure(
        'fetch_page',
        'Fetch full content of a web page',
        function (string $url): string {
            return json_encode([
                'url' => $url,
                'content' => 'Full article content with details...',
                'related_links' => ['https://example.com/related'],
            ]);
        }
    ))
    ->tool(Tool::fromClosure(
        'extract_facts',
        'Extract key facts from text',
        function (string $content): string {
            return json_encode([
                'facts' => ['Fact 1', 'Fact 2', 'Fact 3'],
                'statistics' => ['Stat 1', 'Stat 2'],
            ]);
        }
    ));

$response = $researcher->prompt('Research quantum computing with key facts');

// The LLM automatically chains tools:
// 1. search_web('quantum computing')
// 2. fetch_page(url from results)
// 3. extract_facts(fetched content)
// 4. Possibly search_web() again for related topics
// 5. Synthesize final answer
```

### Example 2: Data Processing Pipeline

Build an agent that progressively processes data through multiple transformations:

```php
<?php

use Pagent\Tool\Tool;

$dataStore = []; // Simple in-memory store

$processor = agent('data_processor')
    ->provider(openai())
    ->model('gpt-4')
    ->system('You are a data processing assistant. Use tools to fetch, validate, and transform data.')
    ->tool(Tool::fromClosure(
        'fetch_data',
        'Fetch data from a source by name',
        function (string $source) use (&$dataStore): string {
            $data = match($source) {
                'sales' => ['records' => 150, 'total' => 45000, 'currency' => 'USD'],
                'inventory' => ['items' => 200, 'low_stock' => 15],
                default => ['error' => 'Unknown source'],
            };
            $dataStore[$source] = $data;
            return json_encode($data);
        }
    ))
    ->tool(Tool::fromClosure(
        'validate_data',
        'Validate data structure and completeness',
        function (string $source) use (&$dataStore): string {
            if (!isset($dataStore[$source])) {
                return json_encode(['valid' => false, 'error' => 'Data not loaded']);
            }
            return json_encode(['valid' => true, 'source' => $source]);
        }
    ))
    ->tool(Tool::fromClosure(
        'transform_data',
        'Transform data using a specified operation',
        function (string $source, string $operation) use (&$dataStore): string {
            $data = $dataStore[$source] ?? [];
            $transformed = match($operation) {
                'summarize' => ['count' => count($data), 'keys' => array_keys($data)],
                default => $data,
            };
            return json_encode($transformed);
        }
    ));

$response = $processor->prompt(
    'Load sales and inventory data, validate both, and summarize the sales data'
);

// The LLM will automatically chain tools:
// 1. fetch_data('sales')
// 2. fetch_data('inventory')
// 3. validate_data('sales')
// 4. validate_data('inventory')
// 5. transform_data('sales', 'summarize')
```

### Example 3: API Orchestration with Dependencies

Create an agent that orchestrates API calls with authentication dependencies:

```php
<?php

use Pagent\Tool\Tool;

$sessionData = [];

$orchestrator = agent('api_orchestrator')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->system('Call APIs in the correct order respecting dependencies.')
    ->tool(Tool::fromClosure(
        'authenticate',
        'Authenticate and get an access token (must be called first)',
        function (string $username) use (&$sessionData): string {
            $token = 'token_' . md5($username . time());
            $sessionData['token'] = $token;
            return json_encode(['success' => true, 'token' => $token]);
        }
    ))
    ->tool(Tool::fromClosure(
        'get_user_profile',
        'Get user profile (requires authentication)',
        function () use (&$sessionData): string {
            if (!isset($sessionData['token'])) {
                return json_encode(['error' => 'Not authenticated. Call authenticate first.']);
            }
            return json_encode(['user_id' => 12345, 'username' => 'john']);
        }
    ))
    ->tool(Tool::fromClosure(
        'get_user_orders',
        'Get user orders (requires authentication)',
        function () use (&$sessionData): string {
            if (!isset($sessionData['token'])) {
                return json_encode(['error' => 'Not authenticated']);
            }
            return json_encode([
                'orders' => [
                    ['id' => 1, 'status' => 'shipped'],
                    ['id' => 2, 'status' => 'pending'],
                ],
            ]);
        }
    ));

$response = $orchestrator->prompt('Get profile and orders for user john@example.com');

// The LLM automatically handles dependencies:
// 1. authenticate('john@example.com') - called first
// 2. get_user_profile() - uses token from step 1
// 3. get_user_orders() - uses token from step 1
```

## Debugging Recursive Tool Chains

When working with recursive tool execution, inspect the agent's message history to understand what happened:

### Inspecting Message History

```php
<?php

$agent = agent('debugger')->provider(anthropic())->tool(/* ... */);
$response = $agent->prompt('Complex multi-step task');

// Count tool call rounds
$rounds = 0;
foreach ($agent->messages as $message) {
    if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
        $rounds++;
        echo "Round {$rounds}: ";
        foreach ($message['tool_calls'] as $call) {
            echo "{$call['name']}() ";
        }
        echo "\n";
    }
}

echo "Total rounds: {$rounds}\n";
```

### Creating a Tool Call Visualizer

Build a helper to visualize the execution flow:

```php
<?php

function visualizeToolCalls(array $messages): void
{
    $round = 0;
    foreach ($messages as $message) {
        if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
            $round++;
            echo "Round {$round}:\n";
            foreach ($message['tool_calls'] as $call) {
                $args = json_encode($call['arguments'] ?? []);
                echo "  - {$call['name']}({$args})\n";
            }
        }
    }
}

$agent = agent('visualizer')->provider(anthropic())->tool(/* ... */);
$response = $agent->prompt('Complex task');
visualizeToolCalls($agent->messages);
```

## Common Patterns and Best Practices

### Pattern 1: Progressive Refinement

Design tools that build on each other:

```php
$agent->tool('search', 'Search broadly', fn($query) => /* ... */);
$agent->tool('filter', 'Filter results', fn($criteria) => /* ... */);
$agent->tool('get_details', 'Get detailed info', fn($id) => /* ... */);
```

### Pattern 2: Dependency Handling

Make tools fail gracefully when prerequisites aren't met:

```php
$agent->tool(Tool::fromClosure(
    'analyze_data',
    'Analyze data (requires data to be loaded first)',
    function(string $source) use (&$dataStore): string {
        if (!isset($dataStore[$source])) {
            return json_encode([
                'error' => 'Data not loaded',
                'hint' => 'Use fetch_data tool first',
            ]);
        }
        return json_encode(/* analysis */);
    }
));
```

### Best Practices

**Design Tools for Composition**

Create focused tools that work well together:

```php
// Good - single-purpose tools
$agent->tool('fetch', 'Fetch data', fn($source) => /* ... */);
$agent->tool('transform', 'Transform data', fn($data) => /* ... */);

// Avoid - monolithic tools
$agent->tool('do_everything', 'Fetch and transform', fn($source) => /* ... */);
```

**Provide Clear Tool Descriptions**

Help the LLM understand tool order and dependencies:

```php
$agent->tool('search', 'Search for items. Use this FIRST to find IDs.', fn($q) => /* ... */);
$agent->tool('get_details', 'Get details (needs ID from search)', fn($id) => /* ... */);
```

**Monitor Depth Usage**

Break complex tasks into phases if hitting the depth limit:

```php
// Instead of hitting depth limit
$response = $agent->prompt('Do steps A through J');

// Break into phases
$phase1 = $agent->prompt('Do steps A-C');
$phase2 = $agent->prompt('Do steps D-F based on previous results');
$phase3 = $agent->prompt('Complete with steps G-J');
```

## Understanding the Depth Limit

The `MAX_TOOL_CALL_DEPTH = 10` limit means up to 10 rounds of tool calls. Each round can include multiple tools:

```
Round 1: tool_a, tool_b, tool_c
Round 2: tool_d
Round 3: tool_e, tool_f
...
Round 10: final tool
```

Exceeding this throws a `RuntimeException` to prevent infinite loops and runaway costs.

## Performance Considerations

Each tool call round adds latency and token costs. Monitor usage:

```php
$response = $agent->prompt('Complex task');
echo "Total tokens: {$response->usage['total_tokens']}\n";

// Use streaming for better UX with long workflows
$agent->streamTo('Multi-step task', fn($chunk) => echo $chunk);
```

## Testing Recursive Tool Execution

Test that your tools work correctly in recursive scenarios:

```php
<?php

test('agent handles multi-step workflow', function () {
    $callLog = [];

    $agent = agent('test')
        ->provider(mock())
        ->tool('search', 'Search', function($q) use (&$callLog) {
            $callLog[] = "search:{$q}";
            return json_encode(['results' => ['item1']]);
        })
        ->tool('fetch', 'Fetch details', function($item) use (&$callLog) {
            $callLog[] = "fetch:{$item}";
            return json_encode(['data' => "Details of {$item}"]);
        });

    $response = $agent->prompt('Search and fetch item1');

    expect($callLog)->toContain('search:test');
    expect($callLog)->toContain('fetch:item1');
});

test('agent stops at depth limit', function () {
    $agent = agent('infinite')
        ->provider(mock('Loop!', tool_calls: [['name' => 'loop', 'arguments' => []]]))
        ->tool('loop', 'Loops forever', fn() => 'Continue');

    expect(fn() => $agent->prompt('Start loop'))
        ->toThrow(RuntimeException::class, 'Maximum tool call depth exceeded');
});
```

## Summary

You've learned how Pagent's automatic recursive tool execution works:

- Pagent automatically handles tool calls in a loop until the LLM provides a final answer
- The `MAX_TOOL_CALL_DEPTH = 10` constant protects against infinite loops
- Multi-step workflows happen naturally through LLM reasoning
- Tool results are automatically added to conversation history
- You can debug recursive chains by inspecting `$agent->messages`
- Design composable tools that work well in sequences
- Monitor depth usage and token consumption for complex workflows

The key insight: You don't need to orchestrate the tool calling sequence. The LLM decides what tools to call and when, based on the results of previous tool calls. Your job is to provide well-designed, composable tools and clear descriptions.

## Next Steps

In Chapter 9, we'll explore tool orchestration patterns, learning how to design tools that work together effectively and how to guide the LLM toward optimal tool-calling strategies for complex workflows.

## Additional Resources

- [Anthropic Tool Use Guide](https://docs.anthropic.com/en/docs/build-with-claude/tool-use)
- [OpenAI Function Calling](https://platform.openai.com/docs/guides/function-calling)
- [Pagent Source Code - Agent.php](https://github.com/hhelge/pagent/blob/main/src/Agent.php)
