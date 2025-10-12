<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use Pagent\Agent;
use Pagent\Contracts\Metric;
use RuntimeException;

use function agent;
use function is_callable;
use function is_string;
use function pathinfo;

final class Evaluator
{
    private Agent $agent;

    private Dataset $dataset;

    /** @var Metric[] */
    private array $metrics = [];

    private ?string $baselineAgentName = null;

    public function __construct(private readonly string $agentName) {}

    public function dataset(string|Dataset $dataset): self
    {
        if (is_string($dataset)) {
            $this->dataset = $this->loadDataset($dataset);
        } else {
            $this->dataset = $dataset;
        }

        return $this;
    }

    public function metric(string $name, Metric|callable $metric): self
    {
        if ($metric instanceof Metric) {
            $this->metrics[$name] = $metric;
        } elseif (is_callable($metric)) {
            $this->metrics[$name] = new class($name, $metric) implements Metric
            {
                public function __construct(
                    private readonly string $name,
                    private readonly mixed $callable,
                ) {}

                public function calculate(string $input, string $output, mixed $expected = null): float
                {
                    return (float) ($this->callable)($input, $output, $expected);
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function getDescription(): string
                {
                    return "Custom metric: {$this->name}";
                }
            };
        }

        return $this;
    }

    public function baseline(string $agentName): self
    {
        $this->baselineAgentName = $agentName;

        return $this;
    }

    public function run(): EvaluationResult
    {
        $this->agent = agent($this->agentName);

        if (! $this->agent instanceof Agent) {
            throw new RuntimeException("Agent '{$this->agentName}' not found");
        }

        if (! isset($this->dataset)) {
            throw new RuntimeException('No dataset provided');
        }

        $results = [];

        foreach ($this->dataset->items() as $item) {
            $input = $item['input'];
            $expected = $item['expected'] ?? null;

            $response = $this->agent->prompt($input);

            $metricScores = [];
            foreach ($this->metrics as $name => $metric) {
                $metricScores[$name] = $metric->calculate($input, $response->content, $expected);
            }

            $results[] = [
                'input' => $input,
                'output' => $response->content,
                'expected' => $expected,
                'metrics' => $metricScores,
                'metadata' => $item['metadata'] ?? [],
            ];
        }

        return new EvaluationResult(
            agentName: $this->agentName,
            results: $results,
            metrics: $this->metrics,
            datasetSize: $this->dataset->count(),
        );
    }

    private function loadDataset(string $path): Dataset
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return match ($extension) {
            'json' => Dataset::fromJson($path),
            'csv' => Dataset::fromCsv($path),
            default => throw new RuntimeException("Unsupported dataset format: {$extension}"),
        };
    }
}
