<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use Pagent\Contracts\Metric;

final readonly class EvaluationResult
{
    public function __construct(
        public string $agentName,
        public array  $results,
        public array  $metrics,
        public int    $datasetSize,
    ) {}

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
            'metrics' => [],
        ];

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
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
