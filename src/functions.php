<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\AgentBuilder;
use Pagent\Registry;

if (! \function_exists('agent')) {
    /**
     * Create or retrieve an agent.
     */
    function agent(string $name): Agent|AgentBuilder
    {
        if (Registry::has($name)) {
            return Registry::get($name) ?? new AgentBuilder($name);
        }

        return new AgentBuilder($name);
    }
}

if (! \function_exists('agents')) {
    /**
     * Get all registered agents.
     */
    function agents(): array
    {
        return Registry::all();
    }
}

if (! \function_exists('clearAgents')) {
    /**
     * Clear all registered agents.
     */
    function clearAgents(): void
    {
        Registry::clear();
    }
}

if (! \function_exists('anthropic')) {
    /**
     * Create an Anthropic provider instance.
     */
    function anthropic(array $config = []): Pagent\Providers\Anthropic
    {
        return new Pagent\Providers\Anthropic($config);
    }
}

if (! \function_exists('openai')) {
    /**
     * Create an OpenAI provider instance.
     */
    function openai(array $config = []): Pagent\Providers\OpenAI
    {
        return new Pagent\Providers\OpenAI($config);
    }
}

if (! \function_exists('mock')) {
    /**
     * Create a mock provider instance.
     */
    function mock(array $responses = []): Pagent\Providers\Mock
    {
        return new Pagent\Providers\Mock(['responses' => $responses]);
    }
}

if (! \function_exists('evaluate')) {
    /**
     * Create an evaluator for an agent.
     */
    function evaluate(string $agentName): Pagent\Evaluation\Evaluator
    {
        return new Pagent\Evaluation\Evaluator($agentName);
    }
}

if (! \function_exists('pipeline')) {
    /**
     * Create a pipeline for sequential agent execution.
     */
    function pipeline(string $name): Pagent\Orchestration\Pipeline
    {
        return new Pagent\Orchestration\Pipeline($name);
    }
}

if (! \function_exists('resolveAgent')) {
    /**
     * Resolve an agent from a string name or Agent instance.
     */
    function resolveAgent(string|Agent $agent): Agent|AgentBuilder
    {
        return \is_string($agent) ? \agent($agent) : $agent;
    }
}
