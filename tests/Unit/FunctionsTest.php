<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\AgentBuilder;

it('creates agent builders with agent() function', function (): void {
    $result = agent('new-agent');

    expect($result)->toBeInstanceOf(AgentBuilder::class);
});

it('retrieves existing agents with agent() function', function (): void {
    // First create an agent
    agent('existing')
        ->provider('mock')
        ->system('Test agent');

    // Now retrieve it
    $agent = agent('existing');

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent->getName())->toBe('existing');
});

it('returns all agents with agents() function', function (): void {
    clearAgents(); // Start fresh

    agent('agent-1')->provider('mock');
    agent('agent-2')->provider('mock');

    $all = agents();

    expect($all)->toBeArray();
    expect($all)->toHaveCount(2);
    expect($all)->toHaveKeys(['agent-1', 'agent-2']);
});

it('clears agents with clearAgents() function', function (): void {
    clearAgents(); // Start fresh

    agent('temp-1')->provider('mock');
    agent('temp-2')->provider('mock');

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

it('creates mock provider with helper function', function (): void {
    $provider = mock(['test' => 'response']);

    expect($provider)->toBeInstanceOf(Pagent\Providers\Mock::class);

    $response = $provider->prompt('test');
    expect($response->content)->toBe('response');
});
