<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Agent;
use Pagent\Contracts\Provider;

final class Pipeline
{
    /** @var list<WorkflowStep> */
    private array $steps = [];

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
        $this->steps[] = WorkflowStep::agent(
            $name,
            $handler,
            $handler instanceof Agent ? $handler->getName() : $name,
        );

        return $this;
    }

    /** @param callable(mixed): mixed $handler */
    public function transform(string $name, callable $handler): self
    {
        $this->steps[] = WorkflowStep::transform($name, $handler);

        return $this;
    }

    /**
     * Add an operation that may return either a value or a provider response.
     * This is primarily useful for adapters that resolve their handler at run time.
     */
    /** @param callable(mixed): mixed $handler */
    public function operation(string $name, callable $handler, ?Agent $telemetryAgent = null, string $label = 'operation'): self
    {
        $this->steps[] = WorkflowStep::operation($name, $handler, $telemetryAgent, $label);

        return $this;
    }

    public function run(mixed $input): WorkflowResult
    {
        return WorkflowExecutor::run($this->name, 'pipeline', $this->steps, $input);
    }
}
