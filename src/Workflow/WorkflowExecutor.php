<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Agent;
use Pagent\Contracts\Provider;
use Pagent\Exceptions\RuntimeException;
use Pagent\Observability\NullSpan;
use Pagent\Observability\TelemetryManager;
use Pagent\Response;
use Throwable;

use function date;
use function get_object_vars;
use function is_array;
use function is_numeric;
use function is_object;
use function is_string;
use function json_encode;
use function microtime;
use function property_exists;

/**
 * The single execution path used by the chain and pipeline DSLs.
 *
 * @internal Public workflow classes are the supported entry points.
 */
final class WorkflowExecutor
{
    /**
     * @param  list<WorkflowStep>  $steps
     */
    public static function run(string $name, string $type, array $steps, mixed $input): WorkflowResult
    {
        $startedAt = date('Y-m-d H:i:s');
        $startedAtMicros = microtime(true);
        $current = $input;
        $results = [];
        $totalTokens = 0;
        $telemetryEnabled = self::hasTelemetry($steps);

        $workflowSpan = $telemetryEnabled
            ? TelemetryManager::instance()->startSpan("workflow.{$type}.run", array_merge([
                'workflow.name' => $name,
                'workflow.type' => $type,
                'workflow.steps' => count($steps),
            ], self::valueAttributes('workflow.input', $input)))
            : new NullSpan;

        try {
            foreach ($steps as $index => $step) {
                $stepStartedAt = microtime(true);
                $stepInput = $current;
                $stepSpan = $telemetryEnabled
                    ? TelemetryManager::instance()->startSpan('workflow.step', [
                        'step.name' => $step->name,
                        'step.index' => $index,
                        'step.type' => $step->type,
                    ])
                    : new NullSpan;

                try {
                    [$current, $response] = self::executeStep($step, $stepInput);
                    $tokens = self::extractTokens($response);
                    $duration = microtime(true) - $stepStartedAt;
                    $totalTokens += $tokens;

                    $stepSpan->setAttributes([
                        'step.duration' => $duration * 1000,
                        'step.tokens' => $tokens,
                    ]);
                    $stepSpan->setStatus('ok');

                    $results[] = new StepResult(
                        name: $step->name,
                        output: $current,
                        input: $stepInput,
                        agent: $step->label,
                        meta: StepMetadata::create($tokens, $duration),
                        response: $response,
                    );
                } catch (Throwable $exception) {
                    $stepSpan->recordException($exception);
                    $stepSpan->setStatus('error', $exception->getMessage());

                    throw new WorkflowException(
                        "Workflow '{$name}' failed at step '{$step->name}': {$exception->getMessage()}",
                        partialResults: $results,
                        failedStep: $step->name,
                        previous: $exception,
                    );
                } finally {
                    $stepSpan->end();
                }
            }

            $duration = microtime(true) - $startedAtMicros;
            $workflowSpan->setAttributes(self::workflowAttributes($duration, $totalTokens, true, count($results)));
            $workflowSpan->setAttributes(self::valueAttributes('workflow.output', $current));
            $workflowSpan->setStatus('ok');

            return new WorkflowResult(
                final: $current,
                steps: $results,
                meta: Metadata::create($totalTokens, $duration, count($results), $startedAt, date('Y-m-d H:i:s')),
            );
        } catch (Throwable $exception) {
            $duration = microtime(true) - $startedAtMicros;
            $workflowSpan->recordException($exception);
            $workflowSpan->setStatus('error', $exception->getMessage());
            $workflowSpan->setAttributes(self::workflowAttributes($duration, $totalTokens, false, count($results)));

            // Step failures arrive here already wrapped as WorkflowException
            // (carrying partial results); rethrow as-is.
            throw $exception;
        } finally {
            $workflowSpan->end();
        }
    }

    /**
     * @return array{mixed, ?object}
     */
    private static function executeStep(WorkflowStep $step, mixed $input): array
    {
        if ($step->type === 'transform') {
            $handler = $step->handler;
            if (! $handler instanceof \Closure) {
                throw new RuntimeException("Workflow transform '{$step->name}' has an invalid handler");
            }

            return [$handler($input), null];
        }

        if ($step->type === 'agent') {
            $handler = $step->handler;
            if (! $handler instanceof Agent && ! $handler instanceof Provider) {
                throw new RuntimeException("Workflow agent step '{$step->name}' has an invalid handler");
            }
            $response = $handler->prompt(self::promptInput($input));
        } else {
            $handler = $step->handler;
            if (! $handler instanceof \Closure) {
                throw new RuntimeException("Workflow operation '{$step->name}' has an invalid handler");
            }
            $response = $handler($input);
        }

        if ($response instanceof Response) {
            return [$response->content, $response];
        }

        if (! is_object($response)) {
            return [$response, null];
        }

        if (! property_exists($response, 'content')) {
            throw new RuntimeException("Workflow step '{$step->name}' returned an object without a content property");
        }

        return [$response->content, $response];
    }

    private static function promptInput(mixed $input): string
    {
        if (is_string($input)) {
            return $input;
        }

        return json_encode($input, JSON_THROW_ON_ERROR);
    }

    private static function extractTokens(?object $response): int
    {
        if ($response === null) {
            return 0;
        }

        if ($response instanceof Response) {
            $total = $response->usage['total_tokens'] ?? null;

            return is_numeric($total) ? (int) $total : $response->tokens;
        }

        $responseData = get_object_vars($response);
        $usage = $responseData['usage'] ?? null;

        if (is_array($usage) && isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])) {
            return (int) $usage['total_tokens'];
        }

        if (is_object($usage)) {
            $usageData = get_object_vars($usage);
            foreach (['total_tokens', 'totalTokens'] as $property) {
                if (isset($usageData[$property]) && is_numeric($usageData[$property])) {
                    return (int) $usageData[$property];
                }
            }
        }

        return isset($responseData['tokens']) && is_numeric($responseData['tokens'])
            ? (int) $responseData['tokens']
            : 0;
    }

    /**
     * @param  list<WorkflowStep>  $steps
     */
    private static function hasTelemetry(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->agent?->telemetryEnabled) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int|string> */
    private static function valueAttributes(string $prefix, mixed $value): array
    {
        $serialized = is_string($value)
            ? $value
            : (json_encode($value) ?: get_debug_type($value));
        $attributes = [
            "{$prefix}.type" => get_debug_type($value),
            "{$prefix}.size" => strlen($serialized),
        ];

        $content = TelemetryManager::instance()->contentForSpan($value);
        if ($content !== null) {
            $attributes[$prefix] = $content;
        }

        return $attributes;
    }

    private static function workflowAttributes(
        float $duration,
        int $tokens,
        bool $success,
        int $stepsExecuted,
    ): array {
        return [
            'workflow.duration' => $duration * 1000,
            'workflow.total_tokens' => $tokens,
            'workflow.success' => $success,
            'workflow.steps_executed' => $stepsExecuted,
        ];
    }
}
