<?php

declare(strict_types=1);

namespace Pagent\Workflow;

final readonly class WorkflowResult
{
    /**
     * @param  array<StepResult>  $steps
     */
    public function __construct(
        public mixed $final,
        public array $steps,
        public Metadata $meta,
    ) {}

    public function step(string $name): ?StepResult
    {
        foreach ($this->steps as $step) {
            if ($step->name === $name) {
                return $step;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->step($name) !== null;
    }

    public function toArray(): array
    {
        return [
            'final' => $this->final,
            'steps' => array_map(fn (StepResult $step) => $step->toArray(), $this->steps),
            'metadata' => $this->meta->toArray(),
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (is_string($this->final)) {
            $decoded = json_decode($this->final, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded[$key] ?? $default;
            }
        }

        if (is_array($this->final)) {
            return $this->final[$key] ?? $default;
        }

        return $default;
    }

    public function export(): array
    {
        return $this->toArray();
    }
}
