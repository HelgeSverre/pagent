<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Agent;
use Pagent\Contracts\Provider;

final class Pipeline
{
    /** @var array<array{name: string, handler: Agent|Provider|callable, type: string}> */
    protected array $steps = [];

    public static function create(): self
    {
        return new self;
    }

    public function step(string $name, Agent|Provider $handler): self
    {
        $this->steps[] = [
            'name' => $name,
            'handler' => $handler,
            'type' => 'agent',
        ];

        return $this;
    }

    public function transform(string $name, callable $fn): self
    {
        $this->steps[] = [
            'name' => $name,
            'handler' => $fn,
            'type' => 'transform',
        ];

        return $this;
    }

    public function run(mixed $input): WorkflowResult
    {
        $startTime = microtime(true);
        $current = $input;
        $stepResults = [];
        $totalTokens = 0;

        foreach ($this->steps as $step) {
            $stepStartTime = microtime(true);

            if ($step['type'] === 'agent') {
                $response = $step['handler']->prompt($current);
                $current = $response->content;
                $stepTokens = $response->usage?->total_tokens ?? $response->tokens ?? 0;
            } else {
                // Transform step
                $current = $step['handler']($current);
                $stepTokens = 0;
            }

            $stepDuration = microtime(true) - $stepStartTime;
            $totalTokens += $stepTokens;

            $stepResults[] = new StepResult(
                name: $step['name'],
                output: $current,
                input: $step['type'] === 'agent' ? ($stepResults[count($stepResults) - 1]->output ?? $input) : $current,
                agent: $step['type'] === 'agent' ? ($step['handler']->name ?? $step['name']) : 'transform',
                meta: StepMetadata::create(
                    tokens: $stepTokens,
                    duration: $stepDuration
                )
            );
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
