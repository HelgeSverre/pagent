<?php

declare(strict_types=1);

namespace Pagent;

use Closure;

/**
 * An in-memory registry for a single application or worker context.
 *
 * The static Registry facade remains for convenience, while this object lets
 * long-lived applications isolate agent definitions per request, tenant, or
 * test without relying on process-wide mutable state.
 */
final class AgentRegistry
{
    /** @var array<string, Agent> */
    private array $agents = [];

    public function set(string $name, Agent $agent): void
    {
        $this->agents[$name] = $agent;
    }

    public function get(string $name): ?Agent
    {
        return $this->agents[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->agents[$name]);
    }

    /**
     * @return array<string, Agent>
     */
    public function all(): array
    {
        return $this->agents;
    }

    /**
     * @param  Closure(string): Agent  $factory
     */
    public function getOrCreate(string $name, Closure $factory): Agent
    {
        return $this->agents[$name] ??= $factory($name);
    }

    public function clear(): void
    {
        $this->agents = [];
    }
}
