<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

use Closure;
use Pagent\Agent;
use Pagent\Exceptions\RuntimeException;
use Pagent\Registry;
use Pagent\Workflow\Pipeline as WorkflowPipeline;
use Pagent\Workflow\StepResult;
use Pagent\Workflow\WorkflowException;
use Pagent\Workflow\WorkflowResult;
use Throwable;

use function count;
use function is_string;
use function json_encode;
use function resolveAgent;

/**
 * Backwards-compatible facade for the original orchestration API.
 *
 * Execution, metadata, errors, and telemetry are delegated to the workflow
 * pipeline so all workflow entry points share the same runner.
 */
final class Pipeline
{
    /** @var array<int, array{agent: Agent|string, transform: ?Closure}> */
    private array $stages = [];

    private ?Closure $errorHandler = null;

    private ?WorkflowResult $workflowResult = null;

    /** @var list<StepResult> */
    private array $lastSteps = [];

    public function __construct(
        private readonly string $name,
    ) {}

    public function agent(string|Agent $agent, ?Closure $transform = null): self
    {
        $this->stages[] = [
            'agent' => $agent,
            'transform' => $transform,
        ];

        return $this;
    }

    /**
     * Register an error handler invoked as ($exception, $completedStages, $agentName).
     * The handler's return value becomes the result of run() for the failed
     * pipeline; run() does not rethrow when a handler is set.
     */
    public function onError(Closure $handler): self
    {
        $this->errorHandler = $handler;

        return $this;
    }

    public function run(mixed $input): mixed
    {
        $this->workflowResult = null;
        $this->lastSteps = [];
        $workflow = WorkflowPipeline::create($this->name);

        foreach ($this->stages as $index => $stage) {
            $agentName = $this->stageAgentName($index);

            $workflow->operation(
                "stage_{$index}",
                function (mixed $previous) use ($stage, $index, $agentName): object {
                    $agent = resolveAgent($stage['agent']);
                    if ($agent === null) {
                        throw new RuntimeException("Agent '{$agentName}' not found at stage {$index}");
                    }

                    $stageInput = $stage['transform']
                        ? ($stage['transform'])($previous)
                        : $this->promptInput($previous);

                    return $agent->prompt($stageInput);
                },
                $stage['agent'] instanceof Agent ? $stage['agent'] : Registry::get($stage['agent']),
                $agentName,
            );
        }

        try {
            $result = $workflow->run($input);
            $this->workflowResult = $result;
            $this->lastSteps = $result->steps;

            return $result->final;
        } catch (Throwable $exception) {
            $original = $exception;
            if ($exception instanceof WorkflowException) {
                $this->lastSteps = $exception->partialResults;
                $original = $exception->getPrevious() ?? $exception;
            }

            $stage = count($this->lastSteps);
            $agentName = $this->stageAgentName($stage);

            if ($this->errorHandler !== null) {
                return ($this->errorHandler)($original, $stage, $agentName);
            }

            throw new RuntimeException(
                "Pipeline '{$this->name}' failed at stage {$stage} (agent: {$agentName}): {$original->getMessage()}",
                previous: $original,
            );
        }
    }

    /**
     * Per-stage results of the most recent run() (including completed stages
     * of a failed run), derived from the workflow step results.
     */
    public function getResults(): array
    {
        $results = [];

        foreach ($this->lastSteps as $index => $step) {
            $results[] = [
                'stage' => $index,
                'agent' => $step->agent,
                'input' => $step->input,
                'output' => $step->output,
                'response' => $step->response,
            ];
        }

        return $results;
    }

    /**
     * The full WorkflowResult of the last successful run(), or null.
     */
    public function getWorkflowResult(): ?WorkflowResult
    {
        return $this->workflowResult;
    }

    public function getName(): string
    {
        return $this->name;
    }

    private function promptInput(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function stageAgentName(int $index): string
    {
        $stage = $this->stages[$index] ?? null;

        if ($stage === null) {
            return 'unknown';
        }

        return is_string($stage['agent']) ? $stage['agent'] : $stage['agent']->getName();
    }
}
