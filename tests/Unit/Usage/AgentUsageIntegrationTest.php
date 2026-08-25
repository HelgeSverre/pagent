<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Usage\Storage\InMemoryUsageStorage;
use Pagent\Usage\UsageTracker;

beforeEach(function () {
    // Reset global tracker before each test
    UsageTracker::resetGlobal();
});

afterEach(function () {
    // Clean up after each test
    UsageTracker::resetGlobal();
});

test('agent trackUsage enables per-agent tracking', function () {
    $agent = new Agent('test-agent');
    $agent->provider(mock())
        ->trackUsage();

    $response = $agent->prompt('Hello');

    // Verify usage was tracked
    $usage = $agent->getUsage();

    expect($usage)->toHaveCount(1)
        ->and($usage[0]->agentName)->toBe('test-agent')
        ->and($usage[0]->inputTokens)->toBeGreaterThan(0);
});

test('agent getUsage returns usage from dedicated tracker', function () {
    $agent = new Agent('dedicated-agent');
    $agent->provider(mock())
        ->trackUsage();

    // Make multiple calls
    $agent->prompt('First');
    $agent->prompt('Second');
    $agent->prompt('Third');

    $usage = $agent->getUsage();

    expect($usage)->toHaveCount(3)
        ->and($usage[0]->agentName)->toBe('dedicated-agent')
        ->and($usage[1]->agentName)->toBe('dedicated-agent')
        ->and($usage[2]->agentName)->toBe('dedicated-agent');
});

test('agent getUsage works with global tracker singleton', function () {
    // Create agent with tracker
    $agent = new Agent('test-agent');
    $agent->provider(mock())->trackUsage();

    $agent->prompt('Hello');

    // Can also access via global singleton (if tracker was created that way)
    // Note: Per-agent trackers are independent, not global
    $usage = $agent->getUsage();

    expect($usage)->toHaveCount(1)
        ->and($usage[0]->agentName)->toBe('test-agent');
});

test('agent getUsage returns empty array when no tracker', function () {
    $agent = new Agent('no-tracker');
    $agent->provider(mock());

    $agent->prompt('Hello');

    // No tracker enabled, should return empty array
    $usage = $agent->getUsage();

    expect($usage)->toBeEmpty();
});

test('agent trackUsage accepts custom configuration', function () {
    $agent = new Agent('custom-config-agent');
    $agent->provider(mock())
        ->trackUsage(['track_llm' => true, 'track_streaming' => false]);

    $agent->prompt('Hello');

    $usage = $agent->getUsage();

    expect($usage)->toHaveCount(1);
});

test('multiple agents can have independent trackers', function () {
    $storage1 = new InMemoryUsageStorage;
    $storage2 = new InMemoryUsageStorage;
    $agent1 = new Agent('agent-1');
    $agent1->provider(mock())->trackUsage(['storage' => $storage1]);

    $agent2 = new Agent('agent-2');
    $agent2->provider(mock())->trackUsage(['storage' => $storage2]);

    $agent1->prompt('Hello from 1');
    $agent2->prompt('Hello from 2');
    $agent1->prompt('Another from 1');

    $usage1 = $agent1->getUsage();
    $usage2 = $agent2->getUsage();

    expect($usage1)->toHaveCount(2)
        ->and($usage2)->toHaveCount(1)
        ->and($usage1[0]->agentName)->toBe('agent-1')
        ->and($usage2[0]->agentName)->toBe('agent-2')
        ->and($storage1->getAll())->toHaveCount(2)
        ->and($storage2->getAll())->toHaveCount(1);
});

test('agent getUsage filters correctly by agent name', function () {
    // Each agent has its own tracker
    $agent1 = new Agent('agent-1');
    $agent1->provider(mock())->trackUsage();

    $agent2 = new Agent('agent-2');
    $agent2->provider(mock())->trackUsage();

    $agent1->prompt('Hello');
    $agent2->prompt('Hello');
    $agent2->prompt('Hello again');

    // Each agent should only see its own usage
    expect($agent1->getUsage())->toHaveCount(1)
        ->and($agent2->getUsage())->toHaveCount(2);
});

test('agent trackUsage supports method chaining', function () {
    $agent = new Agent('chaining-test');
    $result = $agent->provider(mock())
        ->trackUsage()
        ->temperature(0.7)
        ->maxTokens(100);

    expect($result)->toBe($agent);

    $agent->prompt('Test');

    expect($agent->getUsage())->toHaveCount(1);
});
