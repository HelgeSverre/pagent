# Chapter 20: Evaluation Framework

## What You'll Learn

By the end of this chapter, you'll be able to:
- Design meaningful evaluation metrics for AI agents
- Create comprehensive test datasets for different scenarios
- Implement scoring functions to measure agent performance
- Run automated evaluation suites with consistent methodology
- Generate detailed performance reports with actionable insights

**Prerequisites:** Complete understanding of Chapters 1-9, especially Agent creation, Tool usage, and Response handling

**Time Estimate:** 45 minutes

**Final Result:** A complete evaluation framework for systematically testing and comparing agent performance across multiple dimensions

## Understanding Evaluation Fundamentals

Before diving into implementation, let's understand why systematic evaluation is crucial for AI agents. Unlike traditional software where correctness is binary, AI agent evaluation requires nuanced metrics that capture quality, relevance, and effectiveness.

Think of evaluation like grading a student's essay rather than checking math homework. You need multiple criteria: accuracy, creativity, coherence, and completeness. Similarly, AI agents require multifaceted evaluation approaches.

## Building Your First Metric

Let's start with a simple accuracy metric that measures how often an agent provides correct answers:

```php
<?php

declare(strict_types=1);

namespace App\Evaluation\Metrics;

use Pagent\Agent;

final class AccuracyMetric
{
    private array $results = [];

    public function evaluate(Agent $agent, string $question, string $expectedAnswer): float
    {
        // Get agent's response
        $response = $agent->ask($question)->text();

        // Simple exact match (we'll improve this later)
        $isCorrect = $this->normalize($response) === $this->normalize($expectedAnswer);

        // Store result for reporting
        $this->results[] = [
            'question' => $question,
            'expected' => $expectedAnswer,
            'actual' => $response,
            'correct' => $isCorrect,
            'timestamp' => now(),
        ];

        return $isCorrect ? 1.0 : 0.0;
    }

    private function normalize(string $text): string
    {
        // Remove whitespace and convert to lowercase for comparison
        return strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }

    public function getScore(): float
    {
        if (empty($this->results)) {
            return 0.0;
        }

        $correct = count(array_filter($this->results, fn($r) => $r['correct']));
        return $correct / count($this->results);
    }

    public function getResults(): array
    {
        return $this->results;
    }
}
```

Run this metric with a simple test:

```php
$metric = new AccuracyMetric();
$agent = agent()->withModel('gpt-4o-mini');

// Test with simple questions
$metric->evaluate($agent, 'What is 2 + 2?', '4');
$metric->evaluate($agent, 'What is the capital of France?', 'Paris');
$metric->evaluate($agent, 'Name a primary color', 'Blue'); // This might fail!

echo "Accuracy: " . ($metric->getScore() * 100) . "%\n";
```

Notice the third question might fail because "Red" or "Yellow" are equally valid answers. This highlights why we need more sophisticated metrics.

## Creating Test Datasets

Effective evaluation requires well-structured test data. Let's build a dataset system that supports multiple question types:

```php
<?php

declare(strict_types=1);

namespace App\Evaluation\Datasets;

final class TestDataset
{
    private array $entries = [];

    public function __construct(
        private string $name,
        private string $description,
    ) {}

    public function addEntry(
        string $input,
        mixed $expectedOutput,
        array $metadata = [],
    ): self {
        $this->entries[] = [
            'id' => uniqid('test_'),
            'input' => $input,
            'expected' => $expectedOutput,
            'metadata' => $metadata,
            'type' => $metadata['type'] ?? 'exact',
        ];

        return $this;
    }

    public function addMultipleChoice(
        string $question,
        array $acceptableAnswers,
        array $metadata = [],
    ): self {
        return $this->addEntry(
            $question,
            $acceptableAnswers,
            array_merge($metadata, ['type' => 'multiple_choice'])
        );
    }

    public function addRangeEntry(
        string $question,
        float $min,
        float $max,
        array $metadata = [],
    ): self {
        return $this->addEntry(
            $question,
            ['min' => $min, 'max' => $max],
            array_merge($metadata, ['type' => 'range'])
        );
    }

    public function addSemanticEntry(
        string $input,
        string $expectedMeaning,
        float $similarityThreshold = 0.8,
        array $metadata = [],
    ): self {
        return $this->addEntry(
            $input,
            [
                'meaning' => $expectedMeaning,
                'threshold' => $similarityThreshold,
            ],
            array_merge($metadata, ['type' => 'semantic'])
        );
    }

    public function getEntries(): array
    {
        return $this->entries;
    }

    public function filter(callable $callback): array
    {
        return array_filter($this->entries, $callback);
    }

    public function loadFromJson(string $path): self
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Dataset file not found: {$path}");
        }

        $data = json_decode(file_get_contents($path), true);

        foreach ($data['entries'] ?? [] as $entry) {
            $this->entries[] = $entry;
        }

        return $this;
    }

    public function saveToJson(string $path): void
    {
        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'created_at' => date('Y-m-d H:i:s'),
            'entry_count' => count($this->entries),
            'entries' => $this->entries,
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }
}
```

Now create a comprehensive dataset:

```php
$dataset = new TestDataset(
    'General Knowledge',
    'Basic questions to test agent general knowledge'
);

// Exact match questions
$dataset->addEntry('What is 2 + 2?', '4', ['category' => 'math'])
    ->addEntry('What year did World War II end?', '1945', ['category' => 'history']);

// Multiple choice questions
$dataset->addMultipleChoice(
    'Name a primary color',
    ['Red', 'Blue', 'Yellow'],
    ['category' => 'general']
);

// Range questions
$dataset->addRangeEntry(
    'How many bones are in the adult human body?',
    200,
    210,
    ['category' => 'science']
);

// Semantic similarity questions
$dataset->addSemanticEntry(
    'Explain photosynthesis',
    'The process by which plants convert light energy into chemical energy',
    0.75,
    ['category' => 'biology']
);

$dataset->saveToJson('datasets/general_knowledge.json');
```

## Implementing Advanced Scoring Functions

Let's create a sophisticated scoring system that handles different answer types:

```php
<?php

declare(strict_types=1);

namespace App\Evaluation\Scoring;

use Pagent\Agent;

final class SmartScorer
{
    private array $scorers = [];

    public function __construct()
    {
        $this->registerDefaultScorers();
    }

    private function registerDefaultScorers(): void
    {
        // Exact match scorer
        $this->registerScorer('exact', function($response, $expected) {
            return $this->normalizeText($response) === $this->normalizeText($expected)
                ? 1.0 : 0.0;
        });

        // Multiple choice scorer
        $this->registerScorer('multiple_choice', function($response, $acceptable) {
            $normalized = $this->normalizeText($response);
            foreach ($acceptable as $answer) {
                if ($this->normalizeText($answer) === $normalized) {
                    return 1.0;
                }
            }
            return 0.0;
        });

        // Range scorer
        $this->registerScorer('range', function($response, $range) {
            // Extract numeric value from response
            preg_match('/\d+\.?\d*/', $response, $matches);
            if (empty($matches)) {
                return 0.0;
            }

            $value = (float) $matches[0];
            if ($value >= $range['min'] && $value <= $range['max']) {
                return 1.0;
            }

            // Partial credit based on distance
            $distance = min(
                abs($value - $range['min']),
                abs($value - $range['max'])
            );
            $maxDistance = $range['max'] - $range['min'];

            return max(0, 1 - ($distance / $maxDistance));
        });

        // Semantic similarity scorer (simplified)
        $this->registerScorer('semantic', function($response, $expected) {
            $meaning = $expected['meaning'];
            $threshold = $expected['threshold'];

            // Simple word overlap similarity (in production, use embeddings)
            $responseWords = str_word_count(strtolower($response), 1);
            $expectedWords = str_word_count(strtolower($meaning), 1);

            $intersection = array_intersect($responseWords, $expectedWords);
            $union = array_unique(array_merge($responseWords, $expectedWords));

            $similarity = count($intersection) / count($union);

            return $similarity >= $threshold ? 1.0 : $similarity;
        });
    }

    public function registerScorer(string $type, callable $scorer): void
    {
        $this->scorers[$type] = $scorer;
    }

    public function score(string $response, mixed $expected, string $type): float
    {
        if (! isset($this->scorers[$type])) {
            throw new \InvalidArgumentException("Unknown scorer type: {$type}");
        }

        return ($this->scorers[$type])($response, $expected);
    }

    private function normalizeText(string $text): string
    {
        // Comprehensive normalization
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/', '', $text); // Remove punctuation
        $text = preg_replace('/\s+/', ' ', $text); // Normalize whitespace
        return trim($text);
    }
}
```

## Building the Evaluation Suite

Now let's combine everything into a comprehensive evaluation suite:

```php
<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Evaluation\Datasets\TestDataset;
use App\Evaluation\Scoring\SmartScorer;
use Pagent\Agent;

final class EvaluationSuite
{
    private SmartScorer $scorer;
    private array $results = [];
    private array $metadata = [];

    public function __construct(
        private string $name,
        private ?SmartScorer $customScorer = null,
    ) {
        $this->scorer = $customScorer ?? new SmartScorer();
        $this->metadata['started_at'] = microtime(true);
    }

    public function evaluate(Agent $agent, TestDataset $dataset): self
    {
        $this->metadata['agent_model'] = $agent->model;
        $this->metadata['dataset_name'] = $dataset->name;

        foreach ($dataset->getEntries() as $entry) {
            $this->evaluateEntry($agent, $entry);
        }

        $this->metadata['completed_at'] = microtime(true);
        $this->metadata['duration'] = $this->metadata['completed_at'] - $this->metadata['started_at'];

        return $this;
    }

    private function evaluateEntry(Agent $agent, array $entry): void
    {
        $startTime = microtime(true);

        try {
            // Get agent response
            $response = $agent->ask($entry['input'])->text();

            // Score the response
            $score = $this->scorer->score(
                $response,
                $entry['expected'],
                $entry['type']
            );

            $this->results[] = [
                'entry_id' => $entry['id'],
                'input' => $entry['input'],
                'expected' => $entry['expected'],
                'actual' => $response,
                'score' => $score,
                'type' => $entry['type'],
                'metadata' => $entry['metadata'],
                'duration' => microtime(true) - $startTime,
                'success' => true,
            ];
        } catch (\Exception $e) {
            $this->results[] = [
                'entry_id' => $entry['id'],
                'input' => $entry['input'],
                'expected' => $entry['expected'],
                'actual' => null,
                'score' => 0.0,
                'type' => $entry['type'],
                'metadata' => $entry['metadata'],
                'duration' => microtime(true) - $startTime,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getOverallScore(): float
    {
        if (empty($this->results)) {
            return 0.0;
        }

        $totalScore = array_sum(array_column($this->results, 'score'));
        return $totalScore / count($this->results);
    }

    public function getScoreByCategory(string $category): float
    {
        $categoryResults = array_filter(
            $this->results,
            fn($r) => ($r['metadata']['category'] ?? '') === $category
        );

        if (empty($categoryResults)) {
            return 0.0;
        }

        $totalScore = array_sum(array_column($categoryResults, 'score'));
        return $totalScore / count($categoryResults);
    }

    public function getDetailedMetrics(): array
    {
        return [
            'overall_score' => $this->getOverallScore(),
            'total_entries' => count($this->results),
            'successful_entries' => count(array_filter($this->results, fn($r) => $r['success'])),
            'failed_entries' => count(array_filter($this->results, fn($r) => !$r['success'])),
            'average_response_time' => $this->getAverageResponseTime(),
            'scores_by_type' => $this->getScoresByType(),
            'scores_by_category' => $this->getScoresByCategory(),
            'metadata' => $this->metadata,
        ];
    }

    private function getAverageResponseTime(): float
    {
        $durations = array_column($this->results, 'duration');
        return empty($durations) ? 0.0 : array_sum($durations) / count($durations);
    }

    private function getScoresByType(): array
    {
        $types = array_unique(array_column($this->results, 'type'));
        $scores = [];

        foreach ($types as $type) {
            $typeResults = array_filter(
                $this->results,
                fn($r) => $r['type'] === $type
            );

            $totalScore = array_sum(array_column($typeResults, 'score'));
            $scores[$type] = $totalScore / count($typeResults);
        }

        return $scores;
    }

    private function getScoresByCategory(): array
    {
        $categories = array_unique(
            array_filter(
                array_map(
                    fn($r) => $r['metadata']['category'] ?? null,
                    $this->results
                )
            )
        );

        $scores = [];
        foreach ($categories as $category) {
            $scores[$category] = $this->getScoreByCategory($category);
        }

        return $scores;
    }

    public function getResults(): array
    {
        return $this->results;
    }
}
```

## A/B Testing Framework

Compare different agents or configurations systematically:

```php
<?php

declare(strict_types=1);

namespace App\Evaluation;

use Pagent\Agent;

final class ABTestRunner
{
    private array $results = [];

    public function compare(
        Agent $agentA,
        Agent $agentB,
        TestDataset $dataset,
        string $nameA = 'Agent A',
        string $nameB = 'Agent B',
    ): array {
        // Run evaluation for Agent A
        $suiteA = new EvaluationSuite("{$nameA} Evaluation");
        $suiteA->evaluate($agentA, $dataset);

        // Run evaluation for Agent B
        $suiteB = new EvaluationSuite("{$nameB} Evaluation");
        $suiteB->evaluate($agentB, $dataset);

        // Calculate comparative metrics
        $comparison = $this->calculateComparison($suiteA, $suiteB);

        return [
            'agent_a' => [
                'name' => $nameA,
                'metrics' => $suiteA->getDetailedMetrics(),
                'results' => $suiteA->getResults(),
            ],
            'agent_b' => [
                'name' => $nameB,
                'metrics' => $suiteB->getDetailedMetrics(),
                'results' => $suiteB->getResults(),
            ],
            'comparison' => $comparison,
            'winner' => $this->determineWinner($comparison, $nameA, $nameB),
        ];
    }

    private function calculateComparison(
        EvaluationSuite $suiteA,
        EvaluationSuite $suiteB,
    ): array {
        $metricsA = $suiteA->getDetailedMetrics();
        $metricsB = $suiteB->getDetailedMetrics();

        return [
            'score_difference' => $metricsA['overall_score'] - $metricsB['overall_score'],
            'score_improvement' => $this->calculateImprovement(
                $metricsB['overall_score'],
                $metricsA['overall_score']
            ),
            'speed_difference' => $metricsA['average_response_time'] - $metricsB['average_response_time'],
            'speed_improvement' => $this->calculateImprovement(
                $metricsB['average_response_time'],
                $metricsA['average_response_time'],
                true // Lower is better for time
            ),
            'reliability_comparison' => [
                'success_rate_a' => $metricsA['successful_entries'] / $metricsA['total_entries'],
                'success_rate_b' => $metricsB['successful_entries'] / $metricsB['total_entries'],
            ],
        ];
    }

    private function calculateImprovement(float $baseline, float $new, bool $lowerIsBetter = false): float
    {
        if ($baseline == 0) {
            return 0.0;
        }

        $improvement = (($new - $baseline) / $baseline) * 100;

        return $lowerIsBetter ? -$improvement : $improvement;
    }

    private function determineWinner(array $comparison, string $nameA, string $nameB): string
    {
        if ($comparison['score_difference'] > 0.05) {
            return $nameA;
        } elseif ($comparison['score_difference'] < -0.05) {
            return $nameB;
        }

        return 'Tie (within 5% margin)';
    }
}
```

## Generating Performance Reports

Create comprehensive, actionable reports:

```php
<?php

declare(strict_types=1);

namespace App\Evaluation\Reporting;

final class PerformanceReporter
{
    public function generateReport(array $evaluationData, string $format = 'markdown'): string
    {
        return match ($format) {
            'markdown' => $this->generateMarkdownReport($evaluationData),
            'json' => $this->generateJsonReport($evaluationData),
            'html' => $this->generateHtmlReport($evaluationData),
            default => throw new \InvalidArgumentException("Unknown format: {$format}"),
        };
    }

    private function generateMarkdownReport(array $data): string
    {
        $metrics = $data['metrics'];
        $report = "# Evaluation Report\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        // Executive Summary
        $report .= "## Executive Summary\n\n";
        $report .= sprintf("- **Overall Score**: %.2f%%\n", $metrics['overall_score'] * 100);
        $report .= sprintf("- **Total Tests**: %d\n", $metrics['total_entries']);
        $report .= sprintf("- **Success Rate**: %.2f%%\n",
            ($metrics['successful_entries'] / $metrics['total_entries']) * 100);
        $report .= sprintf("- **Average Response Time**: %.3fs\n\n", $metrics['average_response_time']);

        // Scores by Type
        $report .= "## Performance by Question Type\n\n";
        $report .= "| Type | Score | Grade |\n";
        $report .= "|------|-------|-------|\n";

        foreach ($metrics['scores_by_type'] as $type => $score) {
            $grade = $this->scoreToGrade($score);
            $report .= sprintf("| %s | %.2f%% | %s |\n",
                ucfirst($type), $score * 100, $grade);
        }

        // Scores by Category
        if (!empty($metrics['scores_by_category'])) {
            $report .= "\n## Performance by Category\n\n";
            $report .= "| Category | Score | Grade |\n";
            $report .= "|----------|-------|-------|\n";

            foreach ($metrics['scores_by_category'] as $category => $score) {
                $grade = $this->scoreToGrade($score);
                $report .= sprintf("| %s | %.2f%% | %s |\n",
                    ucfirst($category), $score * 100, $grade);
            }
        }

        // Failures Analysis
        $failures = array_filter($data['results'] ?? [], fn($r) => !$r['success']);
        if (!empty($failures)) {
            $report .= "\n## Failed Tests\n\n";
            foreach ($failures as $failure) {
                $report .= sprintf("- **Question**: %s\n", $failure['input']);
                $report .= sprintf("  - **Error**: %s\n", $failure['error'] ?? 'Unknown');
            }
        }

        // Recommendations
        $report .= "\n## Recommendations\n\n";
        $report .= $this->generateRecommendations($metrics);

        return $report;
    }

    private function scoreToGrade(float $score): string
    {
        return match (true) {
            $score >= 0.95 => 'A+',
            $score >= 0.90 => 'A',
            $score >= 0.85 => 'B+',
            $score >= 0.80 => 'B',
            $score >= 0.75 => 'C+',
            $score >= 0.70 => 'C',
            $score >= 0.65 => 'D',
            default => 'F',
        };
    }

    private function generateRecommendations(array $metrics): string
    {
        $recommendations = [];

        // Score-based recommendations
        if ($metrics['overall_score'] < 0.7) {
            $recommendations[] = "- Consider fine-tuning the model or adjusting prompts";
        }

        // Speed-based recommendations
        if ($metrics['average_response_time'] > 5.0) {
            $recommendations[] = "- Response time is high. Consider using a faster model or optimizing prompts";
        }

        // Type-specific recommendations
        foreach ($metrics['scores_by_type'] as $type => $score) {
            if ($score < 0.6) {
                $recommendations[] = "- Poor performance on {$type} questions. Review training data";
            }
        }

        // Success rate recommendations
        $successRate = $metrics['successful_entries'] / $metrics['total_entries'];
        if ($successRate < 0.95) {
            $recommendations[] = "- Investigate failures to improve reliability";
        }

        return empty($recommendations)
            ? "- Performance is excellent! No immediate improvements needed.\n"
            : implode("\n", $recommendations) . "\n";
    }

    private function generateJsonReport(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    private function generateHtmlReport(array $data): string
    {
        // Convert markdown to HTML for simplicity
        $markdown = $this->generateMarkdownReport($data);

        $html = "<html><head><style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            h1, h2 { color: #333; }
        </style></head><body>";

        // Simple markdown to HTML conversion
        $html .= nl2br(htmlspecialchars($markdown));
        $html .= "</body></html>";

        return $html;
    }
}
```

## Putting It All Together

Here's a complete example running the evaluation framework:

```php
// Create test dataset
$dataset = new TestDataset('Comprehensive Test', 'Full evaluation suite');
$dataset->addEntry('What is 2 + 2?', '4', ['category' => 'math'])
    ->addMultipleChoice('Name a primary color', ['Red', 'Blue', 'Yellow'])
    ->addRangeEntry('How many days in a year?', 365, 366, ['category' => 'general'])
    ->addSemanticEntry('Explain gravity', 'Force that attracts objects with mass');

// Set up agents for comparison
$gpt4 = agent()->withModel('gpt-4o');
$gpt3 = agent()->withModel('gpt-3.5-turbo');

// Run A/B test
$tester = new ABTestRunner();
$results = $tester->compare($gpt4, $gpt3, $dataset, 'GPT-4', 'GPT-3.5');

// Generate report
$reporter = new PerformanceReporter();
$report = $reporter->generateReport($results['agent_a'], 'markdown');

// Save report
file_put_contents('evaluation_report.md', $report);

echo "Evaluation complete!\n";
echo "Winner: " . $results['winner'] . "\n";
echo "Score difference: " . sprintf("%.2f%%",
    $results['comparison']['score_difference'] * 100) . "\n";
```

## Troubleshooting Common Issues

**Problem**: Scores are always 0
**Solution**: Check that your scorer types match dataset entry types

**Problem**: Semantic similarity isn't working well
**Solution**: Consider using embedding-based similarity instead of word overlap

**Problem**: Evaluation takes too long
**Solution**: Run evaluations in parallel or use smaller models for testing

**Problem**: Results are inconsistent
**Solution**: Set temperature to 0 for deterministic responses during evaluation

## Summary

You've built a comprehensive evaluation framework that can:
- Measure agent performance across multiple dimensions
- Handle different types of expected outputs
- Compare agents systematically with A/B testing
- Generate detailed, actionable reports
- Track performance over time

This framework provides the foundation for continuous improvement of your AI agents through data-driven insights.

## Next Steps

- Implement embedding-based semantic similarity for better accuracy
- Add support for multi-turn conversation evaluation
- Create specialized evaluators for specific domains
- Build a dashboard for real-time performance monitoring
- Integrate with CI/CD for automated testing

## Additional Resources

- [Pagent Documentation - Testing](https://github.com/your-repo/pagent/docs/testing)
- [Evaluation Metrics Best Practices](https://www.example.com/metrics)
- [Building AI Test Suites](https://www.example.com/test-suites)
- [Performance Benchmarking Guide](https://www.example.com/benchmarks)