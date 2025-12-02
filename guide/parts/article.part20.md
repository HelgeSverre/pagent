# Chapter 20: Evaluation Framework

**Learning Objectives:**

- Design evaluation metrics for agent performance
- Create test datasets from multiple sources
- Implement custom scoring functions
- Run comprehensive evaluation suites
- Generate performance reports and comparisons

---

## Why Evaluate Your Agents?

Building an LLM-powered agent is only the first step. How do you know if it's actually working? More importantly, how do you measure whether changes improve performance or introduce regressions?

The evaluation framework in Pagent provides systematic, repeatable testing of agent behavior. Unlike manual testing or "vibe checks," evaluations give you quantifiable metrics that answer critical questions:

- Does my agent generate valid JSON 100% of the time?
- Are responses within the expected length range?
- Does output contain required keywords for compliance?
- How does performance compare after changing the prompt?
- Which model performs better for this specific task?

Think of evaluations as unit tests for agent behavior—automated, reproducible, and essential for production deployments.

## Understanding the Evaluation Pipeline

The evaluation framework follows a simple but powerful pattern:

```php
use function Pagent\evaluate;

$result = evaluate('my-agent')
    ->dataset('tests/data/questions.json')
    ->metric('accuracy', new SimilarityMetric())
    ->metric('length', new LengthMetric(100, 500))
    ->run();

echo "Average accuracy: " . $result->getAverageScore('accuracy');
```

The pipeline has four components:

1. **Agent** - The agent to evaluate (referenced by name)
2. **Dataset** - Test cases with inputs and expected outputs
3. **Metrics** - Scoring functions that measure quality
4. **Result** - Statistical analysis of performance

This design separates concerns cleanly. The agent doesn't need to know it's being evaluated, datasets can be reused across agents, and metrics can be mixed and matched for different validation needs.

## Creating Test Datasets

Datasets are collections of test cases—input prompts paired with optional expected outputs. Pagent supports multiple dataset formats to fit different workflows.

### From JSON Files

The most common format is JSON arrays with `input` and `expected` fields:

```json
[
  {
    "input": "What is 2 + 2?",
    "expected": "4"
  },
  {
    "input": "List three programming languages",
    "expected": "Python, JavaScript, PHP"
  }
]
```

Load it with `Dataset::fromJson()`:

```php
use Pagent\Evaluation\Dataset;

$dataset = Dataset::fromJson('tests/data/math_questions.json');
```

The evaluator also accepts file paths directly:

```php
evaluate('math-agent')
    ->dataset('tests/data/math_questions.json')
    ->run();
```

### From CSV Files

For simpler datasets or spreadsheet exports, use CSV format with headers:

```csv
input,expected
"What is the capital of France?","Paris"
"What is 10 * 5?","50"
```

Load with `Dataset::fromCsv()`:

```php
$dataset = Dataset::fromCsv('tests/data/trivia.csv');
```

Without headers, the first two columns are treated as input and expected:

```php
$dataset = Dataset::fromCsv('tests/data/no_headers.csv', hasHeader: false);
```

### From Arrays

For dynamic or generated test cases, create datasets programmatically:

```php
$testCases = [
    ['input' => 'Test 1', 'expected' => 'Response 1'],
    ['input' => 'Test 2', 'expected' => 'Response 2'],
    ['input' => 'Test 3'], // No expected output
];

$dataset = Dataset::fromArray($testCases);
```

This is particularly useful when generating test cases algorithmically:

```php
$cases = [];
for ($i = 0; $i < 100; $i++) {
    $a = rand(1, 10);
    $b = rand(1, 10);
    $cases[] = [
        'input' => "What is {$a} + {$b}?",
        'expected' => (string)($a + $b),
    ];
}

$dataset = Dataset::fromArray($cases);
```

### Adding Metadata

Datasets support arbitrary metadata for test cases, useful for filtering or analysis:

```php
$dataset = Dataset::fromArray([
    [
        'input' => 'Simple question',
        'expected' => 'Simple answer',
        'metadata' => ['difficulty' => 'easy', 'category' => 'math'],
    ],
    [
        'input' => 'Complex question',
        'expected' => 'Complex answer',
        'metadata' => ['difficulty' => 'hard', 'category' => 'logic'],
    ],
]);
```

Filter datasets dynamically:

```php
$easyTests = $dataset->filter(fn($item) =>
    ($item['metadata']['difficulty'] ?? '') === 'easy'
);
```

Transform dataset items:

```php
$uppercased = $dataset->map(fn($item) => [
    'input' => strtoupper($item['input']),
    'expected' => $item['expected'],
]);
```

## Built-in Metrics

Pagent includes nine production-ready metrics covering common validation scenarios. All metrics return scores between `0.0` (fail) and `1.0` (perfect).

### SimilarityMetric

Measures text similarity between agent output and expected result using PHP's `similar_text()` function:

```php
use Pagent\Evaluation\Metrics\SimilarityMetric;

evaluate('agent')
    ->metric('accuracy', new SimilarityMetric())
    ->run();
```

Returns `1.0` for identical strings, `0.0` for completely different strings, and fractional scores for partial matches. Perfect for testing factual accuracy when exact matching is too strict.

### LengthMetric

Validates output length falls within specified bounds:

```php
use Pagent\Evaluation\Metrics\LengthMetric;

// Minimum length only
->metric('min_length', new LengthMetric(minLength: 100))

// Range validation
->metric('length_range', new LengthMetric(minLength: 100, maxLength: 500))
```

Returns `0.0` if output is outside bounds, `1.0` if within range. Useful for ensuring responses aren't too terse or overly verbose.

### KeywordMetric

Checks for presence of required keywords (case-insensitive):

```php
use Pagent\Evaluation\Metrics\KeywordMetric;

// Any keyword present
->metric('keywords', new KeywordMetric(['important', 'required', 'critical']))

// All keywords required
->metric('all_keywords', new KeywordMetric(
    ['disclaimer', 'terms', 'conditions'],
    requireAll: true
))
```

With `requireAll: false` (default), returns the fraction of keywords found. With `requireAll: true`, returns `1.0` only if all keywords are present. Essential for compliance and safety checks.

### RegexMatchMetric

Generic pattern matching using regular expressions:

```php
use Pagent\Evaluation\Metrics\RegexMatchMetric;

// Email validation
->metric('has_email', new RegexMatchMetric(
    pattern: '/[\w\-\.]+@[\w\-\.]+\.\w{2,}/',
    name: 'email_present'
))

// UUID validation
->metric('uuid', new RegexMatchMetric(
    pattern: '/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/',
    name: 'uuid_format'
))

// Inverse matching (pattern should NOT be present)
->metric('no_profanity', new RegexMatchMetric(
    pattern: '/badword1|badword2/',
    name: 'clean_content',
    inverse: true
))
```

The `inverse` parameter flips the logic—returns `1.0` if pattern is NOT found. Use this for safety checks or content filtering.

### JsonValidMetric

Validates that output is parseable JSON:

```php
use Pagent\Evaluation\Metrics\JsonValidMetric;

->metric('json', new JsonValidMetric())
```

Returns `1.0` if output is valid JSON, `0.0` otherwise. Use this before `JsonSchemaMetric` to ensure basic validity.

### JsonSchemaMetric

Validates JSON output against a JSON Schema specification:

```php
use Pagent\Evaluation\Metrics\JsonSchemaMetric;

$schema = [
    'type' => 'object',
    'required' => ['name', 'email'],
    'properties' => [
        'name' => ['type' => 'string'],
        'email' => ['type' => 'string', 'format' => 'email'],
        'age' => ['type' => 'integer', 'minimum' => 0],
    ],
];

->metric('schema', new JsonSchemaMetric('user_profile', $schema))
```

Supports both strict mode (any error = `0.0`) and lenient mode (scored by error count):

```php
->metric('lenient_schema', new JsonSchemaMetric(
    'user_profile',
    $schema,
    strictMode: false
))
```

This is the gold standard for structured output validation. If your agent generates JSON, always validate with a schema.

### MarkdownValidMetric

Validates Markdown document structure:

```php
use Pagent\Evaluation\Metrics\MarkdownValidMetric;

// Optional checks (scored proportionally)
->metric('markdown', new MarkdownValidMetric())

// Require specific elements
->metric('docs', new MarkdownValidMetric(
    requireHeaders: true,
    requireLists: true,
    requireCodeBlocks: true
))
```

Checks for headers, lists, code blocks, and well-formed links. Perfect for documentation generation agents.

### HasCodeBlockMetric

Validates presence of code blocks in output:

```php
use Pagent\Evaluation\Metrics\HasCodeBlockMetric;

// Any code block
->metric('code', new HasCodeBlockMetric())

// Specific language
->metric('php_code', new HasCodeBlockMetric(language: 'php'))

// Multiple blocks required
->metric('examples', new HasCodeBlockMetric(minBlocks: 3))
```

Essential for code generation and technical writing agents.

### UrlValidityMetric

Validates URLs in output are well-formed:

```php
use Pagent\Evaluation\Metrics\UrlValidityMetric;

->metric('urls', new UrlValidityMetric())
```

Uses PHP's `filter_var()` with `FILTER_VALIDATE_URL` to check URL formatting.

## Custom Metrics with Callables

For one-off or simple metrics, use closures instead of creating full metric classes:

```php
evaluate('agent')
    ->metric('exact_match', function ($input, $output, $expected) {
        return $output === $expected ? 1.0 : 0.0;
    })
    ->metric('word_count', function ($input, $output, $expected) {
        $count = str_word_count($output);
        return $count >= 50 && $count <= 100 ? 1.0 : 0.0;
    })
    ->run();
```

The callable receives three parameters:

- `$input` - The test case input prompt
- `$output` - The agent's generated response
- `$expected` - The expected output (may be null)

Return a float between `0.0` and `1.0`. The evaluator wraps your callable in an anonymous Metric implementation automatically.

## Implementing Custom Metric Classes

For reusable metrics, implement the `Metric` interface:

```php
use Pagent\Contracts\Metric;

final class SentimentMetric implements Metric
{
    public function __construct(
        private readonly string $expectedSentiment
    ) {}

    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        // Simple sentiment detection
        $positiveWords = ['good', 'great', 'excellent', 'happy'];
        $negativeWords = ['bad', 'poor', 'terrible', 'sad'];

        $lowerOutput = strtolower($output);
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($positiveWords as $word) {
            if (str_contains($lowerOutput, $word)) $positiveCount++;
        }

        foreach ($negativeWords as $word) {
            if (str_contains($lowerOutput, $word)) $negativeCount++;
        }

        $sentiment = $positiveCount > $negativeCount ? 'positive' : 'negative';

        return $sentiment === $this->expectedSentiment ? 1.0 : 0.0;
    }

    public function getName(): string
    {
        return 'sentiment_' . $this->expectedSentiment;
    }

    public function getDescription(): string
    {
        return "Validates that output has {$this->expectedSentiment} sentiment";
    }
}
```

Use it like any built-in metric:

```php
evaluate('support-agent')
    ->metric('tone', new SentimentMetric('positive'))
    ->run();
```

The `getName()` and `getDescription()` methods provide metadata for reports and debugging.

## Running Evaluations

Once configured, call `run()` to execute the evaluation:

```php
$result = evaluate('my-agent')
    ->dataset($dataset)
    ->metric('similarity', new SimilarityMetric())
    ->metric('length', new LengthMetric(100, 500))
    ->metric('keywords', new KeywordMetric(['important']))
    ->run();
```

The evaluator processes each test case sequentially:

1. Loads the agent from the registry
2. Sends the input to the agent via `prompt()`
3. Applies all metrics to the output
4. Collects results with scores

The returned `EvaluationResult` object contains detailed statistics.

## Analyzing Results

The `EvaluationResult` object provides several methods for analyzing performance:

### Average Scores

Get the mean score for any metric:

```php
$avgSimilarity = $result->getAverageScore('similarity');
$avgLength = $result->getAverageScore('length');

echo "Average similarity: " . round($avgSimilarity * 100) . "%\n";
```

### All Scores

Retrieve individual scores for distribution analysis:

```php
$scores = $result->getAllScores('similarity');
// [0.95, 0.87, 1.0, 0.92, ...]

$min = min($scores);
$max = max($scores);
$median = $scores[count($scores) / 2];
```

### Summary Statistics

Get a complete statistical summary:

```php
$summary = $result->getSummary();

/*
[
    'agent' => 'my-agent',
    'dataset_size' => 50,
    'metrics' => [
        'similarity' => [
            'average' => 0.87,
            'min' => 0.65,
            'max' => 1.0,
            'description' => 'Calculates text similarity...'
        ],
        'length' => [
            'average' => 0.94,
            'min' => 0.8,
            'max' => 1.0,
            'description' => 'Checks if output is between...'
        ]
    ]
]
*/
```

### Individual Results

Access detailed results for each test case:

```php
foreach ($result->results as $testResult) {
    echo "Input: {$testResult['input']}\n";
    echo "Output: {$testResult['output']}\n";
    echo "Expected: {$testResult['expected']}\n";

    foreach ($testResult['metrics'] as $name => $score) {
        echo "  {$name}: " . round($score * 100) . "%\n";
    }
}
```

### Export to JSON

Generate JSON reports for storage or analysis:

```php
file_put_contents('evaluation_report.json', $result->toJson());
```

The JSON includes all results, metadata, and summary statistics.

## Comparing Agent Performance

A common use case is comparing different agents or configurations:

```php
// Create two agents with different prompts
agent('concise-agent')
    ->provider('anthropic')
    ->system('Be extremely concise. Answer in 1-2 sentences maximum.');

agent('detailed-agent')
    ->provider('anthropic')
    ->system('Provide detailed, comprehensive answers.');

$dataset = Dataset::fromJson('tests/data/questions.json');

// Evaluate both
$conciseResults = evaluate('concise-agent')
    ->dataset($dataset)
    ->metric('similarity', new SimilarityMetric())
    ->metric('length', new LengthMetric(0, 200))
    ->run();

$detailedResults = evaluate('detailed-agent')
    ->dataset($dataset)
    ->metric('similarity', new SimilarityMetric())
    ->metric('length', new LengthMetric(200, 1000))
    ->run();

// Compare
echo "Concise accuracy: " .
    round($conciseResults->getAverageScore('similarity') * 100) . "%\n";
echo "Detailed accuracy: " .
    round($detailedResults->getAverageScore('similarity') * 100) . "%\n";
```

This pattern enables systematic A/B testing of prompt engineering changes, model upgrades, or configuration tweaks.

## Best Practices

**Start Simple**: Begin with basic metrics like similarity or keyword matching before building complex custom metrics.

**Use Multiple Metrics**: No single metric captures all aspects of quality. Combine length, structure, and content checks.

**Version Your Datasets**: Keep datasets in version control alongside code. This makes evaluations reproducible across time.

**Set Thresholds**: Define minimum acceptable scores for production deployment:

```php
$result = evaluate('production-agent')
    ->dataset($dataset)
    ->metric('json', new JsonValidMetric())
    ->run();

$jsonScore = $result->getAverageScore('json');

if ($jsonScore < 0.95) {
    throw new Exception("JSON validity below threshold: {$jsonScore}");
}
```

**Automate in CI/CD**: Run evaluations in continuous integration to catch regressions before deployment.

**Track Over Time**: Store evaluation results to track performance trends as you iterate.

**Test Edge Cases**: Include adversarial inputs in datasets—empty strings, very long inputs, special characters—to validate robustness.

## Real-World Example: Customer Support Agent

Here's a complete evaluation setup for a customer support agent:

```php
use function Pagent\agent;
use function Pagent\evaluate;
use Pagent\Evaluation\Dataset;
use Pagent\Evaluation\Metrics\{
    SimilarityMetric,
    LengthMetric,
    KeywordMetric,
    JsonValidMetric
};

// Configure agent
agent('support-bot')
    ->provider('anthropic')
    ->model('claude-sonnet-4-20250514')
    ->system('You are a helpful customer support agent. Always be polite and professional.');

// Create test dataset
$dataset = Dataset::fromArray([
    [
        'input' => 'How do I reset my password?',
        'expected' => 'Click "Forgot Password" and follow the email instructions',
        'metadata' => ['category' => 'account'],
    ],
    [
        'input' => 'What are your business hours?',
        'expected' => 'We are open Monday-Friday 9am-5pm EST',
        'metadata' => ['category' => 'general'],
    ],
    // ... more test cases
]);

// Run evaluation
$result = evaluate('support-bot')
    ->dataset($dataset)
    ->metric('accuracy', new SimilarityMetric())
    ->metric('politeness', new KeywordMetric(['please', 'thank', 'help']))
    ->metric('length', new LengthMetric(minLength: 20, maxLength: 300))
    ->run();

// Analyze results
$summary = $result->getSummary();

echo "Support Bot Evaluation Results\n";
echo "==============================\n\n";

foreach ($summary['metrics'] as $name => $stats) {
    echo ucfirst($name) . ": " . round($stats['average'] * 100) . "%\n";
}

// Check if meets production standards
$meetsStandards =
    $summary['metrics']['accuracy']['average'] >= 0.8 &&
    $summary['metrics']['politeness']['average'] >= 0.9 &&
    $summary['metrics']['length']['average'] >= 0.95;

echo "\nProduction ready: " . ($meetsStandards ? 'YES' : 'NO') . "\n";
```

This evaluation provides quantifiable metrics for accuracy, tone, and response length—exactly what you need to confidently deploy a support bot.

---

The evaluation framework transforms agent development from guesswork into engineering. With systematic testing, clear metrics, and reproducible results, you can iterate confidently and deploy with certainty.
