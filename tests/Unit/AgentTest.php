<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Providers\Mock;

it('creates an agent with a name', function (): void {
    $agent = new Agent('test-agent');

    expect($agent->getName())->toBe('test-agent');
    expect($agent->messages)->toBeArray()->toBeEmpty();
});

it('sets and uses a provider', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock(['responses' => ['hello' => 'Hi there!']]);

    $agent->provider($mock);

    $response = $agent->prompt('hello');
    expect($response->content)->toBe('Hi there!');
});

it('throws exception when no provider is set', function (): void {
    $agent = new Agent('test-agent');

    expect(fn() => $agent->prompt('hello'))
        ->toThrow(RuntimeException::class, "No provider set for agent 'test-agent'");
});

it('configures agent settings fluently', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock();

    $agent
        ->provider($mock)
        ->system('You are helpful')
        ->model('gpt-4')
        ->temperature(0.8)
        ->maxTokens(2000);

    // Settings should be passed to provider
    $response = $agent->prompt('test', ['extra' => 'option']);
    expect($response)->toBeObject();
});

it('tracks conversation history', function (): void {
    $agent = new Agent('chat-agent');
    $agent->provider(new Mock());

    expect($agent->messages)->toBeEmpty();

    $agent->prompt('Hello');
    expect($agent->messages)->toHaveCount(2); // user + assistant
    expect($agent->messages[0])->toBe(['role' => 'user', 'content' => 'Hello']);
    expect($agent->messages[1]['role'])->toBe('assistant');

    $agent->prompt('How are you?');
    expect($agent->messages)->toHaveCount(4); // 2 user + 2 assistant
});

it('merges config with prompt options', function (): void {
    $agent = new Agent('test-agent');
    $agent
        ->provider(new Mock())
        ->temperature(0.7)
        ->model('base-model');

    // Provider should receive merged options
    $response = $agent->prompt('test', [
        'temperature' => 0.9,  // Override
        'max_tokens' => 100,    // Additional
    ]);

    expect($response)->toBeObject();
});

it('returns the same agent instance for fluent calls', function (): void {
    $agent = new Agent('test-agent');
    $mock = new Mock();

    $result1 = $agent->provider($mock);
    $result2 = $agent->system('test');
    $result3 = $agent->temperature(0.5);

    expect($result1)->toBe($agent);
    expect($result2)->toBe($agent);
    expect($result3)->toBe($agent);
});
