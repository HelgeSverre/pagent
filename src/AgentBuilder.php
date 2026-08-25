<?php

declare(strict_types=1);

namespace Pagent;

use Pagent\Contracts\Provider;

final class AgentBuilder
{
    private Agent $agent;

    public function __construct(string|Agent $agent)
    {
        $this->agent = $agent instanceof Agent ? $agent : new Agent($agent);
    }

    public function __call(string $method, array $args): mixed
    {
        $result = $this->agent->{$method}(...$args);

        return $result instanceof Agent ? $this : $result;
    }

    /**
     * Set the provider for this agent.
     *
     * Accepts either a provider name (string) with optional config,
     * or a Provider instance (when custom configuration is needed).
     *
     * @param  string|Provider  $provider  Provider name or instance
     * @param  array  $config  Configuration (only used with string names)
     */
    public function provider(string|Provider $provider, array $config = []): self
    {
        $this->agent->provider(ProviderFactory::resolve($provider, $config));

        return $this;
    }

    public function build(): Agent
    {
        Registry::set($this->agent->getName(), $this->agent);

        return $this->agent;
    }

    /**
     * Explicit alias for build() for configuration code that wants to make
     * registration visible at the call site.
     */
    public function register(): Agent
    {
        return $this->build();
    }
}
