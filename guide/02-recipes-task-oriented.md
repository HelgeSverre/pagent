# Pagent Recipes

**Task-Oriented How-To Guide**

This guide provides step-by-step recipes for common tasks. Each recipe is self-contained and focuses on solving a specific problem.

---

## Table of Contents

- [Installation & Setup](#installation--setup)
- [Recipe 1: Create Your First Agent](#recipe-1-create-your-first-agent)
- [Recipe 2: Add Tool Calling](#recipe-2-add-tool-calling)
- [Recipe 3: Protect Against PII Leaks](#recipe-3-protect-against-pii-leaks)
- [Recipe 4: Build a Multi-Agent Pipeline](#recipe-4-build-a-multi-agent-pipeline)
- [Recipe 5: Evaluate Agent Performance](#recipe-5-evaluate-agent-performance)

---

## Installation & Setup

### Step 1: Install via Composer

```bash
composer require pagent/pagent
```

### Step 2: Set Up Environment Variables

Create a `.env` file:

```bash
ANTHROPIC_API_KEY=your_key_here
OPENAI_API_KEY=your_key_here
```

### Step 3: Verify Installation

```php
<?php
require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\mock;

agent('test')->provider(mock(['ping' => 'pong']));
echo agent('test')->prompt('ping')->content; // Outputs: pong
```

---

## Recipe 1: Create Your First Agent

### Problem

You need to create a basic agent that responds to prompts.

### Solution

```php
<?php
require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\anthropic;

// Define the agent
agent('assistant')
    ->provider(anthropic(['api_key' => getenv('ANTHROPIC_API_KEY')]))
    ->system('You are a helpful assistant.')
    ->model('claude-3-5-sonnet-20241022');

// Use the agent
$response = agent('assistant')->prompt('What is PHP?');
echo $response->content;
```

### Expected Output

```
PHP is a popular server-side scripting language...
```

### Key Points

- Agents are registered by name and reusable
- The `system()` method sets the agent's behavior
- The `prompt()` method sends messages and returns responses

---

## Recipe 2: Add Tool Calling

### Problem

Your agent needs to execute functions like fetching weather data or calculating values.

### Solution

```php
<?php
require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\anthropic;

// Define a calculator tool
$calculator = function (string $operation, float $a, float $b): float {
    return match($operation) {
        'add' => $a + $b,
        'subtract' => $a - $b,
        'multiply' => $a * $b,
        'divide' => $b != 0 ? $a / $b : throw new Exception('Division by zero'),
        default => throw new Exception('Unknown operation'),
    };
};

// Create agent with tool
agent('math-agent')
    ->provider(anthropic())
    ->system('You are a math assistant. Use the calculator tool to perform calculations.')
    ->tool('calculator', $calculator);

// Ask a math question
$response = agent('math-agent')->prompt('What is 156 multiplied by 23?');
echo $response->content;
```

### Expected Output

```
156 multiplied by 23 equals 3,588.
```

### Key Points

- Use `->tool('name', $callable)` to register functions
- The agent automatically decides when to call tools
- Tools receive type-validated arguments from the LLM

---

## Recipe 3: Protect Against PII Leaks

### Problem

You need to prevent your agent from exposing sensitive information like emails, phone numbers, or SSNs.

### Solution

```php
<?php
require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\mock;
use Pagent\Guards\PIIGuard;

agent('secure-agent')
    ->provider(mock())
    ->guard(new PIIGuard());

try {
    agent('secure-agent')->prompt('My email is test@example.com');
} catch (Exception $e) {
    echo "Blocked: " . $e->getMessage();
}
```

### Expected Output

```
Blocked: Potential PII detected in prompt
```

### Available Guards

| Guard                  | Purpose                                   | Example                      |
| ---------------------- | ----------------------------------------- | ---------------------------- |
| `PIIGuard`             | Detects SSN, credit cards, emails, phones | `new PIIGuard()`             |
| `ContentFilterGuard`   | Blocks profanity and harmful content      | `new ContentFilterGuard()`   |
| `PromptInjectionGuard` | Prevents prompt injection attacks         | `new PromptInjectionGuard()` |

### Key Points

- Guards run before prompts are sent to the LLM
- Multiple guards can be chained: `->guard($guard1)->guard($guard2)`
- Use `->fallback()` to handle violations gracefully

---

## Recipe 4: Build a Multi-Agent Pipeline

### Problem

You need multiple agents to work together sequentially (e.g., draft → review → publish).

### Solution

```php
<?php
require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\pipeline;
use function Pagent\anthropic;

// Define specialized agents
agent('writer')
    ->provider(anthropic())
    ->system('You write blog posts.');

agent('editor')
    ->provider(anthropic())
    ->system('You edit and improve writing.');

agent('seo')
    ->provider(anthropic())
    ->system('You optimize content for SEO.');

// Create a pipeline
$result = pipeline('content-creation')
    ->agent('writer')
    ->agent('editor')
    ->agent('seo')
    ->run('Write a blog post about PHP agents.');

echo $result; // Final SEO-optimized content
```

### Expected Output

```
[A polished, SEO-optimized blog post about PHP agents]
```

### Key Points

- Each agent receives the output of the previous agent
- Use `->agent('name', $transformFn)` to modify data between stages
- Access intermediate results with `$pipeline->getResults()`

---

## Recipe 5: Evaluate Agent Performance

### Problem

You need to measure how well your agent performs across different test cases.

### Solution

```php
<?php
require 'vendor/autoload.php';

use function Pagent\agent;
use function Pagent\evaluate;
use function Pagent\anthropic;
use Pagent\Evaluation\Dataset;
use Pagent\Evaluation\Metrics\KeywordMetric;
use Pagent\Evaluation\Metrics\LengthMetric;

// Create agent
agent('support')
    ->provider(anthropic())
    ->system('You provide helpful customer support.');

// Prepare test dataset
$dataset = Dataset::fromArray([
    ['input' => 'How do I reset my password?', 'expected' => 'password reset link'],
    ['input' => 'Where is my order?', 'expected' => 'tracking number'],
]);

// Run evaluation
$evaluator = evaluate('support')
    ->dataset($dataset)
    ->metric('keyword', new KeywordMetric())
    ->metric('length', new LengthMetric(min: 10));

$report = $evaluator->run();

// View results
echo "Average Score: " . $report->summary()['average_score'] . "\n";
echo "Pass Rate: " . $report->summary()['pass_rate'] . "\n";

// Export report
file_put_contents('evaluation.html', $report->toHtml());
```

### Expected Output

```
Average Score: 0.85
Pass Rate: 100%
```

### Available Metrics

- `KeywordMetric` - Check if response contains expected keywords
- `LengthMetric` - Validate response length (min/max)
- `SimilarityMetric` - Compare semantic similarity to expected output

### Key Points

- Create datasets from arrays, JSON, or CSV files
- Chain multiple metrics with `->metric()`
- Export reports to HTML, JSON, or Markdown

---

## Next Steps

For more advanced topics:

- **Middleware System** → Add logging, rate limiting, or custom processing
- **Orchestration Patterns** → Delegation and handoffs between agents
- **Custom Providers** → Implement your own LLM backends

See the `/examples` folder for complete working code.
