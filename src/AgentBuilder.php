<?php

declare(strict_types=1);

namespace Pagent;

use InvalidArgumentException;

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

    public function __call(string $method, array $args): self
    {
        $this->agent->{$method}(...$args);
        return $this;
    }

    public function provider(string $providerName, array $config = []): self
    {
        $provider = match ($providerName) {
            'anthropic' => new Providers\Anthropic($config),
            'openai' => new Providers\OpenAI($config),
            'mock' => new Providers\Mock($config),
            default => throw new InvalidArgumentException("Unknown provider: {$providerName}"),
        };

        $this->agent->provider($provider);
        return $this;
    }

    public function build(): Agent
    {
        return $this->agent;
    }
}
