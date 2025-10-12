<?php

declare(strict_types=1);

use Pagent\AgentBuilder;
use Pagent\Registry;

it('creates agent builders', function (): void {
    $builder = new AgentBuilder('test-agent');

    expect($builder)->toBeInstanceOf(AgentBuilder::class);
});

it('registers agent on destruction', function (): void {
    expect(Registry::has('auto-register'))->toBeFalse();

    // Create builder in a scope so it gets destroyed
    (function (): void {
        $builder = new AgentBuilder('auto-register');
        $builder->provider('mock');
    })();

    expect(Registry::has('auto-register'))->toBeTrue();
    expect(Registry::get('auto-register'))->toBeAgent();
});

it('configures agent through builder', function (): void {
    $builder = new AgentBuilder('configured');
    $builder
        ->provider('mock')
        ->system('You are helpful')
        ->temperature(0.9)
        ->maxTokens(1500);

    $agent = $builder->build();

    expect($agent)->toBeAgent();
    expect($agent->getName())->toBe('configured');
});

it('supports provider configuration', function (): void {
    $builder = new AgentBuilder('with-config');

    $builder->provider('mock', [
        'responses' => ['test' => 'custom response'],
    ]);

    $agent = $builder->build();
    $response = $agent->prompt('test');

    expect($response->content)->toBe('custom response');
});

it('throws exception for unknown provider', function (): void {
    $builder = new AgentBuilder('test');

    expect(fn() => $builder->provider('unknown'))
        ->toThrow(InvalidArgumentException::class, 'Unknown provider: unknown');
});

it('forwards method calls to agent', function (): void {
    $builder = new AgentBuilder('forwarded');

    $builder
        ->provider('mock')
        ->system('System prompt')
        ->model('gpt-4.1-mini')
        ->temperature(0.5);

    $agent = $builder->build();

    // Agent should have been configured
    expect($agent)->toBeAgent();
});
