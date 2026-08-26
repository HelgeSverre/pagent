<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use Pagent\Agent;
use Pagent\Contracts\Metric;
use Pagent\Exceptions\RuntimeException;
use Throwable;

use function array_column;
use function array_keys;
use function array_map;
use function array_sum;
use function count;
use function is_callable;
use function is_string;
use function pathinfo;
use function resolveAgent;

final class Evaluator
{
    private Agent $agent;

    private Dataset $dataset;

    /** @var Metric[] */
    private array $metrics = [];

    private ?string $baselineAgentName = null;

    private bool $stateful = false;

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

    /**
     * Reuse one isolated conversation across dataset rows. By default every
     * row gets a fresh conversation so ordering cannot contaminate scores.
     */
    public function stateful(bool $stateful = true): self
    {
        $this->stateful = $stateful;

        return $this;
    }

    public function run(): EvaluationResult
    {
        $agent = resolveAgent($this->agentName);

        if ($agent === null) {
            throw new RuntimeException("Agent '{$this->agentName}' not found");
        }

        $this->agent = $agent;

        if (! isset($this->dataset)) {
            throw new RuntimeException('No dataset provided');
        }

        [$results, $errors] = $this->evaluateAgent($agent);

        $baseline = null;

        if ($this->baselineAgentName !== null) {
            $baselineAgent = resolveAgent($this->baselineAgentName);

            if ($baselineAgent === null) {
                throw new RuntimeException("Baseline agent '{$this->baselineAgentName}' not found");
            }

            [$baselineResults, $baselineErrors] = $this->evaluateAgent($baselineAgent);

            $averages = [];
            $deltas = [];
            foreach (array_keys($this->metrics) as $name) {
                $averages[$name] = self::average($baselineResults, $name);
                $deltas[$name] = self::average($results, $name) - $averages[$name];
            }

            $baseline = [
                'agent' => $this->baselineAgentName,
                'averages' => $averages,
                'deltas' => $deltas,
                'errors' => $baselineErrors,
            ];
        }

        return new EvaluationResult(
            agentName: $this->agentName,
            results: $results,
            metrics: $this->metrics,
            datasetSize: $this->dataset->count(),
            errors: $errors,
            baseline: $baseline,
        );
    }

    /**
     * Run every dataset item through the agent, recording per-item failures
     * (rate limits, provider errors) instead of aborting the whole run.
     *
     * @return array{array<int, array<string, mixed>>, array<int, array{index: int, input: string, error: string}>}
     */
    private function evaluateAgent(Agent $agent): array
    {
        $results = [];
        $errors = [];
        $statefulAgent = $this->stateful ? $agent->clone($agent->getName()) : null;

        foreach ($this->dataset->items() as $index => $item) {
            $input = $item['input'];
            $expected = $item['expected'] ?? null;
            $evaluationAgent = $statefulAgent ?? $agent->clone($agent->getName());

            try {
                $response = $evaluationAgent->prompt($input);

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
            } catch (Throwable $exception) {
                $errors[] = [
                    'index' => $index,
                    'input' => $input,
                    'error' => $exception->getMessage(),
                ];

                $results[] = [
                    'input' => $input,
                    'output' => null,
                    'expected' => $expected,
                    'metrics' => array_map(static fn (): float => 0.0, $this->metrics),
                    'metadata' => $item['metadata'] ?? [],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [$results, $errors];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private static function average(array $results, string $metricName): float
    {
        $scores = array_column(array_column($results, 'metrics'), $metricName);

        return $scores === [] ? 0.0 : array_sum($scores) / count($scores);
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
