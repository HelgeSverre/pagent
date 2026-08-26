<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\AgentBuilder;
use Pagent\AgentRegistry;
use Pagent\Evaluation\Evaluator;
use Pagent\Observability\TelemetryEventBridge;
use Pagent\Observability\TelemetryManager;
use Pagent\Orchestration\Pipeline;
use Pagent\Providers\Anthropic;
use Pagent\Providers\Mock;
use Pagent\Providers\Ollama;
use Pagent\Providers\OpenAI;
use Pagent\Providers\OpenCode;
use Pagent\Registry;
use Pagent\Tools\SearchTool;
use Pagent\Usage\Storage\UsageStorage;
use Pagent\Usage\UsageTracker;

if (! function_exists('agent')) {
    /**
     * Create or retrieve a named agent.
     *
     * The returned Agent is registered immediately, so a named agent can be
     * passed to typed orchestration APIs without a build/destructor phase.
     */
    function agent(string $name): Agent
    {
        return Registry::getOrCreate($name, static fn (string $agentName): Agent => new Agent($agentName));
    }
}

if (! function_exists('defineAgent')) {
    /**
     * Start configuring a named agent and register it immediately.
     *
     * Use build() or register() when handing the Agent to APIs that require an
     * Agent rather than a fluent builder.
     */
    function defineAgent(string $name): AgentBuilder
    {
        return new AgentBuilder(agent($name));
    }
}

if (! function_exists('getAgent')) {
    /**
     * Look up a registered agent without creating one.
     */
    function getAgent(string $name): ?Agent
    {
        return Registry::get($name);
    }
}

if (! function_exists('useAgentRegistry')) {
    /**
     * Set the registry used by the global helper functions.
     */
    function useAgentRegistry(AgentRegistry $registry): AgentRegistry
    {
        return Registry::use($registry);
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
    function anthropic(array $config = []): Anthropic
    {
        return new Anthropic($config);
    }
}

if (! function_exists('openai')) {
    /**
     * Create an OpenAI provider instance.
     */
    function openai(array $config = []): OpenAI
    {
        return new OpenAI($config);
    }
}

if (! function_exists('opencode')) {
    /**
     * Create an OpenCode Zen or Go provider instance.
     */
    function opencode(array $config = []): OpenCode
    {
        return new OpenCode($config);
    }
}

if (! function_exists('ollama')) {
    /**
     * Create an Ollama provider instance.
     */
    function ollama(array $config = []): Ollama
    {
        return new Ollama($config);
    }
}

if (! function_exists('mock')) {
    /**
     * Create a mock provider instance.
     */
    function mock(array $responses = []): Mock
    {
        return new Mock(['responses' => $responses]);
    }
}

if (! function_exists('evaluate')) {
    /**
     * Create an evaluator for an agent.
     */
    function evaluate(string $agentName): Evaluator
    {
        return new Evaluator($agentName);
    }
}

if (! function_exists('workflow')) {
    /**
     * Create a workflow pipeline — the canonical API for sequential
     * agent/transform steps with per-step results and metadata.
     */
    function workflow(string $name): Pagent\Workflow\Pipeline
    {
        return Pagent\Workflow\Pipeline::create($name);
    }
}

if (! function_exists('pipeline')) {
    /**
     * Create a pipeline for sequential agent execution.
     *
     * This returns the legacy orchestration facade for backwards
     * compatibility; prefer workflow() for new code.
     */
    function pipeline(string $name): Pipeline
    {
        return new Pipeline($name);
    }
}

if (! function_exists('resolveAgent')) {
    /**
     * Resolve an agent from a string name or Agent instance.
     */
    function resolveAgent(string|Agent $agent): ?Agent
    {
        if ($agent instanceof Agent) {
            return $agent;
        }

        return getAgent($agent);
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

if (! function_exists('telemetry_bridge')) {
    /**
     * Create and register a TelemetryEventBridge for automatic span creation from events.
     *
     * This bridge listens to agent events and automatically creates OpenTelemetry spans,
     * eliminating the need for manual span creation in your code.
     *
     * @param  array{enabled?: bool, trace_llm?: bool, trace_tools?: bool, trace_memory?: bool, trace_guards?: bool, trace_streams?: bool}  $config  Bridge configuration
     *
     * @example
     * ```php
     * // Enable automatic span creation from events
     * telemetry_bridge();
     *
     * // Now all LLM operations automatically create spans
     * agent('bot')->prompt('Hello');
     * ```
     */
    function telemetry_bridge(array $config = []): TelemetryEventBridge
    {
        return TelemetryEventBridge::global($config);
    }
}

if (! function_exists('usage_tracker')) {
    /**
     * Create and register a UsageTracker for automatic cost and token tracking.
     *
     * This tracker listens to LLM events and automatically records usage and cost data,
     * enabling budget enforcement and usage analytics.
     *
     * @param  array{enabled?: bool, track_llm?: bool, track_streaming?: bool, storage?: UsageStorage, pricing?: array<string, array<string, array{input: float, output: float, cached_input?: float}>>}  $config  Tracker configuration
     *
     * @example
     * ```php
     * use Pagent\Usage\Storage\SqliteUsageStorage;
     *
     * // Enable global usage tracking with SQLite storage
     * usage_tracker([
     *     'storage' => new SqliteUsageStorage(['database' => 'usage.db']),
     * ]);
     *
     * // Now all LLM operations automatically track usage and cost
     * agent('bot')->prompt('Hello');
     *
     * // Query usage
     * $tracker = Pagent\Usage\UsageTracker::global();
     * echo "Total cost: " . $tracker->getTotalCost();
     * ```
     */
    function usage_tracker(array $config = []): UsageTracker
    {
        return UsageTracker::global($config);
    }
}

if (! function_exists('search')) {
    /**
     * Create a SearchTool with flexible configuration.
     *
     * @param  array<int, array<string, mixed>>|null  $documents  Array of documents to index
     * @param  string|null  $indexPath  Path to pre-built index file
     * @param  string|null  $query  SQL query to fetch documents
     * @param  array<string>|null  $paths  File or directory paths to index
     * @param  array<string, mixed>  $config  Additional configuration options
     */
    function search(
        ?array $documents = null,
        ?string $indexPath = null,
        ?string $query = null,
        ?array $paths = null,
        array $config = []
    ): SearchTool {
        /** @var array<string, mixed> $params */
        $params = array_merge([
            'indexPath' => $indexPath,
            'documents' => $documents,
            'query' => $query,
            'paths' => $paths,
        ], $config);

        /** @phpstan-ignore-next-line */
        return new SearchTool(...$params);
    }
}

if (! function_exists('searchIndex')) {
    /**
     * Create a SearchTool for a pre-built index file.
     */
    function searchIndex(string $indexPath, bool $returnContent = false): SearchTool
    {
        return new SearchTool(
            indexPath: $indexPath,
            returnContent: $returnContent
        );
    }
}

if (! function_exists('searchDocuments')) {
    /**
     * Create an in-memory SearchTool for a collection of documents.
     *
     * @param  array<int, array<string, mixed>>  $documents  Array of documents (each must have 'id' field)
     * @param  bool  $returnContent  Whether to return full content or just IDs
     */
    function searchDocuments(array $documents, bool $returnContent = true): SearchTool
    {
        return new SearchTool(
            documents: $documents,
            storage: ':memory:',
            returnContent: $returnContent
        );
    }
}
