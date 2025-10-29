<?php

declare(strict_types=1);

namespace Pagent;

use InvalidArgumentException;
use Pagent\Contracts\Provider;

final class AgentBuilder
{
    private Agent $agent;

    public function __construct(string $name)
    {
        $this->agent = new Agent($name);
    }

    public function __destruct()
    {
        Registry::set($this->agent->getName(), $this->agent);
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
        // If already a Provider instance, use it directly
        if ($provider instanceof Provider) {
            $this->agent->provider($provider);

            return $this;
        }

        // String resolution - create instance from name
        $providerInstance = match ($provider) {
            'anthropic' => new Providers\Anthropic($config),
            'openai' => new Providers\OpenAI($config),
            'ollama' => new Providers\Ollama($config),
            'mock' => new Providers\Mock($config),
            default => throw new InvalidArgumentException("Unknown provider: {$provider}"),
        };

        $this->agent->provider($providerInstance);

        return $this;
    }

    public function build(): Agent
    {
        return $this->agent;
    }
}
