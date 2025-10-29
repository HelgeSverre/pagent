<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Agent;
use Pagent\Contracts\Provider;
use Pagent\Observability\NullSpan;
use Pagent\Observability\Span;
use Pagent\Observability\TelemetryManager;
use Throwable;

use function microtime;

final class Pipeline
{
    /** @var array<array{name: string, handler: Agent|Provider|callable, type: string}> */
    protected array $steps = [];

    private string $name = 'unnamed-pipeline';

    public static function create(string $name = 'unnamed-pipeline'): self
    {
        $instance = new self;
        $instance->name = $name;

        return $instance;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
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
        $success = true;

        // Start workflow span if any agent has telemetry enabled
        $workflowSpan = $this->shouldEnableTelemetry()
            ? TelemetryManager::instance()->startSpan('workflow.pipeline', [
                'workflow.name' => $this->name,
                'workflow.type' => 'pipeline',
                'workflow.steps' => count($this->steps),
            ])
            : new NullSpan;

        try {
            foreach ($this->steps as $index => $step) {
                $stepStartTime = microtime(true);

                // Start step span
                $stepSpan = $this->shouldEnableTelemetry()
                    ? TelemetryManager::instance()->startSpan('workflow.step', [
                        'step.name' => $step['name'],
                        'step.index' => $index,
                        'step.type' => $step['type'],
                    ])
                    : new NullSpan;

                try {
                    if ($step['type'] === 'agent') {
                        $response = $step['handler']->prompt($current);
                        $current = $response->content;
                        $stepTokens = $response->usage?->total_tokens ?? $response->tokens ?? 0;
                    } else {
                        // Transform step
                        $current = $step['handler']($current);
                        $stepTokens = 0;
                    }

                    $stepDuration = (microtime(true) - $stepStartTime) * 1000; // Convert to milliseconds
                    $totalTokens += $stepTokens;

                    // Record step attributes
                    $stepSpan->setAttributes([
                        'step.duration' => $stepDuration,
                        'step.tokens' => $stepTokens,
                    ]);

                    $stepSpan->setStatus('ok');

                    $stepResults[] = new StepResult(
                        name: $step['name'],
                        output: $current,
                        input: $step['type'] === 'agent' ? ($stepResults[count($stepResults) - 1]->output ?? $input) : $current,
                        agent: $step['type'] === 'agent' ? ($step['handler']->name ?? $step['name']) : 'transform',
                        meta: StepMetadata::create(
                            tokens: $stepTokens,
                            duration: $stepDuration / 1000 // Convert back to seconds for StepMetadata
                        )
                    );
                } catch (Throwable $e) {
                    $success = false;
                    $stepSpan->recordException($e);
                    $stepSpan->setStatus('error', $e->getMessage());

                    throw $e;
                } finally {
                    $stepSpan->end();
                }
            }

            $totalDuration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

            // Set workflow attributes
            $workflowSpan->setAttributes([
                'workflow.duration' => $totalDuration,
                'workflow.total_tokens' => $totalTokens,
                'workflow.success' => $success,
                'workflow.steps_completed' => count($stepResults),
            ]);

            $workflowSpan->setStatus('ok');

            return new WorkflowResult(
                final: $current,
                steps: $stepResults,
                meta: Metadata::create(
                    totalTokens: $totalTokens,
                    duration: $totalDuration / 1000, // Convert back to seconds for Metadata
                    stepsExecuted: count($stepResults)
                )
            );
        } catch (Throwable $e) {
            $workflowSpan->recordException($e);
            $workflowSpan->setStatus('error', $e->getMessage());

            // Set workflow attributes before throwing
            $totalDuration = (microtime(true) - $startTime) * 1000;
            $workflowSpan->setAttributes([
                'workflow.duration' => $totalDuration,
                'workflow.total_tokens' => $totalTokens,
                'workflow.success' => false,
                'workflow.steps_completed' => count($stepResults),
            ]);

            throw $e;
        } finally {
            $workflowSpan->end();
        }
    }

    private function shouldEnableTelemetry(): bool
    {
        // Check if any step has an agent with telemetry enabled
        foreach ($this->steps as $step) {
            if ($step['handler'] instanceof Agent && $step['handler']->telemetryEnabled) {
                return true;
            }
        }

        return false;
    }
}
