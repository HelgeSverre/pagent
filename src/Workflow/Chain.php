<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Agent;
use Pagent\Contracts\Provider;

/**
 * Thin alias for {@see Pipeline} with auto-generated step names.
 *
 * @deprecated Use \Pagent\Workflow\Pipeline and name your steps explicitly.
 */
final class Chain
{
    /** @var array<Agent|Provider> */
    private array $steps = [];

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
        $pipeline = Pipeline::create($this->name);

        foreach ($this->steps as $index => $agent) {
            $pipeline->step("step_{$index}", $agent);
        }

        return $pipeline->run($input);
    }
}
