# Testing AI Agent Quality: A Hands-On Tutorial

Welcome! Today you're going to master one of the most important skills in AI development: measuring and improving agent quality. By the end of this tutorial, you'll be confidently testing your AI agents like a pro.

## What You'll Learn

- How to measure AI agent performance objectively
- Creating test datasets for your specific use cases
- Building custom metrics that matter for your application
- Generating professional reports for stakeholders
- A/B testing different prompts and models
- Setting up regression testing for production agents

## Prerequisites

- Basic PHP knowledge (variables, functions, arrays)
- Pagent installed and configured
- An API key for OpenAI or Anthropic (we'll use mocks for testing)
- About 30-45 minutes of your time

## Why Evaluation Matters

Imagine you're building a customer support bot. How do you know if it's actually helpful? How do you prove that version 2.0 is better than 1.0? That's where evaluation comes in – it turns subjective quality into objective metrics.

Think of it like unit testing for AI. Just as you wouldn't deploy code without tests, you shouldn't deploy agents without evaluation.

## Tutorial Structure

We'll progress through three levels:
1. **Beginner**: Get your first evaluation running
2. **Intermediate**: Build sophisticated test suites
3. **Advanced**: Production-ready evaluation systems

Ready? Let's dive in!

---

## Level 1: Beginner – Your First Evaluation

### Understanding the Basics

An evaluation has three core components:

1. **Dataset**: The test cases (input/expected output pairs)
2. **Metrics**: How we measure success (keywords, length, similarity)
3. **Agent**: The AI system we're testing

Let's start with the simplest possible evaluation.

### Example 1: Hello World of Evaluation

```php
// Create a simple test dataset
$dataset = Dataset::fromArray([
    ['input' => 'What is PHP?', 'expected' => 'programming language']
]);

// Run evaluation with one metric
$result = evaluate('my-agent')
    ->dataset($dataset)
    ->metric('keywords', new KeywordMetric(['PHP', 'programming']))
    ->run();

// Check the results
echo "Score: " . $result->getAverageScore('keywords') * 100 . "%\n";
```

**What's happening here?**
- We created one test case asking about PHP
- We check if the response mentions "PHP" and "programming"
- The score tells us how well the agent performed (0-100%)

### Example 2: Multiple Test Cases

Now let's test with more data:

```php
$dataset = Dataset::fromArray([
    ['input' => 'How do I reset my password?'],
    ['input' => 'My order never arrived'],
    ['input' => 'I want a refund'],
]);

$result = evaluate('support-bot')
    ->dataset($dataset)
    ->metric('helpful', new KeywordMetric(['help', 'assist', 'sorry', 'understand']))
    ->metric('concise', new LengthMetric(minLength: 20, maxLength: 150))
    ->run();
```

**Pro Tip**: Start with 3-5 test cases. You can always add more later!

### Example 3: Understanding Scores

Metrics return scores between 0.0 and 1.0:
- **1.0 (100%)**: Perfect score
- **0.5 (50%)**: Partially meeting criteria
- **0.0 (0%)**: Not meeting criteria at all

```php
// KeywordMetric with requireAll=false (default)
// If 2 out of 4 keywords found → score = 0.5

// KeywordMetric with requireAll=true
// All keywords must be found → score = 1.0 or 0.0
```

### Try It Yourself!

Run the example with:
```bash
php examples/08-evaluation-progressive.php
```

Look for "Level 1" in the output to see these examples in action.

---

## Level 2: Intermediate – Building Real Test Suites

### Loading Datasets from Files

Managing test data in code gets messy. Let's use JSON files:

```json
// examples/datasets/faq_tests.json
[
  {
    "input": "What are your business hours?",
    "expected": "Monday Friday 9am 5pm",
    "metadata": {
      "category": "hours",
      "importance": "high"
    }
  }
]
```

```php
$dataset = Dataset::fromJson(__DIR__ . '/datasets/faq_tests.json');
```

**Why use files?**
- Version control your test data
- Share datasets across teams
- Organize tests by category

### Custom Metrics with Closures

Sometimes built-in metrics aren't enough. Create your own:

```php
->metric('professional_tone', function($input, $output, $expected): float {
    // Unprofessional words to avoid
    $avoid = ['stupid', 'dumb', 'obviously', 'whatever'];

    foreach ($avoid as $word) {
        if (str_contains(strtolower($output), $word)) {
            return 0.0; // Fail immediately if unprofessional
        }
    }

    // Check for professional indicators
    $professional = ['appreciate', 'understand', 'assist', 'happy to help'];
    $found = 0;

    foreach ($professional as $word) {
        if (str_contains(strtolower($output), $word)) {
            $found++;
        }
    }

    return min(1.0, $found / 2); // Need at least 2 professional phrases
})
```

**Warning**: Custom metrics should always return a float between 0.0 and 1.0!

### Generating Reports

Transform your results into professional reports:

```php
$report = new Report($result);

// Generate HTML for stakeholders
$report->save(__DIR__ . '/reports/evaluation.html');

// Generate Markdown for documentation
$report->save(__DIR__ . '/reports/evaluation.md');

// Generate JSON for further analysis
$report->save(__DIR__ . '/reports/evaluation.json');
```

The HTML report includes:
- Visual metrics summary with scores
- Detailed test case results
- Professional styling for presentations

### A/B Testing Prompts

Compare different agent configurations:

```php
// Test prompt version A
agent('support-v1')
    ->system('Be helpful and concise.');

$resultA = evaluate('support-v1')
    ->dataset($dataset)
    ->metric('quality', new KeywordMetric(['help', 'assist']))
    ->run();

// Test prompt version B
agent('support-v2')
    ->system('You are an expert support agent. Always apologize first, then help.');

$resultB = evaluate('support-v2')
    ->dataset($dataset)
    ->metric('quality', new KeywordMetric(['help', 'assist', 'sorry']))
    ->run();

// Compare results
echo "Version A: " . $resultA->getAverageScore('quality') * 100 . "%\n";
echo "Version B: " . $resultB->getAverageScore('quality') * 100 . "%\n";
```

**Pro Tip**: Always test with the same dataset for fair comparison!

---

## Level 3: Advanced – Production-Ready Systems

### Dataset Transformations

Filter and transform your datasets dynamically:

```php
// Filter to only high-priority test cases
$filtered = $dataset->filter(function($item) {
    return $item['metadata']['priority'] === 'high';
});

// Transform inputs (e.g., add typos to test robustness)
$transformed = $dataset->map(function($item) {
    $item['input'] = str_replace('e', '3', $item['input']); // L33t speak
    return $item;
});
```

### Complex Metric Combinations

Build sophisticated evaluation criteria:

```php
class SentimentAccuracyMetric implements Metric
{
    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        // Expected format: "positive" or "negative"
        if (!$expected) return 0.0;

        $outputLower = strtolower($output);
        $expectedLower = strtolower($expected);

        // Check for exact match
        if (str_contains($outputLower, $expectedLower)) {
            return 1.0;
        }

        // Check for synonyms
        $synonyms = [
            'positive' => ['good', 'great', 'happy', 'satisfied'],
            'negative' => ['bad', 'poor', 'unhappy', 'dissatisfied']
        ];

        if (isset($synonyms[$expectedLower])) {
            foreach ($synonyms[$expectedLower] as $synonym) {
                if (str_contains($outputLower, $synonym)) {
                    return 0.8; // Partial credit for synonyms
                }
            }
        }

        return 0.0;
    }

    // ... other required methods
}
```

### Regression Testing

Ensure new versions don't break existing functionality:

```php
class RegressionTest
{
    private array $baseline;

    public function captureBaseline(string $agentName, Dataset $dataset): void
    {
        $result = evaluate($agentName)
            ->dataset($dataset)
            ->metric('keywords', new KeywordMetric(['important', 'terms']))
            ->run();

        $this->baseline = $result->getSummary();
    }

    public function testRegression(string $agentName, Dataset $dataset): bool
    {
        $result = evaluate($agentName)
            ->dataset($dataset)
            ->metric('keywords', new KeywordMetric(['important', 'terms']))
            ->run();

        $current = $result->getSummary();

        // Ensure no metric dropped by more than 5%
        foreach ($current['metrics'] as $name => $data) {
            $baselineScore = $this->baseline['metrics'][$name]['average'];
            $currentScore = $data['average'];

            if ($currentScore < $baselineScore - 0.05) {
                echo "REGRESSION DETECTED: {$name} dropped from {$baselineScore} to {$currentScore}\n";
                return false;
            }
        }

        return true;
    }
}
```

### CI/CD Integration

Add to your GitHub Actions workflow:

```yaml
# .github/workflows/agent-tests.yml
name: Agent Evaluation
on: [push, pull_request]

jobs:
  evaluate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install dependencies
        run: composer install

      - name: Run agent evaluation
        run: php examples/08-evaluation-progressive.php

      - name: Check regression
        run: |
          SCORE=$(php -r "
            require 'vendor/autoload.php';
            \$result = evaluate('production-agent')
                ->dataset(Dataset::fromJson('tests/regression.json'))
                ->metric('quality', new KeywordMetric(['required', 'terms']))
                ->run();
            echo \$result->getAverageScore('quality');
          ")

          if (( $(echo "$SCORE < 0.8" | bc -l) )); then
            echo "Quality score below threshold: $SCORE"
            exit 1
          fi
```

---

## Common Pitfalls and Solutions

### Pitfall 1: Over-fitting to Test Data

**Problem**: Your agent scores 100% on tests but fails in production.

**Solution**: Use diverse test cases and regularly add new ones from real user interactions.

```php
// Bad: Too specific
$dataset = Dataset::fromArray([
    ['input' => 'What is 2+2?', 'expected' => '4']
]);

// Good: More general
$dataset = Dataset::fromArray([
    ['input' => 'What is 2+2?', 'expected' => 'four'],
    ['input' => 'Calculate 2 plus 2', 'expected' => '4'],
    ['input' => 'Add two and two', 'expected' => 'sum is 4']
]);
```

### Pitfall 2: Metrics That Don't Matter

**Problem**: High scores but users are unhappy.

**Solution**: Design metrics that reflect real user needs.

```php
// Bad: Just checking length
->metric('length', new LengthMetric(50, 200))

// Good: Checking actual helpfulness
->metric('helpful', new KeywordMetric(['solution', 'fix', 'resolve', 'help']))
->metric('actionable', function($input, $output) {
    // Check for action words
    $actions = ['click', 'go to', 'select', 'enter', 'contact'];
    foreach ($actions as $action) {
        if (str_contains(strtolower($output), $action)) {
            return 1.0;
        }
    }
    return 0.0;
})
```

### Pitfall 3: Not Testing Edge Cases

**Problem**: Agent breaks on unexpected input.

**Solution**: Include edge cases in your dataset.

```php
$edgeCases = Dataset::fromArray([
    ['input' => ''], // Empty input
    ['input' => '!@#$%^&*()'], // Special characters
    ['input' => str_repeat('a', 1000)], // Very long input
    ['input' => 'HELP ME NOW!!!'], // All caps
    ['input' => '你好'], // Non-English
]);
```

---

## Real-World Scenarios

### Scenario 1: Customer Support Bot

```php
$supportTests = Dataset::fromArray([
    [
        'input' => 'My order #12345 never arrived',
        'expected' => 'track investigate apologize'
    ],
    [
        'input' => 'This product is broken!',
        'expected' => 'sorry replacement refund'
    ]
]);

evaluate('support-bot')
    ->dataset($supportTests)
    ->metric('empathy', new KeywordMetric(['sorry', 'understand', 'apologize']))
    ->metric('solution', new KeywordMetric(['help', 'resolve', 'fix', 'replace', 'refund']))
    ->metric('concise', new LengthMetric(50, 200))
    ->run();
```

### Scenario 2: Content Summarizer

```php
$summaryTests = Dataset::fromArray([
    [
        'input' => $longArticle,
        'expected' => 'main points key takeaways'
    ]
]);

evaluate('summarizer')
    ->dataset($summaryTests)
    ->metric('brevity', new LengthMetric(100, 300))
    ->metric('coverage', new KeywordMetric($importantTerms))
    ->metric('similarity', new SimilarityMetric()) // Compares to expected
    ->run();
```

### Scenario 3: Code Reviewer

```php
$codeReviewTests = Dataset::fromArray([
    [
        'input' => 'function getData() { return $data; }',
        'expected' => 'undefined variable'
    ],
    [
        'input' => 'while(true) { processItem(); }',
        'expected' => 'infinite loop'
    ]
]);

evaluate('code-reviewer')
    ->dataset($codeReviewTests)
    ->metric('issues_found', new KeywordMetric(['error', 'warning', 'issue', 'problem']))
    ->metric('actionable', new KeywordMetric(['fix', 'change', 'update', 'replace']))
    ->run();
```

---

## Next Steps

Congratulations! You've mastered agent evaluation. Here's where to go next:

1. **Build Your Own Dataset**: Start with 10 real test cases from your application
2. **Create Custom Metrics**: Design metrics specific to your use case
3. **Set Up CI/CD**: Automate evaluation in your deployment pipeline
4. **Track Over Time**: Build a dashboard to monitor agent performance
5. **Share Results**: Generate reports for your team and stakeholders

## Additional Resources

- [Full API Reference](../docs/evaluation.md)
- [Example Code](08-evaluation-progressive.php) - Run all examples from this tutorial
- [Dataset Examples](datasets/) - Sample datasets for different use cases
- [Custom Metrics Guide](../docs/custom-metrics.md)

## Quick Reference

```php
// Essential functions
evaluate(string $agentName): Evaluator
Dataset::fromArray(array $items): Dataset
Dataset::fromJson(string $path): Dataset
new KeywordMetric(array $keywords, bool $requireAll = false)
new LengthMetric(int $min = 0, int $max = PHP_INT_MAX)
new SimilarityMetric()
new Report(EvaluationResult $result)

// Evaluator methods
->dataset(Dataset|string $dataset): self
->metric(string $name, Metric|callable $metric): self
->run(): EvaluationResult

// Result methods
->getAverageScore(string $metricName): float
->getSummary(): array
->toJson(): string

// Report methods
->save(string $path): void
->toHtml(): string
->toMarkdown(): string
```

---

## Final Thoughts

Remember: evaluation isn't about getting perfect scores. It's about:
- Understanding your agent's strengths and weaknesses
- Making informed decisions about improvements
- Building confidence in your AI systems
- Proving value to stakeholders

Start simple, iterate often, and let the metrics guide you to better agents.

Happy evaluating! 🎯