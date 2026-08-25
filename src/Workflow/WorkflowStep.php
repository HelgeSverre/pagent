<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Closure;
use Pagent\Agent;
use Pagent\Contracts\Provider;

/**
 * Internal normalized representation of a workflow step.
 *
 * @internal Use Chain or Pipeline to construct workflows.
 */
final readonly class WorkflowStep
{
    /** @param Agent|Provider|Closure(mixed): mixed $handler */
    private function __construct(
        public string $name,
        public string $type,
        public Agent|Provider|Closure $handler,
        public ?Agent $agent,
        public string $label,
    ) {}

    public static function agent(string $name, Agent|Provider $handler, string $label): self
    {
        return new self(
            name: $name,
            type: 'agent',
            handler: $handler,
            agent: $handler instanceof Agent ? $handler : null,
            label: $label,
        );
    }

    /** @param callable(mixed): mixed $handler */
    public static function transform(string $name, callable $handler): self
    {
        return new self(
            name: $name,
            type: 'transform',
            handler: Closure::fromCallable($handler),
            agent: null,
            label: 'transform',
        );
    }

    /** @param callable(mixed): mixed $handler */
    public static function operation(string $name, callable $handler, ?Agent $telemetryAgent, string $label): self
    {
        return new self(
            name: $name,
            type: 'operation',
            handler: Closure::fromCallable($handler),
            agent: $telemetryAgent,
            label: $label,
        );
    }
}
