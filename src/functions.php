<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\AgentBuilder;
use Pagent\Observability\TelemetryManager;
use Pagent\Registry;

if (! function_exists('agent')) {
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

if (! function_exists('agents')) {
    /**
     * Get all registered agents.
     */
    function agents(): array
    {
        return Registry::all();
    }
}

if (! function_exists('clearAgents')) {
    /**
     * Clear all registered agents.
     */
    function clearAgents(): void
    {
        Registry::clear();
    }
}

if (! function_exists('anthropic')) {
    /**
     * Create an Anthropic provider instance.
     */
    function anthropic(array $config = []): Pagent\Providers\Anthropic
    {
        return new Pagent\Providers\Anthropic($config);
    }
}

if (! function_exists('openai')) {
    /**
     * Create an OpenAI provider instance.
     */
    function openai(array $config = []): Pagent\Providers\OpenAI
    {
        return new Pagent\Providers\OpenAI($config);
    }
}

if (! function_exists('ollama')) {
    /**
     * Create an Ollama provider instance.
     */
    function ollama(array $config = []): Pagent\Providers\Ollama
    {
        return new Pagent\Providers\Ollama($config);
    }
}

if (! function_exists('mock')) {
    /**
     * Create a mock provider instance.
     */
    function mock(array $responses = []): Pagent\Providers\Mock
    {
        return new Pagent\Providers\Mock(['responses' => $responses]);
    }
}

if (! function_exists('evaluate')) {
    /**
     * Create an evaluator for an agent.
     */
    function evaluate(string $agentName): Pagent\Evaluation\Evaluator
    {
        return new Pagent\Evaluation\Evaluator($agentName);
    }
}

if (! function_exists('pipeline')) {
    /**
     * Create a pipeline for sequential agent execution.
     */
    function pipeline(string $name): Pagent\Orchestration\Pipeline
    {
        return new Pagent\Orchestration\Pipeline($name);
    }
}

if (! function_exists('resolveAgent')) {
    /**
     * Resolve an agent from a string name or Agent instance.
     */
    function resolveAgent(string|Agent $agent): Agent|AgentBuilder
    {
        return is_string($agent) ? \agent($agent) : $agent;
    }
}

if (! function_exists('telemetry')) {
    /**
     * Configure OpenTelemetry telemetry with custom settings.
     *
     * @param  array<string, mixed>  $config  Configuration array
     */
    function telemetry(array $config = []): void
    {
        TelemetryManager::instance()->initialize($config);
    }
}

if (! function_exists('telemetry_console')) {
    /**
     * Enable console telemetry for debugging.
     *
     * @param  bool  $verbose  Show detailed span attributes
     */
    function telemetry_console(bool $verbose = false): void
    {
        TelemetryManager::instance()->initialize([
            'enabled' => true,
            'exporter' => 'console',
            'verbose' => $verbose,
        ]);
    }
}

if (! function_exists('telemetry_jaeger')) {
    /**
     * Enable Jaeger telemetry.
     *
     * @param  string  $endpoint  Jaeger OTLP endpoint (default: http://localhost:4318/v1/traces)
     * @param  string  $serviceName  Service name (default: 'pagent')
     */
    function telemetry_jaeger(string $endpoint = 'http://localhost:4318/v1/traces', string $serviceName = 'pagent'): void
    {
        TelemetryManager::instance()->initialize([
            'enabled' => true,
            'exporter' => 'jaeger',
            'service_name' => $serviceName,
            'jaeger' => [
                'endpoint' => $endpoint,
            ],
        ]);
    }
}

if (! function_exists('telemetry_otlp')) {
    /**
     * Enable OTLP telemetry.
     *
     * @param  string  $endpoint  OTLP HTTP endpoint (default: http://localhost:4318/v1/traces)
     * @param  array<string, string>  $headers  Optional headers (e.g., API keys)
     * @param  string  $serviceName  Service name (default: 'pagent')
     */
    function telemetry_otlp(string $endpoint = 'http://localhost:4318/v1/traces', array $headers = [], string $serviceName = 'pagent'): void
    {
        TelemetryManager::instance()->initialize([
            'enabled' => true,
            'exporter' => 'otlp',
            'service_name' => $serviceName,
            'otlp' => [
                'endpoint' => $endpoint,
                'headers' => $headers,
            ],
        ]);
    }
}

if (! function_exists('telemetry_zipkin')) {
    /**
     * Enable Zipkin telemetry.
     *
     * @param  string  $endpoint  Zipkin endpoint (default: http://localhost:9411/api/v2/spans)
     * @param  string  $serviceName  Service name (default: 'pagent')
     */
    function telemetry_zipkin(string $endpoint = 'http://localhost:9411/api/v2/spans', string $serviceName = 'pagent'): void
    {
        TelemetryManager::instance()->initialize([
            'enabled' => true,
            'exporter' => 'zipkin',
            'service_name' => $serviceName,
            'zipkin' => [
                'endpoint' => $endpoint,
            ],
        ]);
    }
}
