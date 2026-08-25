<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\AgentBuilder;
use Pagent\Observability\TelemetryManager;

beforeEach(function (): void {
    TelemetryManager::reset();
});

afterEach(function (): void {
    TelemetryManager::reset();
});

it('creates agent builders with agent() function', function (): void {
    $result = \agent('new-agent');

    expect($result)->toBeInstanceOf(AgentBuilder::class);
});

it('retrieves existing agents with agent() function', function (): void {
    // First create an agent
    \agent('existing')
        ->provider('mock')
        ->system('Test agent');

    // Now retrieve it
    $agent = \agent('existing');

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent->getName())->toBe('existing');
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

    expect($provider)->toBeInstanceOf(Pagent\Providers\Anthropic::class);
});

it('creates openai provider with helper function', function (): void {
    $provider = openai(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(Pagent\Providers\OpenAI::class);
});

it('creates opencode provider with helper function', function (): void {
    $provider = opencode(['api_key' => 'test-key', 'gateway' => 'go']);

    expect($provider)->toBeInstanceOf(Pagent\Providers\OpenCode::class);
});

it('creates mock provider with helper function', function (): void {
    $provider = mock(['test' => 'response']);

    expect($provider)->toBeInstanceOf(Pagent\Providers\Mock::class);

    $response = $provider->prompt('test');
    expect($response->content)->toBe('response');
});

it('telemetry_console function initializes console exporter', function (): void {
    Pagent\Observability\TelemetryManager::instance()->clearContext();

    telemetry_console(verbose: true);

    expect(Pagent\Observability\TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry_jaeger function initializes jaeger exporter', function (): void {
    Pagent\Observability\TelemetryManager::instance()->clearContext();

    telemetry_jaeger('http://custom:4318/v1/traces', 'test-service');

    expect(Pagent\Observability\TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry_otlp function initializes otlp exporter', function (): void {
    Pagent\Observability\TelemetryManager::instance()->clearContext();

    telemetry_otlp('http://custom:4318/v1/traces', ['key' => 'value']);

    expect(Pagent\Observability\TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry_zipkin function initializes zipkin exporter', function (): void {
    Pagent\Observability\TelemetryManager::instance()->clearContext();

    telemetry_zipkin('http://custom:9411/api/v2/spans', 'test-service');

    expect(Pagent\Observability\TelemetryManager::instance()->isEnabled())->toBeTrue();
});

it('telemetry function accepts custom config', function (): void {
    Pagent\Observability\TelemetryManager::instance()->clearContext();

    telemetry([
        'enabled' => true,
        'exporter' => 'console',
    ]);

    expect(Pagent\Observability\TelemetryManager::instance()->isEnabled())->toBeTrue();
});
