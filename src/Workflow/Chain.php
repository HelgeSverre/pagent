<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Agent;
use Pagent\Contracts\Provider;

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
        /** @var list<WorkflowStep> $steps */
        $steps = [];

        foreach ($this->steps as $index => $agent) {
            $steps[] = WorkflowStep::agent(
                "step_{$index}",
                $agent,
                $agent instanceof Agent ? $agent->getName() : "agent_{$index}",
            );
        }

        return WorkflowExecutor::run($this->name, 'chain', $steps, $input);
    }
}
