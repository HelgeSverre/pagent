<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use Pagent\Contracts\Metric;

use function array_column;
use function array_sum;
use function count;
use function json_encode;
use function max;
use function min;

final readonly class EvaluationResult
{
    /**
     * @param  array<int, array{index: int, input: string, error: string}>  $errors  Per-item failures recorded during the run
     * @param  array{agent: string, averages: array<string, float>, deltas: array<string, float>, errors: array<int, array{index: int, input: string, error: string}>}|null  $baseline  Baseline agent averages, deltas (this agent minus baseline), and failures
     */
    public function __construct(
        public string $agentName,
        public array $results,
        public array $metrics,
        public int $datasetSize,
        public array $errors = [],
        public ?array $baseline = null,
    ) {}

    public function getFailureCount(): int
    {
        return count($this->errors);
    }

    public function getAverageScore(string $metricName): float
    {
        $scores = array_column(
            array_column($this->results, 'metrics'),
            $metricName,
        );

        if (empty($scores)) {
            return 0.0;
        }

        return array_sum($scores) / count($scores);
    }

    public function getAllScores(string $metricName): array
    {
        return array_column(
            array_column($this->results, 'metrics'),
            $metricName,
        );
    }

    public function getSummary(): array
    {
        $summary = [
            'agent' => $this->agentName,
            'dataset_size' => $this->datasetSize,
            'failures' => $this->getFailureCount(),
            'metrics' => [],
        ];

        if ($this->baseline !== null) {
            $summary['baseline'] = $this->baseline;
        }

        foreach ($this->metrics as $name => $metric) {
            $scores = $this->getAllScores($name);
            $summary['metrics'][$name] = [
                'average' => $this->getAverageScore($name),
                'min' => ! empty($scores) ? min($scores) : 0.0,
                'max' => ! empty($scores) ? max($scores) : 0.0,
                'description' => $metric instanceof Metric ? $metric->getDescription() : 'Custom metric',
            ];
        }

        return $summary;
    }

    public function toArray(): array
    {
        return [
            'agent' => $this->agentName,
            'dataset_size' => $this->datasetSize,
            'summary' => $this->getSummary(),
            'results' => $this->results,
            'errors' => $this->errors,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
