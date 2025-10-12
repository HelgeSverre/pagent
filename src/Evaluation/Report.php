<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use RuntimeException;

use function file_put_contents;
use function pathinfo;
use function round;

final class Report
{
    public function __construct(
        private readonly EvaluationResult $result,
    ) {}

    public function toHtml(): string
    {
        $summary = $this->result->getSummary();

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Evaluation Report: {$summary['agent']}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        h1 { color: #1a202c; }
        .summary { background: #f7fafc; padding: 1.5rem; border-radius: 0.5rem; margin: 1rem 0; }
        .metric { background: #edf2f7; padding: 1rem; margin: 0.5rem 0; border-radius: 0.375rem; }
        .metric h3 { margin: 0 0 0.5rem 0; color: #2d3748; }
        .score { font-size: 2rem; font-weight: bold; color: #2b6cb0; }
        .result { border: 1px solid #e2e8f0; padding: 1rem; margin: 1rem 0; border-radius: 0.375rem; }
        .input { color: #4a5568; font-weight: 600; }
        .output { color: #2d3748; margin: 0.5rem 0; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem; margin-top: 0.5rem; }
        .metric-badge { background: #bee3f8; padding: 0.25rem 0.75rem; border-radius: 0.25rem; font-size: 0.875rem; }
    </style>
</head>
<body>
    <h1>📊 Evaluation Report</h1>
    <div class="summary">
        <h2>Agent: {$summary['agent']}</h2>
        <p><strong>Dataset Size:</strong> {$summary['dataset_size']} items</p>
    </div>

    <h2>Metrics Summary</h2>
HTML;

        foreach ($summary['metrics'] as $name => $data) {
            $percentage = round($data['average'] * 100, 1);
            $html .= <<<HTML
    <div class="metric">
        <h3>{$name}</h3>
        <div class="score">{$percentage}%</div>
        <p>{$data['description']}</p>
        <small>Min: {$data['min']} | Max: {$data['max']} | Avg: {$data['average']}</small>
    </div>
HTML;
        }

        $html .= "\n    <h2>Detailed Results</h2>\n";

        foreach ($this->result->results as $i => $result) {
            $num = $i + 1;
            $html .= <<<HTML
    <div class="result">
        <div class="input"><strong>Input #{$num}:</strong> {$result['input']}</div>
        <div class="output"><strong>Output:</strong> {$result['output']}</div>
HTML;

            if (isset($result['expected'])) {
                $html .= "        <div><strong>Expected:</strong> {$result['expected']}</div>\n";
            }

            $html .= '        <div class="metrics">';
            foreach ($result['metrics'] as $name => $score) {
                $percentage = round($score * 100, 1);
                $html .= "<span class=\"metric-badge\">{$name}: {$percentage}%</span>";
            }
            $html .= "</div>\n    </div>\n";
        }

        $html .= "</body>\n</html>";

        return $html;
    }

    public function toMarkdown(): string
    {
        $summary = $this->result->getSummary();
        $md = "# Evaluation Report: {$summary['agent']}\n\n";
        $md .= "**Dataset Size**: {$summary['dataset_size']} items\n\n";
        $md .= "## Metrics Summary\n\n";

        foreach ($summary['metrics'] as $name => $data) {
            $percentage = round($data['average'] * 100, 1);
            $md .= "### {$name}: {$percentage}%\n";
            $md .= "{$data['description']}\n";
            $md .= "- Average: {$data['average']}\n";
            $md .= "- Min: {$data['min']}\n";
            $md .= "- Max: {$data['max']}\n\n";
        }

        $md .= "## Detailed Results\n\n";

        foreach ($this->result->results as $i => $result) {
            $num = $i + 1;
            $md .= "### Result #{$num}\n";
            $md .= "**Input**: {$result['input']}\n\n";
            $md .= "**Output**: {$result['output']}\n\n";

            if (isset($result['expected'])) {
                $md .= "**Expected**: {$result['expected']}\n\n";
            }

            $md .= "**Metrics**:\n";
            foreach ($result['metrics'] as $name => $score) {
                $percentage = round($score * 100, 1);
                $md .= "- {$name}: {$percentage}%\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    public function save(string $path): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $content = match ($extension) {
            'html' => $this->toHtml(),
            'md', 'markdown' => $this->toMarkdown(),
            'json' => $this->result->toJson(),
            default => throw new RuntimeException("Unsupported export format: {$extension}"),
        };

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Failed to write report to: {$path}");
        }
    }
}
