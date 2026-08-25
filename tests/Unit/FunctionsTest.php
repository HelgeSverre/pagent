<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\AgentBuilder;
use Pagent\AgentRegistry;
use Pagent\Observability\TelemetryManager;
use Pagent\Providers\Anthropic;
use Pagent\Providers\Mock;
use Pagent\Providers\OpenAI;
use Pagent\Providers\OpenCode;
use Pagent\Registry;

beforeEach(function (): void {
    TelemetryManager::reset();
    clearAgents();
});

afterEach(function (): void {
    TelemetryManager::reset();
});

it('creates and immediately registers agents with agent() function', function (): void {
    $result = \agent('new-agent');

    expect($result)->toBeInstanceOf(Agent::class)
        ->and(getAgent('new-agent'))->toBe($result);
});

it('retrieves existing agents with agent() function', function (): void {
    // First create and configure an agent
    \agent('existing')
        ->provider('mock')
        ->system('Test agent');

    // Now retrieve it
    $agent = \agent('existing');

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent->getName())->toBe('existing');
});

it('returns the same named agent deterministically', function (): void {
    $first = \agent('same-agent');
    $second = \agent('same-agent');

    expect($first)->toBe($second)
        ->and(agents())->toHaveCount(1);
});

it('defines a fluent builder around an immediately registered agent', function (): void {
    $builder = defineAgent('defined-agent')->provider('mock');

    expect($builder)->toBeInstanceOf(AgentBuilder::class)
        ->and(getAgent('defined-agent'))->toBeInstanceOf(Agent::class)
        ->and($builder->build())->toBe(getAgent('defined-agent'));
});

it('does not create an agent when resolving a missing name', function (): void {
    expect(getAgent('missing-agent'))->toBeNull();
    expect(resolveAgent('missing-agent'))->toBeNull();
    expect(getAgent('missing-agent'))->toBeNull();
});

it('isolates agent definitions in a scoped registry', function (): void {
    $default = \agent('default-agent');
    $scoped = new AgentRegistry;

    Registry::scoped($scoped, function (): void {
        $isolated = \agent('isolated-agent');

        expect($isolated)->toBe(getAgent('isolated-agent'))
            ->and(getAgent('default-agent'))->toBeNull();
    });

    expect(getAgent('default-agent'))->toBe($default)
        ->and(getAgent('isolated-agent'))->toBeNull();
});

it('can replace and restore the registry used by helper functions', function (): void {
    $isolated = new AgentRegistry;
    $previous = useAgentRegistry($isolated);

    try {
        $agent = \agent('injected-agent');

        expect($isolated->get('injected-agent'))->toBe($agent);
    } finally {
        useAgentRegistry($previous);
    }

    expect(getAgent('injected-agent'))->toBeNull();
});

it('returns all agents with agents() function', function (): void {
    clearAgents(); // Start fresh

    \agent('agent-1')->provider('mock');
    \agent('agent-2')->provider('mock');

    $all = agents();

    expect($all)->toBeArray();
    expect($all)->toHaveCount(2);
    expect($all)->toHaveKeys(['agent-1', 'agent-2']);
});

it('clears agents with clearAgents() function', function (): void {
    clearAgents(); // Start fresh

    \agent('temp-1')->provider('mock');
    \agent('temp-2')->provider('mock');

    expect(agents())->toHaveCount(2);

    clearAgents();

    expect(agents())->toBeEmpty();
});

it('creates anthropic provider with helper function', function (): void {
    $provider = anthropic(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(Anthropic::class);
});

it('creates openai provider with helper function', function (): void {
    $provider = openai(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(OpenAI::class);
});

it('creates opencode provider with helper function', function (): void {
    $provider = opencode(['api_key' => 'test-key', 'gateway' => 'go']);

    expect($provider)->toBeInstanceOf(OpenCode::class);
});

it('creates mock provider with helper function', function (): void {
    $provider = mock(['test' => 'response']);

    expect($provider)->toBeInstanceOf(Mock::class);

    $response = $provider->prompt('test');
    expect($response->content)->toBe('response');
});

it('telemetry_console function initializes console exporter', function (): void {
    TelemetryManager::instance()->clearContext();

    telemetry_console(verbose: true);

    expect(TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry_jaeger function initializes jaeger exporter', function (): void {
    TelemetryManager::instance()->clearContext();

    telemetry_jaeger('http://custom:4318/v1/traces', 'test-service');

    expect(TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry_otlp function initializes otlp exporter', function (): void {
    TelemetryManager::instance()->clearContext();

    telemetry_otlp('http://custom:4318/v1/traces', ['key' => 'value']);

    expect(TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry_zipkin function initializes zipkin exporter', function (): void {
    TelemetryManager::instance()->clearContext();

    telemetry_zipkin('http://custom:9411/api/v2/spans', 'test-service');

    expect(TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry function accepts custom config', function (): void {
    TelemetryManager::instance()->clearContext();

    telemetry([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    expect(TelemetryManager::instance()->isEnabled())->toBeTrue();
});
