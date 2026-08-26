<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use Pagent\Exceptions\RuntimeException;

use function file_put_contents;
use function pathinfo;
use function round;

final class Report
{
    /**
     * @param  string|null  $title  HTML report title; defaults to "{agent} - Evaluation Report"
     */
    public function __construct(
        private readonly EvaluationResult $result,
        private readonly ?string $title = null,
    ) {}

    private function renderTemplate(string $template, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include __DIR__.'/Templates/'.$template;

        return ob_get_clean();
    }

    private function renderLayout(string $content, string $title): string
    {
        return $this->renderTemplate('layout.html.php', [
            'title' => $title,
            'content' => $content,
        ]);
    }

    public function toHtml(): string
    {
        $summary = $this->result->getSummary();

        // Render report content
        $content = $this->renderTemplate('evaluation-report.html.php', [
            'agentName' => $summary['agent'],
            'datasetSize' => $summary['dataset_size'],
            'metrics' => $summary['metrics'],
            'results' => $this->result->results,
        ]);

        $title = $this->title ?? "{$summary['agent']} - Evaluation Report";

        return $this->renderLayout($content, $title);
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
