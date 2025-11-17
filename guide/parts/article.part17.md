# Chapter 17: Pipeline Pattern

In previous chapters, we've explored how individual agents handle tasks, how streaming enables real-time interactions, and how guards protect against unwanted outputs. But what happens when you need to process information through multiple stages, where each agent specializes in one aspect of the task? What if you want to extract data, transform it, validate it, and format it - each step performed by a different expert agent?

This is where the Pipeline pattern comes in. Pagent provides first-class support for sequential agent execution through its `Pipeline` class, letting you chain agents together where each stage's output becomes the next stage's input. In this chapter, we'll explore how to build pipelines, transform data between stages, handle errors gracefully, and inspect results from each step.

## Why Pipelines Matter

Before diving into the API, let's understand why pipelines are valuable:

**Separation of Concerns**: Instead of one complex agent trying to do everything, you can create specialized agents that each excel at their specific task. An extraction agent pulls out key information, a transformation agent reformats it, and a validation agent ensures quality.

**Composability**: Pipelines let you build complex workflows from simple, reusable components. Once you have a good extraction agent, you can use it as the first stage in multiple different pipelines.

**Debugging and Iteration**: When something goes wrong, you can inspect the output of each stage to pinpoint exactly where the pipeline failed. You can also run individual stages in isolation to test them.

**Flexible Data Flow**: Transform functions between stages let you adapt data formats as it flows through the pipeline, ensuring each agent receives input in its preferred format.

## The Basic Pipeline API

Pagent provides a `pipeline()` helper function that creates a `Pipeline` instance. Let's start with a simple example:

```php
use function Pagent\pipeline;
use function Pagent\agent;

// Create specialized agents
agent('extractor')
    ->provider('anthropic')
    ->system('Extract key facts from the input text. Return only the facts as a numbered list.')
    ->build();

agent('summarizer')
    ->provider('anthropic')
    ->system('Summarize the input into a single concise paragraph.')
    ->build();

agent('formatter')
    ->provider('anthropic')
    ->system('Format the input as professional markdown.')
    ->build();

// Build and run the pipeline
$result = pipeline('document-processor')
    ->agent('extractor')
    ->agent('summarizer')
    ->agent('formatter')
    ->run('Long article text here...');

echo $result; // Formatted markdown summary
```

This example demonstrates the core pattern: create a pipeline with a descriptive name, add agents in sequence using the `agent()` method, then execute the pipeline with `run()`. Each agent processes the output from the previous stage.

The `run()` method:
- Accepts the initial input (string or any data type)
- Passes it to the first agent
- Takes that agent's response and passes it to the next agent
- Continues through all stages sequentially
- Returns the final output as a string

This makes pipelines intuitive - data flows left to right through your agent chain.

## Agent Resolution and Naming

The `agent()` method accepts either a registered agent name (string) or an `Agent` instance:

```php
// Using registered agent names
$pipeline = pipeline('name-based')
    ->agent('extractor')
    ->agent('formatter')
    ->run($input);

// Using Agent instances directly
$extractor = agent('extractor')
    ->provider('anthropic')
    ->build();

$formatter = agent('formatter')
    ->provider('openai')
    ->build();

$pipeline = pipeline('instance-based')
    ->agent($extractor)
    ->agent($formatter)
    ->run($input);

// Mixing both approaches
$pipeline = pipeline('mixed')
    ->agent('extractor')        // Registered agent
    ->agent($formatter)          // Agent instance
    ->agent('final-processor')   // Registered agent
    ->run($input);
```

When you provide a string name, Pagent looks it up in the global Registry. If the agent doesn't exist, you'll get a clear error when the pipeline runs. This flexibility lets you build pipelines with pre-registered agents or construct them dynamically.

## Transform Functions Between Stages

Not every agent expects input in the same format. Transform functions let you adapt data as it flows between stages:

```php
agent('data-extractor')
    ->provider('anthropic')
    ->system('Extract structured data and return as JSON')
    ->build();

agent('report-generator')
    ->provider('anthropic')
    ->system('Generate a professional report from the provided information')
    ->build();

$result = pipeline('data-pipeline')
    ->agent('data-extractor')
    ->agent('report-generator', function ($previousOutput) {
        // Transform JSON to a formatted prompt
        $data = json_decode($previousOutput, true);
        return "Generate a report with this data:\n\n"
            . "Name: {$data['name']}\n"
            . "Count: {$data['count']}\n"
            . "Status: {$data['status']}";
    })
    ->run('Extract name, count, and status from this text: ...');

echo $result; // Professional report based on extracted data
```

The transform function receives the previous stage's output and returns the input for the current stage. This is powerful because:

**Format Conversion**: Convert between JSON, XML, plain text, or custom formats
**Prompt Engineering**: Wrap data in instructions specific to each agent's needs
**Data Filtering**: Extract only relevant portions before passing to the next stage
**Preprocessing**: Clean, normalize, or enrich data between stages

Without a transform function, Pagent automatically converts the previous output to a string (using `json_encode()` for non-string values) and passes it directly to the next agent.

## Inspecting Pipeline Results

After a pipeline runs, you can inspect the results from each stage using `getResults()`:

```php
$pipe = pipeline('inspectable')
    ->agent('stage1')
    ->agent('stage2')
    ->agent('stage3');

$finalOutput = $pipe->run('Initial input');

// Inspect each stage
$results = $pipe->getResults();

foreach ($results as $result) {
    echo "Stage {$result['stage']}: {$result['agent']}\n";
    echo "Input: {$result['input']}\n";
    echo "Output: {$result['output']}\n";
    echo "---\n";
}
```

Each result in the array contains:
- `stage`: Numeric index (0, 1, 2, ...)
- `agent`: Agent name (string)
- `input`: What was sent to this agent
- `output`: What this agent returned (text content)
- `response`: Full `Response` object with metadata

This is invaluable for debugging. If your pipeline produces unexpected output, you can examine exactly what each stage received and produced:

```php
$pipe = pipeline('debuggable')
    ->agent('parser')
    ->agent('processor', fn($out) => "Process: $out")
    ->agent('finalizer');

$result = $pipe->run('Input data');

// Debug output
$results = $pipe->getResults();

// Check where things went wrong
if ($results[1]['output'] !== 'Expected Value') {
    echo "Stage 1 (processor) produced unexpected output:\n";
    echo $results[1]['output'];
}
```

The full `Response` object provides access to token usage, model information, stop reason, and any other metadata from the provider:

```php
$results = $pipe->getResults();

foreach ($results as $result) {
    $response = $result['response'];
    $usage = $response->usage;

    echo "{$result['agent']} used {$usage['total_tokens']} tokens\n";
}
```

## Error Handling in Pipelines

When something goes wrong during pipeline execution, you need to decide whether to fail fast or handle errors gracefully. By default, pipelines throw exceptions on errors:

```php
try {
    $result = pipeline('may-fail')
        ->agent('valid-agent')
        ->agent('nonexistent-agent')  // This will fail
        ->agent('another-agent')
        ->run('Input');
} catch (RuntimeException $e) {
    // Pipeline 'may-fail' failed at stage 1 (agent: nonexistent-agent): Agent 'nonexistent-agent' not found
    echo "Pipeline failed: " . $e->getMessage();
}
```

The exception message includes the pipeline name, stage index, and agent name, making it easy to diagnose failures.

For more controlled error handling, use the `onError()` method to provide a custom error handler:

```php
$result = pipeline('resilient')
    ->agent('stage1')
    ->agent('stage2')
    ->agent('stage3')
    ->onError(function ($exception, $stageIndex, $agentName) {
        // Log the error
        error_log("Pipeline failed at stage {$stageIndex} ({$agentName}): {$exception->getMessage()}");

        // Return fallback content
        return "Pipeline encountered an error at stage {$stageIndex}. Using fallback output.";
    })
    ->run('Input data');

// $result will contain the error handler's return value if any stage fails
echo $result;
```

The error handler receives three parameters:
- `$exception`: The caught exception
- `$stageIndex`: Which stage failed (0, 1, 2, ...)
- `$agentName`: Name of the agent that failed

Your error handler can:
- Return a fallback value (becomes the final pipeline output)
- Log the error and re-throw it
- Implement retry logic
- Trigger alerts or notifications

Here's a more sophisticated error handler with retry logic:

```php
$result = pipeline('retry-pipeline')
    ->agent('flaky-service')
    ->agent('processor')
    ->onError(function ($exception, $stageIndex, $agentName) use (&$retryCount) {
        if ($retryCount < 3 && str_contains($exception->getMessage(), 'timeout')) {
            $retryCount++;
            error_log("Retry attempt {$retryCount} for {$agentName}");

            // In practice, you'd need to re-run the failed stage
            // This is a simplified example
            throw $exception; // Re-throw to retry
        }

        return "Failed after {$retryCount} retries: " . $exception->getMessage();
    })
    ->run('Input');
```

## Building Real-World Pipelines

Let's build some practical pipelines. Here's a content moderation pipeline:

```php
// Define specialized agents
agent('content-analyzer')
    ->provider('anthropic')
    ->system('Analyze the content for: tone, topic, potential issues. Return as JSON.')
    ->build();

agent('safety-checker')
    ->provider('anthropic')
    ->system('Check if content is safe and appropriate. Return "SAFE" or "UNSAFE: reason".')
    ->build();

agent('content-improver')
    ->provider('anthropic')
    ->system('If content has issues, suggest improvements. Otherwise, return the content unchanged.')
    ->build();

// Build moderation pipeline
function moderateContent(string $content): array
{
    $pipe = pipeline('content-moderation')
        ->agent('content-analyzer')
        ->agent('safety-checker', function ($analysis) {
            // Transform JSON analysis to safety check prompt
            $data = json_decode($analysis, true);
            return "Check this content for safety:\n\n"
                . "Tone: {$data['tone']}\n"
                . "Topic: {$data['topic']}\n"
                . "Issues: {$data['issues']}";
        })
        ->agent('content-improver', function ($safetyResult) use ($content) {
            // Pass both safety result and original content
            return "Safety check result: {$safetyResult}\n\n"
                . "Original content: {$content}\n\n"
                . "Provide improved version if needed.";
        })
        ->onError(function ($e, $stage, $agent) {
            error_log("Moderation pipeline failed at {$agent}: {$e->getMessage()}");
            return "MODERATION_ERROR";
        });

    $result = $pipe->run($content);
    $stages = $pipe->getResults();

    return [
        'final_content' => $result,
        'analysis' => $stages[0]['output'],
        'safety_check' => $stages[1]['output'],
        'total_tokens' => array_sum(array_map(
            fn($s) => $s['response']->usage['total_tokens'] ?? 0,
            $stages
        ))
    ];
}

// Use the pipeline
$moderated = moderateContent('User-submitted content here...');
echo $moderated['final_content'];
echo "\nTotal tokens used: {$moderated['total_tokens']}";
```

This demonstrates several advanced patterns:
- Transform functions that access external data (the original `$content` via closure)
- Result inspection to build a comprehensive response
- Token usage aggregation across all stages
- Error handling with logging

Here's another example - a data processing pipeline:

```php
agent('sql-generator')
    ->provider('anthropic')
    ->system('Generate SQL query based on natural language request. Return only the SQL.')
    ->build();

agent('sql-validator')
    ->provider('anthropic')
    ->system('Validate SQL query for safety. Check for: injections, destructive operations, syntax errors. Return "VALID" or "INVALID: reason".')
    ->build();

agent('query-optimizer')
    ->provider('anthropic')
    ->system('Optimize the SQL query for performance. Return the optimized SQL.')
    ->build();

function processQuery(string $naturalLanguageQuery): string
{
    $pipe = pipeline('sql-pipeline')
        ->agent('sql-generator')
        ->agent('sql-validator', function ($sql) {
            return "Validate this SQL:\n\n{$sql}";
        })
        ->agent('query-optimizer', function ($validationResult) use (&$sql) {
            // Store validation result, pass original SQL to optimizer
            if (!str_starts_with($validationResult, 'VALID')) {
                throw new RuntimeException("Invalid SQL: {$validationResult}");
            }

            // Get SQL from previous stages
            return $sql;
        })
        ->onError(function ($e, $stage, $agent) {
            if ($stage === 1 && str_contains($e->getMessage(), 'Invalid SQL')) {
                return "-- Query validation failed\n-- " . $e->getMessage();
            }
            throw $e; // Re-throw other errors
        });

    // Store SQL for transform function
    $results = $pipe->run($naturalLanguageQuery);
    $allResults = $pipe->getResults();
    $sql = $allResults[0]['output'];

    return $results;
}
```

## Pipeline Performance Considerations

Pipelines execute stages sequentially, making multiple LLM API calls. Consider these performance implications:

**Latency**: A 3-stage pipeline makes 3 API calls. If each takes 2 seconds, total latency is 6+ seconds. For time-sensitive applications, minimize stages or use faster models for intermediate steps.

**Token Costs**: Each stage consumes tokens. A document that grows from 100 to 500 to 1000 tokens across stages means later agents process larger inputs. Monitor token usage with `getResults()` to optimize costs.

**Model Selection**: You don't need to use the same model for every stage. Use powerful models for complex reasoning, faster/cheaper models for simple transformations:

```php
agent('complex-analyzer')
    ->provider(anthropic())
    ->model('claude-sonnet-4-20250514')
    ->build();

agent('simple-formatter')
    ->provider(anthropic())
    ->model('claude-3-5-haiku-20241022')  // Faster, cheaper model
    ->build();

$result = pipeline('optimized')
    ->agent('complex-analyzer')    // Expensive analysis
    ->agent('simple-formatter')    // Cheap formatting
    ->run($input);
```

**Caching**: If you run the same pipeline with similar inputs frequently, consider caching intermediate results. While Pagent doesn't provide built-in pipeline caching, you can implement it:

```php
function cachedPipeline(string $input): string
{
    $cacheKey = md5($input);

    if ($cached = apcu_fetch($cacheKey)) {
        return $cached;
    }

    $result = pipeline('expensive')
        ->agent('stage1')
        ->agent('stage2')
        ->agent('stage3')
        ->run($input);

    apcu_store($cacheKey, $result, 3600);
    return $result;
}
```

## Pipeline Composition and Reusability

Build reusable pipeline functions that encapsulate common workflows:

```php
function extractTransformLoad(string $input, array $config): string
{
    return pipeline('etl')
        ->agent($config['extractor'])
        ->agent($config['transformer'], $config['transform_fn'] ?? null)
        ->agent($config['loader'])
        ->onError($config['error_handler'] ?? fn($e) => throw $e)
        ->run($input);
}

// Use the reusable function with different configurations
$result1 = extractTransformLoad($data, [
    'extractor' => 'json-extractor',
    'transformer' => 'xml-transformer',
    'loader' => 'database-loader',
    'transform_fn' => fn($json) => json_to_xml($json)
]);

$result2 = extractTransformLoad($data, [
    'extractor' => 'csv-extractor',
    'transformer' => 'json-transformer',
    'loader' => 'api-loader',
]);
```

You can also create pipeline templates:

```php
class PipelineTemplates
{
    public static function contentProcessing(): Pipeline
    {
        return pipeline('content-processing')
            ->agent('content-extractor')
            ->agent('content-enhancer')
            ->agent('content-formatter');
    }

    public static function dataValidation(): Pipeline
    {
        return pipeline('data-validation')
            ->agent('schema-validator')
            ->agent('business-rules-checker')
            ->agent('output-formatter')
            ->onError(fn($e, $stage) => "Validation failed at stage {$stage}");
    }
}

// Use templates
$result = PipelineTemplates::contentProcessing()->run($input);
$validated = PipelineTemplates::dataValidation()->run($data);
```

## Pipeline Naming and Organization

Give pipelines descriptive names that reflect their purpose. The name appears in error messages and helps with debugging:

```php
// Good names
pipeline('user-registration-flow')
pipeline('document-analysis-chain')
pipeline('data-quality-pipeline')

// Less helpful names
pipeline('pipeline1')
pipeline('test')
pipeline('p')
```

Access the pipeline name at runtime:

```php
$pipe = pipeline('my-pipeline')
    ->agent('stage1')
    ->agent('stage2');

echo $pipe->getName(); // 'my-pipeline'

// Useful for logging
$results = $pipe->run($input);
error_log("Pipeline '{$pipe->getName()}' completed with {count($pipe->getResults())} stages");
```

## Pipelines vs. Other Patterns

When should you use pipelines versus other approaches?

**Use Pipelines When**:
- You need sequential processing with clear stages
- Each stage specializes in one task
- You want to inspect intermediate results
- Data transforms naturally through steps (extract → transform → load)

**Use Single Agents When**:
- The task doesn't naturally decompose into stages
- You need tool calling (pipelines don't support automatic tool calling)
- Latency is critical (one API call vs. multiple)
- The task is simple enough for one agent

**Use Orchestration When**:
- You need parallel execution
- You need conditional branching
- You need loops or retries
- The workflow is more complex than sequential stages

Pipelines excel at linear, multi-stage processing where each step builds on the previous one.

## Testing Pipelines

Test pipelines by verifying each stage's behavior in isolation first:

```php
// Test individual agents
test('extractor agent works', function () {
    $agent = agent('extractor')
        ->provider(mock(['Mock extracted data']))
        ->build();

    $response = $agent->prompt('Test input');
    expect($response->content)->toBe('Mock extracted data');
});

// Test the pipeline
test('full pipeline works', function () {
    agent('extractor')->provider(mock(['Extracted']))->build();
    agent('formatter')->provider(mock(['Formatted']))->build();

    $result = pipeline('test-pipeline')
        ->agent('extractor')
        ->agent('formatter')
        ->run('Input');

    expect($result)->toBe('Formatted');
});

// Test error handling
test('pipeline handles errors', function () {
    $result = pipeline('error-test')
        ->agent('nonexistent')
        ->onError(fn($e) => 'Error handled')
        ->run('Input');

    expect($result)->toBe('Error handled');
});

// Test transform functions
test('transform function is applied', function () {
    agent('stage1')->provider(mock(['lowercase']))->build();
    agent('stage2')->provider(mock(['OUTPUT']))->build();

    $pipe = pipeline('transform-test')
        ->agent('stage1')
        ->agent('stage2', fn($prev) => strtoupper($prev));

    $result = $pipe->run('test');
    $results = $pipe->getResults();

    // Verify transform was applied
    expect($results[1]['input'])->toBe('LOWERCASE');
});
```

## Summary

The Pipeline pattern brings structure and composability to complex agent workflows. The key concepts:

- Use `pipeline(name)` to create pipelines with descriptive names
- Add stages with `agent(name|instance, ?transform)` for sequential processing
- Transform functions adapt data between stages
- Handle errors with `onError(callback)` or let them throw
- Inspect results with `getResults()` for debugging and metrics
- Each stage receives the previous stage's output as input
- Agents can be registered names or instances
- Pipelines execute sequentially, one stage at a time

In the next chapter, we'll explore the Workflow Orchestration system, which provides even more sophisticated control over agent execution including parallel processing, conditional branching, and complex multi-agent coordination.
