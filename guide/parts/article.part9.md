# Chapter 9: Tool Orchestration Patterns

**Learning Objectives:**

- Understand how Pagent executes tools sequentially
- Master manual vs. LLM-driven orchestration strategies
- Implement data pipeline and workflow patterns
- Handle tool dependencies and conditional flows
- Optimize multi-tool execution for real-world applications

**Prerequisites:** Chapters 6-8 (Tool fundamentals, advanced patterns, error handling)

---

## Introduction

Tool orchestration is the art of coordinating multiple tool executions to achieve complex workflows. While individual tools are powerful, the real magic happens when you combine them into pipelines, decision trees, and adaptive workflows.

In this chapter, we'll explore Pagent's tool execution model and learn practical patterns for orchestrating tools—whether you let the LLM decide the flow or take manual control yourself.

## Understanding Tool Execution Flow

### The Automatic Loop

When you register tools with an agent and make a prompt, Pagent enters an automatic tool-calling loop:

```php
$agent = agent('data-processor')
    ->provider(anthropic())
    ->tool('fetch_data', 'Fetch data from URL',
        fn (string $url) => file_get_contents($url))
    ->tool('parse_json', 'Parse JSON string',
        fn (string $json) => json_decode($json, true))
    ->tool('summarize', 'Summarize data array',
        fn (array $data) => count($data) . ' items found');

$response = $agent->prompt('Fetch and summarize https://api.example.com/data');
```

**What happens behind the scenes:**

1. **Initial API call:** Agent sends user message + tool schemas to LLM
2. **Tool call detection:** LLM responds with `tool_calls` array
3. **Sequential execution:** Pagent executes each tool in order
4. **Message history update:** Tool results added to conversation
5. **Loop continuation:** Agent calls LLM again with results
6. **Final response:** LLM synthesizes final answer (no more tool calls)

This automatic loop continues up to `MAX_TOOL_CALL_DEPTH` (10 by default) to prevent infinite loops.

### Sequential Execution Model

Pagent executes tools **sequentially**, not in parallel. Here's the actual implementation from `Agent.php`:

```php
// src/Agent.php:904-932
foreach ($response->tool_calls as $toolCall) {
    $arguments = $this->normalizeToolCallArguments($toolCall);
    $result = $this->executeToolWithSpan($toolCall['name'], $arguments);

    // Add tool result to messages
    $this->messages[] = [
        'role' => 'tool',
        'tool_call_id' => $toolCall['id'],
        'content' => is_string($result) ? $result : json_encode($result),
    ];
}
```

**Why sequential?** Sequential execution provides:

- **Predictable ordering:** Tools run in the exact order the LLM specifies
- **Dependency handling:** Later tools can depend on earlier results
- **Simpler debugging:** Clear execution trace in conversation history
- **Error isolation:** A failing tool doesn't affect completed tools

### Message History Integration

Every tool execution leaves a trace in `$agent->messages`:

```php
// After tool execution, your message history looks like:
[
    ['role' => 'user', 'content' => 'Fetch and process data'],
    ['role' => 'assistant', 'tool_calls' => [/* tool call details */]],
    ['role' => 'tool', 'tool_call_id' => 'call_123', 'content' => '{"data": [...]}'],
    ['role' => 'assistant', 'content' => 'I found 42 items in the data.'],
]
```

This full history allows:

- **LLM context:** The model sees all tool results for final synthesis
- **Debugging:** Inspect exactly what tools returned
- **Auditing:** Track the entire decision chain
- **Retry logic:** Replay conversations with modified tools

## LLM-Driven Orchestration

Let the LLM decide which tools to use and in what order. This is Pagent's default mode and works best for adaptive, decision-based workflows.

### Pattern 1: Multi-Step Data Pipeline

```php
$agent = agent('etl-pipeline')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->system('You are a data processing assistant. Follow these steps:
        1. Fetch data from the source
        2. Validate the data structure
        3. Transform the data as needed
        4. Save the results')
    ->tool('fetch_url', 'Fetch content from URL',
        fn (string $url) => Http::get($url)->body())
    ->tool('validate_json', 'Validate JSON structure',
        function (string $json, string $schema) {
            $data = json_decode($json, true);
            // Validation logic here
            return $data !== null ? 'valid' : 'invalid';
        })
    ->tool('transform_data', 'Transform data array',
        function (array $data, string $transformation) {
            return match($transformation) {
                'uppercase_keys' => array_change_key_case($data, CASE_UPPER),
                'extract_ids' => array_column($data, 'id'),
                default => $data,
            };
        })
    ->tool('save_to_file', 'Save data to file',
        function (string $path, array $data) {
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
            return "Saved to {$path}";
        });

// LLM orchestrates the entire pipeline
$result = $agent->prompt('
    Process the data from https://api.example.com/users:
    1. Fetch the data
    2. Validate it matches the user schema
    3. Transform to uppercase keys
    4. Save to /tmp/users.json
');
```

**When LLM orchestration shines:**

- The workflow involves decision-making
- Tool selection depends on data content
- You want natural language workflow definitions
- The process may need different paths based on results

### Pattern 2: Conditional Workflows

```php
$agent = agent('support-bot')
    ->provider(openai())
    ->system('You are a customer support assistant.
        Use tools to check order status and process refunds when appropriate.')
    ->tool('check_order_status', 'Check order status by ID',
        fn (string $orderId) => Database::getOrderStatus($orderId))
    ->tool('initiate_refund', 'Start refund process',
        fn (string $orderId, string $reason) =>
            RefundService::create($orderId, $reason))
    ->tool('send_email', 'Send email to customer',
        fn (string $email, string $subject, string $body) =>
            MailService::send($email, $subject, $body));

// LLM decides which tools to use based on conversation
$response = $agent->prompt("
    Customer says: My order #12345 arrived damaged.
    I need a refund.
");

// LLM might:
// 1. Call check_order_status('12345')
// 2. Call initiate_refund('12345', 'damaged product')
// 3. Call send_email(...) to confirm refund
```

The LLM naturally handles:

- **Conditional logic:** Only refund if order exists and is eligible
- **Multi-step workflows:** Check → Refund → Notify
- **Context awareness:** Use customer info from conversation history
- **Error recovery:** Adapt if tools return unexpected results

### Pattern 3: Information Aggregation

Perfect for gathering data from multiple sources:

```php
$agent = agent('research-assistant')
    ->provider(anthropic())
    ->tool('search_docs', 'Search internal documentation',
        fn (string $query) => DocSearch::search($query))
    ->tool('query_database', 'Query user database',
        fn (string $sql) => DB::query($sql)->get())
    ->tool('fetch_api', 'Fetch external API data',
        fn (string $endpoint) => Http::get($endpoint)->json())
    ->tool('read_file', 'Read local file',
        fn (string $path) => file_get_contents($path));

$summary = $agent->prompt('
    Research our user "john@example.com":
    - Find their documentation access logs
    - Check their database record
    - Fetch their GitHub activity
    - Read any local notes about them
    Synthesize a complete profile.
');
```

The LLM orchestrates parallel-concept gathering (executed sequentially but conceptually independent) and synthesizes results into coherent output.

## Manual Orchestration

Sometimes you need explicit control over tool execution order. Pagent provides `executeTool()` for manual orchestration.

### Direct Tool Execution

```php
$agent = agent('calculator')
    ->provider(mock())
    ->tool('add', 'Add two numbers',
        fn (int $a, int $b) => $a + $b)
    ->tool('multiply', 'Multiply two numbers',
        fn (int $a, int $b) => $a * $b);

// Manual orchestration
$sum = $agent->executeTool('add', [5, 3]);        // 8
$product = $agent->executeTool('multiply', [$sum, 2]); // 16

echo "Result: {$product}"; // Result: 16
```

**Use cases for manual execution:**

- **Deterministic workflows:** Fixed sequence every time
- **Performance optimization:** Skip LLM overhead for simple chains
- **Testing:** Validate tool behavior in isolation
- **Debugging:** Step through tool execution manually

### Pattern 4: Hybrid Orchestration

Combine manual and LLM-driven approaches:

```php
$agent = agent('report-generator')
    ->provider(anthropic())
    ->tool('fetch_metrics', 'Get metrics for date range',
        fn (string $start, string $end) =>
            Metrics::between($start, $end))
    ->tool('calculate_growth', 'Calculate growth percentage',
        fn (float $current, float $previous) =>
            (($current - $previous) / $previous) * 100)
    ->tool('format_currency', 'Format number as currency',
        fn (float $amount) => '$' . number_format($amount, 2));

// Manual: Fetch raw data
$currentMetrics = $agent->executeTool('fetch_metrics', [
    '2024-01-01',
    '2024-01-31'
]);
$previousMetrics = $agent->executeTool('fetch_metrics', [
    '2023-12-01',
    '2023-12-31'
]);

// Manual: Calculate key stat
$growth = $agent->executeTool('calculate_growth', [
    $currentMetrics['revenue'],
    $previousMetrics['revenue']
]);

// LLM: Generate narrative report
$report = $agent->prompt("
    Generate an executive summary for January 2024 metrics.
    Revenue: {$currentMetrics['revenue']}
    Growth: {$growth}%
    Users: {$currentMetrics['users']}
");
```

**Why hybrid?** You get:

- Predictable data gathering (manual)
- Creative presentation (LLM)
- Cost efficiency (fewer LLM calls)
- Debugging simplicity (known data state before LLM)

### Pattern 5: Error-Resilient Pipelines

Manual orchestration enables sophisticated error handling:

```php
$agent = agent('etl-processor')
    ->provider(anthropic())
    ->tool('extract', 'Extract data from source',
        fn (string $source) => DataExtractor::extract($source))
    ->tool('validate', 'Validate data structure',
        fn (array $data) => Validator::validate($data))
    ->tool('transform', 'Transform data',
        fn (array $data) => Transformer::process($data))
    ->tool('load', 'Load data to destination',
        fn (array $data, string $dest) =>
            DataLoader::load($data, $dest));

try {
    // Manual ETL with error handling at each stage
    $extracted = $agent->executeTool('extract', ['api']);

    $validation = $agent->executeTool('validate', [$extracted]);
    if ($validation['errors'] > 0) {
        // Ask LLM to fix validation errors
        $fixed = $agent->prompt("
            Fix these validation errors:
            " . json_encode($validation['errors'])
        );
        $extracted = json_decode($fixed, true);
    }

    $transformed = $agent->executeTool('transform', [$extracted]);

    $loaded = $agent->executeTool('load', [
        $transformed,
        'database'
    ]);

    echo "Pipeline complete: {$loaded['rows']} rows loaded";

} catch (RuntimeException $e) {
    // Handle tool execution failures
    Log::error("ETL pipeline failed: {$e->getMessage()}");
}
```

## Performance Considerations

### Tool Call Depth Limits

Pagent enforces a maximum depth to prevent infinite loops:

```php
// src/Agent.php:58
private const MAX_TOOL_CALL_DEPTH = 10;

// During execution:
while (!empty($response->tool_calls)) {
    $toolCallDepth++;

    if ($toolCallDepth > self::MAX_TOOL_CALL_DEPTH) {
        throw new RuntimeException(
            'Maximum tool call depth exceeded (10 calls).
             Possible infinite loop detected.'
        );
    }

    $response = $this->handleToolCalls($response);
}
```

**Implications:**

- Deep workflows may need manual orchestration
- Consider batching related operations in single tools
- Design tools to return complete results, not partial

### Optimizing LLM Calls

Each tool execution loop requires an LLM API call. Minimize calls by:

**1. Batching-friendly tool descriptions:**

```php
// Forces multiple LLM calls
$agent->tool('get_user', 'Get user by ID', ...);
$agent->tool('get_user_orders', 'Get orders for user', ...);
$agent->tool('get_user_preferences', 'Get user preferences', ...);

// Single tool, single call
$agent->tool('get_user_profile',
    'Get complete user profile including orders and preferences',
    fn (string $userId) => [
        'user' => User::find($userId),
        'orders' => Orders::forUser($userId),
        'preferences' => Preferences::forUser($userId),
    ]
);
```

**2. Manual execution for known sequences:**

```php
// LLM overhead for simple sequence
$agent->prompt('Add 5+3, then multiply by 2');

// Manual execution
$sum = $agent->executeTool('add', [5, 3]);
$result = $agent->executeTool('multiply', [$sum, 2]);
```

**3. Tool result caching:**

Tools can implement their own caching:

```php
$agent->tool('expensive_computation',
    'Run expensive computation on data',
    function (array $data) {
        static $cache = [];
        $key = md5(json_encode($data));

        if (!isset($cache[$key])) {
            $cache[$key] = heavyComputation($data);
        }

        return $cache[$key];
    }
);
```

## Real-World Examples

### Example 1: Multi-Source Data Aggregator

```php
$agent = agent('market-research')
    ->provider(anthropic())
    ->model('claude-sonnet-4-6')
    ->system('You are a market research analyst.
        Gather data from multiple sources and provide insights.')
    ->tool('scrape_website', 'Scrape competitor website',
        fn (string $url) => WebScraper::scrape($url))
    ->tool('query_crunchbase', 'Get company data from Crunchbase',
        fn (string $company) => CrunchbaseAPI::getCompany($company))
    ->tool('analyze_sentiment', 'Analyze text sentiment',
        fn (string $text) => SentimentAnalyzer::analyze($text))
    ->tool('generate_chart', 'Generate comparison chart',
        fn (array $data, string $type) => ChartGenerator::make($data, $type));

$research = $agent->prompt('
    Research competitor "Acme Corp":
    1. Scrape their homepage for product info
    2. Get their Crunchbase funding data
    3. Analyze sentiment of their recent blog posts
    4. Generate a competitive comparison chart
    Provide a 3-paragraph summary with key insights.
');

// LLM orchestrates all tools and synthesizes report
echo $research->content;
```

### Example 2: Conditional Approval Workflow

```php
$agent = agent('expense-approver')
    ->provider(openai())
    ->system('You are an expense approval assistant.
        Auto-approve under $100, flag $100-$500 for review,
        require manager approval over $500.')
    ->tool('check_budget', 'Check department budget remaining',
        fn (string $dept) => Budget::remaining($dept))
    ->tool('verify_receipt', 'Verify receipt authenticity',
        fn (string $receiptUrl) => ReceiptVerifier::check($receiptUrl))
    ->tool('auto_approve', 'Auto-approve expense',
        fn (string $expenseId) => Expenses::approve($expenseId, 'auto'))
    ->tool('flag_for_review', 'Flag expense for review',
        fn (string $expenseId, string $reason) =>
            Expenses::flag($expenseId, $reason))
    ->tool('request_manager_approval', 'Request manager approval',
        fn (string $expenseId, string $managerId) =>
            Approvals::request($expenseId, $managerId));

$result = $agent->prompt('
    Process expense #EXP-1234:
    Amount: $350
    Department: Engineering
    Receipt: https://example.com/receipt.pdf
    Description: Team lunch
');

// LLM decides workflow based on amount and validation
```

### Example 3: Data Pipeline with Validation

```php
$agent = agent('data-importer')
    ->provider(anthropic())
    ->system('You are a data import specialist.
        Validate data thoroughly before importing.')
    ->tool('download_csv', 'Download CSV file',
        fn (string $url) => CsvDownloader::fetch($url))
    ->tool('validate_headers', 'Validate CSV headers',
        fn (string $csv, array $expectedHeaders) =>
            CsvValidator::checkHeaders($csv, $expectedHeaders))
    ->tool('validate_rows', 'Validate row data',
        fn (string $csv, array $rules) =>
            CsvValidator::checkRows($csv, $rules))
    ->tool('import_to_db', 'Import validated CSV to database',
        fn (string $csv, string $table) =>
            DbImporter::import($csv, $table));

// Hybrid approach: Manual download, LLM-driven validation
$csv = $agent->executeTool('download_csv', [
    'https://example.com/users.csv'
]);

$result = $agent->prompt("
    Import this CSV: {$csv}
    Expected headers: name, email, age
    Rules: email must be valid, age must be 18+
    If validation passes, import to 'users' table.
    If validation fails, tell me what's wrong.
");
```

## Best Practices

### 1. Clear Tool Descriptions

The LLM relies entirely on tool descriptions for orchestration:

```php
// Vague description
$agent->tool('process', 'Process data', ...);

// Specific description
$agent->tool('process_user_data',
    'Process user data: validates email, normalizes name,
     generates username. Returns processed user object.',
    ...);
```

### 2. Design for Idempotency

Tools should be safe to call multiple times:

```php
// Not idempotent
$agent->tool('increment_counter',
    'Increment counter',
    function () {
        static $count = 0;
        return ++$count;
    });

// Idempotent
$agent->tool('get_counter',
    'Get current counter value',
    fn () => Cache::get('counter', 0));

$agent->tool('set_counter',
    'Set counter to specific value',
    fn (int $value) => Cache::put('counter', $value));
```

### 3. Return Structured Data

Help the LLM understand tool results:

```php
// Opaque return
$agent->tool('check_inventory', 'Check inventory',
    fn (string $sku) => Inventory::check($sku));

// Structured return
$agent->tool('check_inventory', 'Check inventory status',
    fn (string $sku) => [
        'sku' => $sku,
        'in_stock' => Inventory::inStock($sku),
        'quantity' => Inventory::quantity($sku),
        'location' => Inventory::location($sku),
        'next_restock' => Inventory::nextRestock($sku),
    ]);
```

### 4. Use System Prompts for Orchestration Hints

Guide the LLM's orchestration logic:

```php
$agent->system('
    You are a helpful assistant with access to tools.

    ORCHESTRATION RULES:
    - Always validate data before transforming it
    - Check user permissions before taking actions
    - Fetch all required data before generating reports
    - If any step fails, explain why and stop
');
```

### 5. Monitor Tool Execution

Track tool usage for optimization:

```php
$agent->prompt($message);

// Inspect tool usage
foreach ($agent->messages as $msg) {
    if ($msg['role'] === 'tool') {
        echo "Tool called: {$msg['tool_call_id']}\n";
        echo "Result: {$msg['content']}\n";
    }
}

// Or use telemetry (Chapter 26)
$agent->telemetry(true);
```

## Common Patterns Summary

| Pattern                      | When to Use                        | Orchestration Type |
| ---------------------------- | ---------------------------------- | ------------------ |
| **Multi-Step Pipeline**      | Sequential data processing         | LLM-driven         |
| **Conditional Workflow**     | Decision-based tool selection      | LLM-driven         |
| **Information Aggregation**  | Gather from multiple sources       | LLM-driven         |
| **Hybrid Orchestration**     | Mix manual + LLM creativity        | Manual + LLM       |
| **Error-Resilient Pipeline** | Critical workflows with validation | Manual             |

## What We Learned

In this chapter, you learned:

- Pagent executes tools **sequentially** in a loop until the LLM returns final content
- **LLM-driven orchestration** excels at adaptive, decision-based workflows
- **Manual orchestration** provides control for deterministic sequences
- **Hybrid approaches** combine the best of both worlds
- Tool descriptions guide the LLM's orchestration decisions
- The `MAX_TOOL_CALL_DEPTH` limit prevents infinite loops
- Message history tracks every tool call for debugging and context

## Next Steps

Now that you understand tool orchestration patterns, you're ready for:

- **Chapter 10:** Streaming fundamentals for real-time tool execution feedback
- **Chapter 11:** Advanced streaming patterns with tool calls
- **Part 6 (Chapters 18-20):** Multi-agent orchestration with pipelines, handoffs, and delegation

Tool orchestration is where Pagent transforms from a simple chat interface into a powerful workflow engine. Master these patterns and you'll build sophisticated AI applications that blend LLM intelligence with deterministic reliability.
