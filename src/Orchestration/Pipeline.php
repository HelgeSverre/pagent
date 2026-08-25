<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

use Closure;
use Pagent\Agent;
use Pagent\Registry;
use Pagent\Workflow\Pipeline as WorkflowPipeline;
use RuntimeException;
use Throwable;

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

    private array $results = [];

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

    public function onError(Closure $handler): self
    {
        $this->errorHandler = $handler;

        return $this;
    }

    public function run(mixed $input): mixed
    {
        $this->results = [];
        $workflow = WorkflowPipeline::create($this->name);

        foreach ($this->stages as $index => $stage) {
            $agentName = is_string($stage['agent']) ? $stage['agent'] : $stage['agent']->getName();

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
                    $response = $agent->prompt($stageInput);

                    $this->results[] = [
                        'stage' => $index,
                        'agent' => $agentName,
                        'input' => $stageInput,
                        'output' => $response->content,
                        'response' => $response,
                    ];

                    return $response;
                },
                $stage['agent'] instanceof Agent ? $stage['agent'] : Registry::get($stage['agent']),
                $agentName,
            );
        }

        try {
            return $workflow->run($input)->final;
        } catch (Throwable $exception) {
            if ($this->errorHandler !== null) {
                return ($this->errorHandler)($exception, count($this->results), $this->failedAgentName());
            }

            $stage = count($this->results);
            $agentName = $this->failedAgentName();

            throw new RuntimeException(
                "Pipeline '{$this->name}' failed at stage {$stage} (agent: {$agentName}): {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getName(): string
    {
        return $this->name;
    }

    private function promptInput(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function failedAgentName(): string
    {
        $stage = $this->stages[count($this->results)] ?? null;

        if ($stage === null) {
            return 'unknown';
        }

        return is_string($stage['agent']) ? $stage['agent'] : $stage['agent']->getName();
    }
}
