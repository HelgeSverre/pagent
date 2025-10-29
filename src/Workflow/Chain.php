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

final class Chain
{
    /** @var array<Agent|Provider> */
    protected array $steps = [];

    private string $name = 'unnamed-chain';

    public static function create(string $name = 'unnamed-chain'): self
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
        $success = true;

        // Start workflow span if any agent has telemetry enabled
        $workflowSpan = $this->shouldEnableTelemetry()
            ? TelemetryManager::instance()->startSpan('workflow.chain', [
                'workflow.name' => $this->name,
                'workflow.type' => 'chain',
                'workflow.steps' => count($this->steps),
            ])
            : new NullSpan;

        try {
            foreach ($this->steps as $index => $agent) {
                $stepStartTime = microtime(true);
                $stepName = "step_{$index}";

                // Start step span
                $stepSpan = $this->shouldEnableTelemetry()
                    ? TelemetryManager::instance()->startSpan('workflow.step', [
                        'step.name' => $stepName,
                        'step.index' => $index,
                        'step.type' => 'agent',
                    ])
                    : new NullSpan;

                try {
                    $response = $agent->prompt($current);

                    $stepDuration = (microtime(true) - $stepStartTime) * 1000; // Convert to milliseconds
                    $stepTokens = $response->usage?->total_tokens ?? 0;
                    $totalTokens += $stepTokens;

                    // Record step attributes
                    $stepSpan->setAttributes([
                        'step.duration' => $stepDuration,
                        'step.tokens' => $stepTokens,
                    ]);

                    $stepSpan->setStatus('ok');

                    $stepResults[] = new StepResult(
                        name: $stepName,
                        output: $response->content,
                        input: $current,
                        agent: $agent->name ?? "agent_{$index}",
                        meta: StepMetadata::create(
                            tokens: $stepTokens,
                            duration: $stepDuration / 1000 // Convert back to seconds for StepMetadata
                        )
                    );

                    $current = $response->content;
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
        // Check if any agent has telemetry enabled
        foreach ($this->steps as $agent) {
            if ($agent instanceof Agent && $agent->telemetryEnabled) {
                return true;
            }
        }

        return false;
    }
}
