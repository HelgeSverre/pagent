<?php

declare(strict_types=1);

use Pagent\Usage\UsageData;

test('creates UsageData with required fields', function () {
    $data = new UsageData(
        agentName: 'test-agent',
        provider: 'anthropic',
        model: 'claude-3-opus',
        inputTokens: 100,
        outputTokens: 50,
        totalTokens: 150,
        cost: 2.25
    );

    expect($data->agentName)->toBe('test-agent')
        ->and($data->provider)->toBe('anthropic')
        ->and($data->model)->toBe('claude-3-opus')
        ->and($data->inputTokens)->toBe(100)
        ->and($data->outputTokens)->toBe(50)
        ->and($data->totalTokens)->toBe(150)
        ->and($data->cost)->toBe(2.25)
        ->and($data->cachedInputTokens)->toBeNull()
        ->and($data->cacheCreationTokens)->toBeNull()
        ->and($data->sessionId)->toBeNull()
        ->and($data->timestamp)->toBe(0.0); // Default when using constructor directly
});

test('creates UsageData with cached tokens', function () {
    $data = new UsageData(
        agentName: 'test-agent',
        provider: 'anthropic',
        model: 'claude-3-sonnet',
        inputTokens: 100,
        outputTokens: 50,
        totalTokens: 150,
        cost: 1.5,
        cachedInputTokens: 500,
        cacheCreationTokens: 600
    );

    expect($data->cachedInputTokens)->toBe(500)
        ->and($data->cacheCreationTokens)->toBe(600);
});

test('creates UsageData from response data', function () {
    $usage = [
        'input_tokens' => 100,
        'output_tokens' => 50,
        'total_tokens' => 150,
    ];

    $data = UsageData::fromResponse(
        agentName: 'bot',
        provider: 'openai',
        model: 'gpt-4o',
        usage: $usage,
        cost: 1.25
    );

    expect($data->agentName)->toBe('bot')
        ->and($data->provider)->toBe('openai')
        ->and($data->model)->toBe('gpt-4o')
        ->and($data->inputTokens)->toBe(100)
        ->and($data->outputTokens)->toBe(50)
        ->and($data->totalTokens)->toBe(150)
        ->and($data->cost)->toBe(1.25);
});

test('creates UsageData from response with cached tokens', function () {
    $usage = [
        'input_tokens' => 100,
        'output_tokens' => 50,
        'total_tokens' => 150,
        'cache_read_input_tokens' => 500,
        'cache_creation_input_tokens' => 600,
    ];

    $data = UsageData::fromResponse(
        agentName: 'bot',
        provider: 'anthropic',
        model: 'claude-3-5-sonnet',
        usage: $usage,
        cost: 0.75,
        sessionId: 'session-123'
    );

    expect($data->cachedInputTokens)->toBe(500)
        ->and($data->cacheCreationTokens)->toBe(600)
        ->and($data->sessionId)->toBe('session-123');
});

test('handles missing usage fields gracefully', function () {
    $usage = [
        'input_tokens' => 100,
        // missing output_tokens and total_tokens
    ];

    $data = UsageData::fromResponse(
        agentName: 'bot',
        provider: 'anthropic',
        model: 'claude-3-haiku',
        usage: $usage,
        cost: 0.1
    );

    expect($data->inputTokens)->toBe(100)
        ->and($data->outputTokens)->toBe(0)
        ->and($data->totalTokens)->toBe(0);
});

test('fromResponse sets timestamp automatically', function () {
    $beforeTime = microtime(true);

    $usage = [
        'input_tokens' => 100,
        'output_tokens' => 50,
        'total_tokens' => 150,
    ];

    $data = UsageData::fromResponse(
        agentName: 'bot',
        provider: 'anthropic',
        model: 'claude-3-haiku',
        usage: $usage,
        cost: 0.1
    );

    $afterTime = microtime(true);

    expect($data->timestamp)->toBeGreaterThan(0)
        ->and($data->timestamp)->toBeGreaterThanOrEqual($beforeTime)
        ->and($data->timestamp)->toBeLessThanOrEqual($afterTime);
});

test('converts UsageData to array', function () {
    $data = new UsageData(
        agentName: 'test-agent',
        provider: 'anthropic',
        model: 'claude-3-opus',
        inputTokens: 100,
        outputTokens: 50,
        totalTokens: 150,
        cost: 2.25,
        cachedInputTokens: 500,
        sessionId: 'session-abc'
    );

    $array = $data->toArray();

    expect($array)->toBeArray()
        ->and($array)->toHaveKey('agent_name', 'test-agent')
        ->and($array)->toHaveKey('provider', 'anthropic')
        ->and($array)->toHaveKey('model', 'claude-3-opus')
        ->and($array)->toHaveKey('input_tokens', 100)
        ->and($array)->toHaveKey('output_tokens', 50)
        ->and($array)->toHaveKey('total_tokens', 150)
        ->and($array)->toHaveKey('cost', 2.25)
        ->and($array)->toHaveKey('cached_input_tokens', 500)
        ->and($array)->toHaveKey('session_id', 'session-abc')
        ->and($array)->toHaveKey('timestamp');
});
