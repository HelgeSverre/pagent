<?php

declare(strict_types=1);

/**
 * Progressive Evaluation Tutorial - Learn to Test AI Agent Quality
 *
 * This example demonstrates how to evaluate AI agents from beginner to advanced level.
 * Each section builds upon the previous, teaching you real-world evaluation techniques.
 *
 * Run this file: php examples/08-evaluation-progressive.php
 */

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;
use Pagent\Contracts\Metric;
use Pagent\Evaluation\Dataset;
use Pagent\Evaluation\Metrics\KeywordMetric;
use Pagent\Evaluation\Metrics\LengthMetric;
use Pagent\Evaluation\Metrics\SimilarityMetric;
use Pagent\Evaluation\Report;

// Load environment variables if .env exists
if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

// Keep console output compact and consistent with the other examples.
function showSection(string $title): void
{
    echo "\n[{$title}]\n";
}

// Display metric scores in a format that is easy to scan and copy.
function showResult(string $label, float $score, string $description = ''): void
{
    $percentage = round($score * 100, 1);

    echo "{$label}: {$percentage}%";
    if ($description) {
        echo " - {$description}";
    }
    echo "\n";
}

// ==============================================================================
//                                   INTRODUCTION
// ==============================================================================

showSection('Pagent: Progressive Evaluation Tutorial');

echo "This tutorial progresses from basic evaluations to regression testing.\n\n";
echo "What you'll learn:\n";
echo "- Create test datasets\n";
echo "- Use built-in and custom metrics\n";
echo "- Generate reports\n";
echo "- Compare prompts\n";
echo "- Detect regressions\n";

// ==============================================================================
//                          LEVEL 1: BEGINNER - GETTING STARTED
// ==============================================================================

showSection('Level 1: First Evaluation');

// -------------------------------
// Example 1: Hello World
// -------------------------------

showSection('Example 1: Minimal Evaluation');

echo "Let's start with the simplest possible evaluation.\n";
echo "We'll test if an agent knows what PHP is.\n\n";

// Configure a simple test agent using mock provider for consistent results
agent('test-bot')
    ->provider('mock', [
        'responses' => [
            'What is PHP?' => 'PHP is a popular server-side programming language used for web development.',
        ],
    ])
    ->model('mock-model')
    ->system('You are a helpful assistant.');

// Create a minimal dataset with one test case
$simpleDataset = Dataset::fromArray([
    ['input' => 'What is PHP?', 'expected' => 'programming language'],
]);

// Run evaluation with a single keyword metric
$result1 = evaluate('test-bot')
    ->dataset($simpleDataset)
    ->metric('keywords', new KeywordMetric(['PHP', 'programming', 'language']))
    ->run();

// Display results
echo "Test Input: 'What is PHP?'\n";
echo 'Agent Response: '.$result1->results[0]['output']."\n\n";
showResult('Keyword Match Score', $result1->getAverageScore('keywords'), 'Found required keywords');

echo "\nTip: Start simple! Even one test case can reveal issues.\n";

// -------------------------------
// Example 2: Multiple Test Cases
// -------------------------------

showSection('Example 2: Testing Multiple Scenarios');

echo "Now let's test a customer support bot with multiple test cases.\n\n";

// Configure a support bot
agent('support-bot')
    ->provider('mock', [
        'responses' => [
            'How do I reset my password?' => 'I can help you reset your password. Please go to the login page and click "Forgot Password".',
            'My order never arrived' => 'I apologize for the inconvenience. Let me help you track your order and find a solution.',
            'I want a refund' => 'I understand you want a refund. I\'ll be happy to assist you with the refund process.',
        ],
    ])
    ->model('mock-model')
    ->system('You are a helpful customer support agent.');

// Create a dataset with multiple test cases
$supportDataset = Dataset::fromArray([
    ['input' => 'How do I reset my password?'],
    ['input' => 'My order never arrived'],
    ['input' => 'I want a refund'],
]);

// Evaluate with multiple metrics
$result2 = evaluate('support-bot')
    ->dataset($supportDataset)
    ->metric('helpfulness', new KeywordMetric(['help', 'assist', 'sorry', 'understand', 'apologize']))
    ->metric('conciseness', new LengthMetric(minLength: 20, maxLength: 150))
    ->run();

// Display results for each test case
foreach ($result2->results as $i => $testCase) {
    echo 'Test Case '.($i + 1).': "'.$testCase['input']."\"\n";
    echo '  Response: '.substr($testCase['output'], 0, 60)."...\n";
    echo '  ';
    showResult('Helpfulness', $testCase['metrics']['helpfulness']);
    echo '  ';
    showResult('Conciseness', $testCase['metrics']['conciseness']);
    echo "\n";
}

// Show overall summary
echo "Overall Scores:\n";
showResult('Average Helpfulness', $result2->getAverageScore('helpfulness'));
showResult('Average Conciseness', $result2->getAverageScore('conciseness'));

// -------------------------------
// Example 3: Understanding Metrics
// -------------------------------

showSection('Example 3: How Metrics Work');

echo "Let's understand how different metrics calculate scores.\n\n";

// Test different keyword matching modes
$keywordTests = Dataset::fromArray([
    ['input' => 'Tell me about security', 'expected' => 'encryption authentication authorization'],
]);

agent('security-bot')
    ->provider('mock', [
        'responses' => [
            'Tell me about security' => 'Security involves encryption and authentication to protect data.',
        ],
    ])
    ->model('mock-model');

// Test with partial matching (default)
$partialResult = evaluate('security-bot')
    ->dataset($keywordTests)
    ->metric('partial_keywords', new KeywordMetric(['encryption', 'authentication', 'authorization', 'firewall'], requireAll: false))
    ->run();

// Test with require all keywords
$strictResult = evaluate('security-bot')
    ->dataset($keywordTests)
    ->metric('all_keywords', new KeywordMetric(['encryption', 'authentication'], requireAll: true))
    ->run();

echo "Response: \"Security involves encryption and authentication to protect data.\"\n\n";
echo "Partial Match (2 of 4 keywords found):\n";
showResult('  Score', $partialResult->getAverageScore('partial_keywords'), '50% of keywords found');
echo "\nRequire All (both keywords found):\n";
showResult('  Score', $strictResult->getAverageScore('all_keywords'), 'All required keywords present');

echo "\nWarning: Choose requireAll=true carefully - it's very strict!\n";

// ==============================================================================
//                        Level 2: BUILDING SKILLS
// ==============================================================================

showSection('Level 2: Building Real Test Suites');

// -------------------------------
// Example 4: Loading from JSON
// -------------------------------

showSection('Example 4: Loading Datasets from Files');

echo "Managing test data in files is cleaner and more maintainable.\n\n";

// Check if the dataset file exists
$datasetPath = __DIR__.'/datasets/support_tickets.json';
if (file_exists($datasetPath)) {
    $fileDataset = Dataset::fromJson($datasetPath);
    echo 'Loaded: '.$fileDataset->count()." test cases from JSON file\n";

    // Show first test case as example
    $items = $fileDataset->items();
    if (! empty($items)) {
        echo "\nExample test case from file:\n";
        echo '  Input: '.$items[0]['input']."\n";
        echo '  Expected: '.$items[0]['expected']."\n";
        if (isset($items[0]['metadata'])) {
            echo '  Category: '.$items[0]['metadata']['category']."\n";
            echo '  Priority: '.$items[0]['metadata']['priority']."\n";
        }
    }
} else {
    echo "Status: Dataset file not found; creating an example dataset\n";

    // Create example dataset file
    $exampleData = [
        [
            'input' => 'What are your business hours?',
            'expected' => 'Monday Friday 9am 5pm',
            'metadata' => ['category' => 'hours', 'importance' => 'high'],
        ],
        [
            'input' => 'Do you ship internationally?',
            'expected' => 'worldwide shipping available',
            'metadata' => ['category' => 'shipping', 'importance' => 'medium'],
        ],
    ];

    // Ensure directory exists
    if (! is_dir(dirname($datasetPath))) {
        mkdir(dirname($datasetPath), 0755, true);
    }

    file_put_contents($datasetPath, json_encode($exampleData, JSON_PRETTY_PRINT));
    echo "Created: {$datasetPath}\n";
}

// -------------------------------
// Example 5: Custom Metrics
// -------------------------------

showSection('Example 5: Building Custom Metrics with Closures');

echo "Sometimes you need metrics specific to your use case.\n\n";

// Create an agent for testing professional tone
agent('professional-bot')
    ->provider('mock', [
        'responses' => [
            'Help me fix this!' => 'I\'d be happy to assist you with resolving this issue. Let me help you find a solution.',
            'This is stupid!' => 'I understand your frustration. Let me help you resolve this matter.',
            'Whatever, just fix it' => 'I appreciate your patience. I\'ll help you get this sorted out right away.',
        ],
    ])
    ->model('mock-model');

$toneDataset = Dataset::fromArray([
    ['input' => 'Help me fix this!'],
    ['input' => 'This is stupid!'],
    ['input' => 'Whatever, just fix it'],
]);

// Create a custom metric for professional tone
$professionalToneMetric = function (string $input, string $output, mixed $expected = null): float {
    // Words that indicate unprofessional tone (immediate fail)
    $unprofessional = ['stupid', 'dumb', 'obviously', 'whatever', 'idiotic'];

    $outputLower = strtolower($output);
    foreach ($unprofessional as $word) {
        if (str_contains($outputLower, $word)) {
            return 0.0; // Fail if any unprofessional word is used
        }
    }

    // Professional indicators (boost score)
    $professional = ['appreciate', 'understand', 'assist', 'happy to help', 'glad to', 'certainly'];
    $foundCount = 0;

    foreach ($professional as $phrase) {
        if (str_contains($outputLower, $phrase)) {
            $foundCount++;
        }
    }

    // Score based on professional phrases found (need at least 2)
    return min(1.0, $foundCount / 2);
};

// Run evaluation with custom metric
$toneResult = evaluate('professional-bot')
    ->dataset($toneDataset)
    ->metric('professional_tone', $professionalToneMetric)
    ->metric('empathy', new KeywordMetric(['understand', 'appreciate', 'help', 'assist']))
    ->run();

echo "Testing Professional Tone:\n\n";
foreach ($toneResult->results as $i => $test) {
    echo 'Customer: "'.$test['input']."\"\n";
    echo 'Agent: "'.substr($test['output'], 0, 70)."...\"\n";
    showResult('  Professional Tone', $test['metrics']['professional_tone']);
    showResult('  Empathy Score', $test['metrics']['empathy']);
    echo "\n";
}

echo "Tip: Custom metrics let you measure what really matters for YOUR use case!\n";

// -------------------------------
// Example 6: Generating Reports
// -------------------------------

showSection('Example 6: Professional Reporting');

echo "Transform your evaluation results into professional reports.\n\n";

// Create a small evaluation for reporting
agent('report-demo')
    ->provider('mock', [
        'responses' => [
            'What is AI?' => 'AI (Artificial Intelligence) is technology that enables machines to learn and make decisions.',
            'Explain machine learning' => 'Machine learning is a subset of AI where computers learn patterns from data.',
        ],
    ])
    ->model('mock-model');

$reportDataset = Dataset::fromArray([
    ['input' => 'What is AI?', 'expected' => 'artificial intelligence technology'],
    ['input' => 'Explain machine learning', 'expected' => 'AI subset patterns data'],
]);

$reportResult = evaluate('report-demo')
    ->dataset($reportDataset)
    ->metric('accuracy', new KeywordMetric(['AI', 'artificial', 'intelligence', 'machine', 'learning', 'data']))
    ->metric('brevity', new LengthMetric(minLength: 20, maxLength: 100))
    ->metric('similarity', new SimilarityMetric)
    ->run();

// Create report generator
$report = new Report($reportResult);

// Ensure reports directory exists
$reportsDir = __DIR__.'/reports';
if (! is_dir($reportsDir)) {
    mkdir($reportsDir, 0755, true);
}

// Generate different report formats
$htmlPath = $reportsDir.'/evaluation_tutorial.html';
$mdPath = $reportsDir.'/evaluation_tutorial.md';
$jsonPath = $reportsDir.'/evaluation_tutorial.json';

$report->save($htmlPath);
$report->save($mdPath);
$report->save($jsonPath);

echo "Saved: {$htmlPath}\n";
echo "Saved: {$mdPath}\n";
echo "Saved: {$jsonPath}\n\n";

echo "Open the HTML report in a browser to inspect the rendered results.\n";

// -------------------------------
// Example 7: A/B Testing
// -------------------------------

showSection('Example 7: A/B Testing Different Prompts');

echo "Compare different agent configurations to find the best one.\n\n";

// Test dataset for comparison
$comparisonDataset = Dataset::fromArray([
    ['input' => 'I need help', 'expected' => 'assist help support'],
    ['input' => 'This isn\'t working', 'expected' => 'fix resolve solution'],
    ['input' => 'Cancel my order', 'expected' => 'cancel process confirm'],
]);

// Version A: Brief and direct
agent('agent-v1')
    ->provider('mock', [
        'responses' => [
            'I need help' => 'How can I help you?',
            'This isn\'t working' => 'Let me fix that.',
            'Cancel my order' => 'Order cancelled.',
        ],
    ])
    ->model('mock-model')
    ->system('Be helpful and concise.');

// Version B: More elaborate and empathetic
agent('agent-v2')
    ->provider('mock', [
        'responses' => [
            'I need help' => 'I understand you need assistance. I\'m here to help you with whatever you need.',
            'This isn\'t working' => 'I apologize for the inconvenience. Let me help you resolve this issue right away.',
            'Cancel my order' => 'I\'ll help you cancel your order. The cancellation has been processed and confirmed.',
        ],
    ])
    ->model('mock-model')
    ->system('You are an empathetic support agent. Always acknowledge feelings and provide detailed help.');

// Evaluate both versions
$metricsToTest = [
    'helpfulness' => new KeywordMetric(['help', 'assist', 'support', 'fix', 'resolve', 'cancel']),
    'empathy' => new KeywordMetric(['understand', 'apologize', 'sorry', 'acknowledge']),
    'brevity' => new LengthMetric(minLength: 5, maxLength: 50),
];

echo "Testing Version A (Brief and Direct):\n";
$resultA = evaluate('agent-v1')->dataset($comparisonDataset);
foreach ($metricsToTest as $name => $metric) {
    $resultA->metric($name, $metric);
}
$resultA = $resultA->run();

echo "Testing Version B (Elaborate and Empathetic):\n";
$resultB = evaluate('agent-v2')->dataset($comparisonDataset);
foreach ($metricsToTest as $name => $metric) {
    $resultB->metric($name, $metric);
}
$resultB = $resultB->run();

// Compare results
echo "\n A/B Test Results:\n\n";
echo "Metric         | Version A | Version B | Winner\n";
echo "---------------|-----------|-----------|--------\n";

foreach (['helpfulness', 'empathy', 'brevity'] as $metric) {
    $scoreA = $resultA->getAverageScore($metric);
    $scoreB = $resultB->getAverageScore($metric);
    $winner = $scoreA > $scoreB ? 'A' : ($scoreB > $scoreA ? 'B' : 'Tie');

    printf("%-14s | %8.1f%% | %8.1f%% | %s\n",
        ucfirst($metric),
        $scoreA * 100,
        $scoreB * 100,
        $winner
    );
}

echo "\nTip: A/B testing helps you make data-driven decisions about prompts!\n";

// ==============================================================================
//                      Level 3: PRODUCTION READY
// ==============================================================================

showSection('Level 3: Production-Ready Systems');

// -------------------------------
// Example 8: Dataset Transformations
// -------------------------------

showSection('Example 8: Dataset Filtering and Transformation');

echo "Dynamically filter and transform your test datasets.\n\n";

// Create a comprehensive dataset
$fullDataset = Dataset::fromArray([
    ['input' => 'urgent: system down', 'metadata' => ['priority' => 'high', 'category' => 'technical']],
    ['input' => 'how to update profile?', 'metadata' => ['priority' => 'low', 'category' => 'account']],
    ['input' => 'payment failed', 'metadata' => ['priority' => 'high', 'category' => 'billing']],
    ['input' => 'where is my order?', 'metadata' => ['priority' => 'medium', 'category' => 'shipping']],
    ['input' => 'app crashed', 'metadata' => ['priority' => 'high', 'category' => 'technical']],
]);

// Filter to only high-priority items
$highPriorityDataset = $fullDataset->filter(function ($item) {
    return isset($item['metadata']['priority']) && $item['metadata']['priority'] === 'high';
});

echo 'Original dataset: '.$fullDataset->count()." items\n";
echo 'After filtering (high priority only): '.$highPriorityDataset->count()." items\n\n";

// Transform dataset to add variations (test robustness)
$robustnessDataset = $fullDataset->map(function ($item) {
    // Add typos and case variations to test robustness
    $variations = [
        strtoupper($item['input']), // ALL CAPS
        strtolower($item['input']), // all lowercase
        str_replace(' ', '  ', $item['input']), // Extra spaces
    ];

    // Pick a random variation
    $item['original_input'] = $item['input'];
    $item['input'] = $variations[array_rand($variations)];

    return $item;
});

echo "Testing robustness with transformed inputs:\n";
foreach ($robustnessDataset->items() as $item) {
    echo '  Original: "'.$item['original_input']."\"\n";
    echo '  Transformed: "'.$item['input']."\"\n";
}

echo "\nTip: Test with transformed data to ensure your agent handles edge cases!\n";

// -------------------------------
// Example 9: Complex Custom Metrics
// -------------------------------

showSection('Example 9: Implementing Complex Metrics');

echo "Build sophisticated metrics for specific use cases.\n\n";

// Create a sentiment analysis accuracy metric
class SentimentAccuracyMetric implements Metric
{
    public function calculate(string $input, string $output, mixed $expected = null): float
    {
        if (! $expected) {
            return 0.0;
        }

        $outputLower = strtolower($output);
        $expectedLower = strtolower($expected);

        // Direct match
        if (str_contains($outputLower, $expectedLower)) {
            return 1.0;
        }

        // Check for synonyms
        $synonyms = [
            'positive' => ['good', 'great', 'excellent', 'happy', 'satisfied', 'pleased'],
            'negative' => ['bad', 'poor', 'terrible', 'unhappy', 'dissatisfied', 'upset'],
            'neutral' => ['okay', 'fine', 'average', 'moderate', 'neither'],
        ];

        // Award partial credit for synonyms
        if (isset($synonyms[$expectedLower])) {
            foreach ($synonyms[$expectedLower] as $synonym) {
                if (str_contains($outputLower, $synonym)) {
                    return 0.8; // 80% credit for synonym match
                }
            }
        }

        // Check for opposite sentiment (complete failure)
        $opposites = [
            'positive' => ['negative', 'bad', 'poor'],
            'negative' => ['positive', 'good', 'great'],
        ];

        if (isset($opposites[$expectedLower])) {
            foreach ($opposites[$expectedLower] as $opposite) {
                if (str_contains($outputLower, $opposite)) {
                    return 0.0; // Wrong sentiment = failure
                }
            }
        }

        return 0.2; // Small credit if at least attempted
    }

    public function getName(): string
    {
        return 'sentiment_accuracy';
    }

    public function getDescription(): string
    {
        return 'Measures accuracy of sentiment classification with synonym support';
    }
}

// Test the sentiment metric
agent('sentiment-analyzer')
    ->provider('mock', [
        'responses' => [
            'I love this product!' => 'The sentiment is very positive and enthusiastic.',
            'This is terrible' => 'The sentiment is negative and dissatisfied.',
            'It\'s okay I guess' => 'The sentiment is neutral, neither positive nor negative.',
        ],
    ])
    ->model('mock-model');

$sentimentDataset = Dataset::fromArray([
    ['input' => 'I love this product!', 'expected' => 'positive'],
    ['input' => 'This is terrible', 'expected' => 'negative'],
    ['input' => 'It\'s okay I guess', 'expected' => 'neutral'],
]);

$sentimentResult = evaluate('sentiment-analyzer')
    ->dataset($sentimentDataset)
    ->metric('sentiment', new SentimentAccuracyMetric)
    ->run();

echo "Sentiment Analysis Results:\n\n";
foreach ($sentimentResult->results as $test) {
    echo 'Input: "'.$test['input']."\"\n";
    echo 'Expected: '.$test['expected']."\n";
    echo 'Response: "'.$test['output']."\"\n";
    showResult('Accuracy', $test['metrics']['sentiment']);
    echo "\n";
}

// -------------------------------
// Example 10: Regression Testing
// -------------------------------

showSection('Example 10: Regression Testing System');

echo "Ensure new versions don't break existing functionality.\n\n";

// Simulate a regression testing system
class RegressionTester
{
    private array $baseline = [];

    private float $threshold = 0.05; // Allow 5% degradation

    public function captureBaseline(string $agentName, Dataset $dataset, array $metrics): void
    {
        $evaluator = evaluate($agentName)->dataset($dataset);

        foreach ($metrics as $name => $metric) {
            $evaluator->metric($name, $metric);
        }

        $result = $evaluator->run();
        $this->baseline = $result->getSummary();

        echo "Baseline: {$agentName}\n";
        foreach ($this->baseline['metrics'] as $name => $data) {
            echo "  {$name}: ".round($data['average'] * 100, 1)."%\n";
        }
    }

    public function testRegression(string $agentName, Dataset $dataset, array $metrics): bool
    {
        if (empty($this->baseline)) {
            echo "Status: No baseline found; capturing one now\n";
            $this->captureBaseline($agentName, $dataset, $metrics);

            return true;
        }

        $evaluator = evaluate($agentName)->dataset($dataset);

        foreach ($metrics as $name => $metric) {
            $evaluator->metric($name, $metric);
        }

        $result = $evaluator->run();
        $current = $result->getSummary();

        echo "\n[Regression Test Results]\n";
        echo "Metric         | Baseline | Current | Change | Status\n";
        echo "---------------|----------|---------|--------|--------\n";

        $passed = true;

        foreach ($current['metrics'] as $name => $data) {
            $baselineScore = $this->baseline['metrics'][$name]['average'] ?? 0;
            $currentScore = $data['average'];
            $change = $currentScore - $baselineScore;

            $status = 'PASS';
            if ($change < -$this->threshold) {
                $status = 'FAIL';
                $passed = false;
            } elseif ($change > $this->threshold) {
                $status = 'IMPROVED';
            }

            printf("%-14s | %7.1f%% | %7.1f%% | %+6.1f%% | %s\n",
                $name,
                $baselineScore * 100,
                $currentScore * 100,
                $change * 100,
                $status
            );
        }

        return $passed;
    }
}

// Configure test agents
agent('production-v1')
    ->provider('mock', [
        'responses' => [
            'help' => 'I can help you with that.',
            'error' => 'Let me fix that error for you.',
        ],
    ])
    ->model('mock-model');

agent('production-v2')
    ->provider('mock', [
        'responses' => [
            'help' => 'I\'d be happy to assist you with that issue.',
            'error' => 'I understand there\'s an error. Let me help resolve it.',
        ],
    ])
    ->model('mock-model');

// Test regression system
$regressionDataset = Dataset::fromArray([
    ['input' => 'help'],
    ['input' => 'error'],
]);

$regressionMetrics = [
    'helpfulness' => new KeywordMetric(['help', 'assist', 'fix', 'resolve']),
    'clarity' => new LengthMetric(minLength: 10, maxLength: 100),
];

$tester = new RegressionTester;

// Capture baseline with v1
echo "Setting up baseline with production-v1:\n";
$tester->captureBaseline('production-v1', $regressionDataset, $regressionMetrics);

// Test v2 against baseline
echo "\nTesting production-v2 for regressions:\n";
$passed = $tester->testRegression('production-v2', $regressionDataset, $regressionMetrics);

if ($passed) {
    echo "\nStatus: Regression checks passed\n";
} else {
    echo "\nStatus: Regression detected; review changes before deployment\n";
}

// -------------------------------
// Example 11: Real-World Scenarios
// -------------------------------

showSection('Example 11: Real-World Use Cases');

echo "Complete examples for common AI agent use cases.\n\n";

// Scenario 1: Customer Support Bot
showSection('Scenario 1: Customer Support Bot');

agent('customer-support')
    ->provider('mock', [
        'responses' => [
            'My package is 5 days late!' => 'I sincerely apologize for the delay with your package. I understand how frustrating this must be. Let me immediately track your order and expedite a solution for you.',
            'The product broke after 2 days' => 'I\'m very sorry to hear about the issue with your product. This is definitely not the experience we want you to have. I can arrange an immediate replacement or full refund, whichever you prefer.',
        ],
    ])
    ->model('mock-model')
    ->system('You are an empathetic customer support agent.');

$supportScenarios = Dataset::fromArray([
    ['input' => 'My package is 5 days late!', 'expected' => 'apologize track expedite'],
    ['input' => 'The product broke after 2 days', 'expected' => 'sorry replacement refund'],
]);

$supportResult = evaluate('customer-support')
    ->dataset($supportScenarios)
    ->metric('empathy', new KeywordMetric(['sorry', 'apologize', 'understand', 'frustrating']))
    ->metric('action_oriented', new KeywordMetric(['track', 'arrange', 'expedite', 'replacement', 'refund']))
    ->metric('response_length', new LengthMetric(minLength: 50, maxLength: 200))
    ->run();

foreach ($supportResult->results as $i => $result) {
    echo 'Case '.($i + 1).': "'.$result['input']."\"\n";
    showResult('  Empathy', $result['metrics']['empathy']);
    showResult('  Action-Oriented', $result['metrics']['action_oriented']);
    showResult('  Response Length', $result['metrics']['response_length']);
    echo "\n";
}

// Scenario 2: Code Review Assistant
showSection('Scenario 2: Code Review Assistant');

agent('code-reviewer')
    ->provider('mock', [
        'responses' => [
            'function getData() { return $data; }' => 'Issue found: Variable $data is undefined. Consider passing it as a parameter or defining it within the function scope.',
            'while(true) { processItem(); }' => 'Warning: Infinite loop detected. Add a break condition or use a for loop with a limit to prevent potential system hang.',
        ],
    ])
    ->model('mock-model');

$codeReviewDataset = Dataset::fromArray([
    ['input' => 'function getData() { return $data; }', 'expected' => 'undefined variable'],
    ['input' => 'while(true) { processItem(); }', 'expected' => 'infinite loop'],
]);

$codeReviewResult = evaluate('code-reviewer')
    ->dataset($codeReviewDataset)
    ->metric('issue_detection', new KeywordMetric(['issue', 'warning', 'error', 'problem', 'undefined', 'infinite']))
    ->metric('actionable_advice', new KeywordMetric(['consider', 'add', 'use', 'change', 'fix', 'prevent']))
    ->run();

foreach ($codeReviewResult->results as $i => $result) {
    echo 'Code: '.$result['input']."\n";
    echo 'Review: '.substr($result['output'], 0, 80)."...\n";
    showResult('  Issue Detection', $result['metrics']['issue_detection']);
    showResult('  Actionable Advice', $result['metrics']['actionable_advice']);
    echo "\n";
}

// Scenario 3: Content Summarizer
showSection('Scenario 3: Content Summarizer');

$longArticle = 'Artificial intelligence has transformed how we interact with technology. '.
               'From voice assistants to recommendation systems, AI is everywhere. '.
               'Machine learning algorithms analyze vast amounts of data to find patterns. '.
               'Deep learning, a subset of ML, uses neural networks inspired by the human brain. '.
               'The future of AI promises even more advances in automation and decision-making.';

agent('summarizer')
    ->provider('mock', [
        'responses' => [
            $longArticle => 'AI has revolutionized technology through ML and deep learning, with promising future advances.',
        ],
    ])
    ->model('mock-model');

$summaryDataset = Dataset::fromArray([
    ['input' => $longArticle, 'expected' => 'AI technology ML deep learning future'],
]);

$summaryResult = evaluate('summarizer')
    ->dataset($summaryDataset)
    ->metric('brevity', new LengthMetric(minLength: 10, maxLength: 100))
    ->metric('key_concepts', new KeywordMetric(['AI', 'ML', 'deep learning', 'technology', 'future']))
    ->metric('similarity', new SimilarityMetric)
    ->run();

echo 'Original length: '.strlen($longArticle)." characters\n";
echo 'Summary length: '.strlen($summaryResult->results[0]['output'])." characters\n";
echo 'Compression ratio: '.round(strlen($summaryResult->results[0]['output']) / strlen($longArticle) * 100, 1)."%\n\n";

showResult('Brevity Score', $summaryResult->results[0]['metrics']['brevity']);
showResult('Key Concepts', $summaryResult->results[0]['metrics']['key_concepts']);
showResult('Similarity', $summaryResult->results[0]['metrics']['similarity']);

// ==============================================================================
//                              CONCLUSION & SUMMARY
// ==============================================================================

showSection('Summary');

echo "Beginner level\n";
echo "- Create simple test datasets\n";
echo "- Use built-in metrics\n";
echo "- Run basic evaluations\n\n";

echo "Intermediate level\n";
echo "- Load datasets from JSON files\n";
echo "- Build custom metrics with closures\n";
echo "- Generate reports\n";
echo "- Compare prompts\n\n";

echo "Advanced level\n";
echo "- Transform and filter datasets\n";
echo "- Implement complex custom metrics\n";
echo "- Set up regression testing\n";
echo "- Handle application-specific scenarios\n\n";

echo "Next steps:\n";
echo "1. Create a representative dataset for your use case\n";
echo "2. Design metrics that reflect user requirements\n";
echo "3. Add automated evaluation to CI\n";
echo "4. Track metrics over time\n\n";

echo "Resources:\n";
echo "- Tutorial Guide: examples/evaluation-tutorial.md\n";
echo "- Dataset Examples: examples/datasets/\n";
echo "- Generated Reports: examples/reports/\n\n";

echo "Done.\n";
