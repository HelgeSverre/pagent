<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Agent;
use Pagent\Contracts\Provider;

final class Chain
{
    /** @var array<Agent|Provider> */
    protected array $steps = [];

    public static function create(): self
    {
        return new self();
    }

    public function add(Agent|Provider $agent): self
    {
        $this->steps[] = $agent;

        return $this;
    }

    public function run(mixed $input): WorkflowResult
    {
        $startTime = microtime(true);
        $current = $input;
        $stepResults = [];
        $totalTokens = 0;

        foreach ($this->steps as $index => $agent) {
            $stepStartTime = microtime(true);

            $response = $agent->prompt($current);

            $stepDuration = microtime(true) - $stepStartTime;
            $stepTokens = $response->usage?->total_tokens ?? 0;
            $totalTokens += $stepTokens;

            $stepResults[] = new StepResult(
                name: "step_{$index}",
                output: $response->content,
                input: $current,
                agent: $agent->name ?? "agent_{$index}",
                meta: StepMetadata::create(
                    tokens: $stepTokens,
                    duration: $stepDuration
                )
            );

            $current = $response->content;
        }

        $totalDuration = microtime(true) - $startTime;

        return new WorkflowResult(
            final: $current,
            steps: $stepResults,
            meta: Metadata::create(
                totalTokens: $totalTokens,
                duration: $totalDuration,
                stepsExecuted: count($stepResults)
            )
        );
    }
}
