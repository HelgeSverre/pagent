<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Pagent\Evaluation\Dataset;
use Pagent\Evaluation\Metrics\KeywordMetric;
use Pagent\Evaluation\Metrics\LengthMetric;
use Pagent\Evaluation\Report;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "📊 Pagent Evaluation Framework Demo\n";
echo "====================================\n\n";

echo "=== Example 1: Simple Evaluation ===\n\n";

agent('support-bot')
    ->provider('openai')
    ->model('gpt-3.5-turbo')
    ->system('You are a helpful customer support agent. Be concise and professional.');

$dataset = Dataset::fromArray([
    ['input' => 'My order never arrived', 'expected' => 'track order status'],
    ['input' => 'I want a refund', 'expected' => 'refund process'],
    ['input' => 'How do I reset my password?', 'expected' => 'password reset'],
]);

echo "Evaluating support-bot on {$dataset->count()} test cases...\n\n";

$result = evaluate('support-bot')
    ->dataset($dataset)
    ->metric('keywords', new KeywordMetric(['order', 'refund', 'password', 'help']))
    ->metric('length', new LengthMetric(minLength: 20, maxLength: 200))
    ->run();

echo "Results:\n";
foreach ($result->getSummary()['metrics'] as $name => $data) {
    $percentage = round($data['average'] * 100, 1);
    echo "  {$name}: {$percentage}% (avg: {$data['average']})\n";
}

echo "\n";

echo "=== Example 2: Detailed Results ===\n\n";

foreach ($result->results as $i => $item) {
    echo "Test Case #" . ($i + 1) . ":\n";
    echo "  Input: {$item['input']}\n";
    echo "  Output: " . mb_substr($item['output'], 0, 80) . "...\n";
    echo "  Scores: ";
    foreach ($item['metrics'] as $name => $score) {
        $pct = round($score * 100);
        echo "{$name}={$pct}% ";
    }
    echo "\n\n";
}

echo "=== Example 3: Loading from JSON File ===\n\n";

$fileDataset = Dataset::fromJson(__DIR__ . '/datasets/support_tickets.json');
echo "Loaded {$fileDataset->count()} test cases from JSON\n\n";

$result2 = evaluate('support-bot')
    ->dataset($fileDataset)
    ->metric('response_quality', new KeywordMetric(['help', 'assist', 'sorry', 'order', 'refund']))
    ->metric('conciseness', new LengthMetric(minLength: 10, maxLength: 150))
    ->run();

echo "Results for file-based dataset:\n";
$summary = $result2->getSummary();
foreach ($summary['metrics'] as $name => $data) {
    echo "  {$name}: " . round($data['average'] * 100, 1) . "%\n";
}

echo "\n";

echo "=== Example 4: Custom Metric with Closure ===\n\n";

$result3 = evaluate('support-bot')
    ->dataset(Dataset::fromArray([
        ['input' => 'Help me!', 'expected' => 'I can help'],
    ]))
    ->metric('politeness', function ($input, $output, $expected): float {
        $politeWords = ['please', 'thank', 'sorry', 'happy', 'glad'];
        $found = 0;
        foreach ($politeWords as $word) {
            if (str_contains(mb_strtolower($output), $word)) {
                $found++;
            }
        }

        return min(1.0, $found / 2);
    })
    ->run();

echo "Politeness score: " . round($result3->getAverageScore('politeness') * 100, 1) . "%\n\n";

echo "=== Example 5: Export Reports ===\n\n";

agent('report-test')
    ->provider('openai')
    ->system('You are a helpful assistant. Answer in one sentence.');

$reportDataset = Dataset::fromArray([
    ['input' => 'What is PHP?', 'expected' => 'programming language'],
]);

$reportResult = evaluate('report-test')
    ->dataset($reportDataset)
    ->metric('keywords', new KeywordMetric(['PHP', 'programming', 'language']))
    ->metric('length', new LengthMetric(minLength: 10, maxLength: 100))
    ->run();

// Generate reports
$report = new Report($reportResult);

// Save as JSON
$report->save(__DIR__ . '/reports/evaluation.json');
echo "✅ Saved JSON report to examples/reports/evaluation.json\n";

// Save as Markdown
$report->save(__DIR__ . '/reports/evaluation.md');
echo "✅ Saved Markdown report to examples/reports/evaluation.md\n";

// Save as HTML
$report->save(__DIR__ . '/reports/evaluation.html');
echo "✅ Saved HTML report to examples/reports/evaluation.html\n";

echo "\n";

echo "=== Example 6: Evaluation Summary ===\n\n";

echo "Full Summary:\n";
print_r($reportResult->getSummary());

echo "\n✅ All evaluation examples completed!\n";
echo "\n📊 Evaluation framework is ready to measure agent quality!\n";
