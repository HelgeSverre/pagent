<?php

declare(strict_types=1);

use Pagent\Usage\Storage\InMemoryUsageStorage;
use Pagent\Usage\UsageData;

test('saves and retrieves usage data', function () {
    $storage = new InMemoryUsageStorage;

    $data = new UsageData(
        agentName: 'bot1',
        provider: 'anthropic',
        model: 'claude-3-opus',
        inputTokens: 100,
        outputTokens: 50,
        totalTokens: 150,
        cost: 2.25
    );

    $storage->save($data);

    $all = $storage->getAll();

    expect($all)->toHaveCount(1)
        ->and($all[0])->toBe($data);
});

test('retrieves all usage data', function () {
    $storage = new InMemoryUsageStorage;

    $data1 = new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25);
    $data2 = new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5);
    $data3 = new UsageData('bot1', 'anthropic', 'claude-3-haiku', 50, 25, 75, 0.1);

    $storage->save($data1);
    $storage->save($data2);
    $storage->save($data3);

    $all = $storage->getAll();

    expect($all)->toHaveCount(3)
        ->and($all[0])->toBe($data1)
        ->and($all[1])->toBe($data2)
        ->and($all[2])->toBe($data3);
});

test('filters usage data by agent name', function () {
    $storage = new InMemoryUsageStorage;

    $data1 = new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25);
    $data2 = new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5);
    $data3 = new UsageData('bot1', 'anthropic', 'claude-3-haiku', 50, 25, 75, 0.1);

    $storage->save($data1);
    $storage->save($data2);
    $storage->save($data3);

    $bot1Data = $storage->byAgent('bot1');

    expect($bot1Data)->toHaveCount(2)
        ->and($bot1Data[0])->toBe($data1)
        ->and($bot1Data[1])->toBe($data3);
});

test('filters usage data by session id', function () {
    $storage = new InMemoryUsageStorage;

    $data1 = new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25, null, null, 'session-1');
    $data2 = new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5, null, null, 'session-2');
    $data3 = new UsageData('bot1', 'anthropic', 'claude-3-haiku', 50, 25, 75, 0.1, null, null, 'session-1');

    $storage->save($data1);
    $storage->save($data2);
    $storage->save($data3);

    $session1Data = $storage->bySession('session-1');

    expect($session1Data)->toHaveCount(2)
        ->and($session1Data[0])->toBe($data1)
        ->and($session1Data[1])->toBe($data3);
});

test('calculates total cost across all records', function () {
    $storage = new InMemoryUsageStorage;

    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25));
    $storage->save(new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5));
    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-haiku', 50, 25, 75, 0.1));

    $totalCost = $storage->getTotalCost();

    // 2.25 + 3.5 + 0.1 = 5.85
    expect($totalCost)->toBe(5.85);
});

test('calculates total cost by agent', function () {
    $storage = new InMemoryUsageStorage;

    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25));
    $storage->save(new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5));
    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-haiku', 50, 25, 75, 0.1));

    $bot1Cost = $storage->getTotalCostByAgent('bot1');
    $bot2Cost = $storage->getTotalCostByAgent('bot2');

    // bot1: 2.25 + 0.1 = 2.35
    expect($bot1Cost)->toBe(2.35)
        ->and($bot2Cost)->toBe(3.5);
});

test('calculates total cost by session', function () {
    $storage = new InMemoryUsageStorage;

    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25, null, null, 'session-1'));
    $storage->save(new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5, null, null, 'session-2'));
    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-haiku', 50, 25, 75, 0.1, null, null, 'session-1'));

    $session1Cost = $storage->getTotalCostBySession('session-1');
    $session2Cost = $storage->getTotalCostBySession('session-2');

    // session-1: 2.25 + 0.1 = 2.35
    expect($session1Cost)->toBe(2.35)
        ->and($session2Cost)->toBe(3.5);
});

test('exports usage data to array format', function () {
    $storage = new InMemoryUsageStorage;

    $data = new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25);
    $storage->save($data);

    $exported = $storage->exportToArray();

    expect($exported)->toBeArray()
        ->and($exported)->toHaveCount(1)
        ->and($exported[0])->toHaveKey('agent_name', 'bot1')
        ->and($exported[0])->toHaveKey('provider', 'anthropic')
        ->and($exported[0])->toHaveKey('model', 'claude-3-opus')
        ->and($exported[0])->toHaveKey('cost', 2.25);
});

test('clears all usage data', function () {
    $storage = new InMemoryUsageStorage;

    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25));
    $storage->save(new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5));

    expect($storage->getAll())->toHaveCount(2);

    $storage->clear();

    expect($storage->getAll())->toBeEmpty()
        ->and($storage->getTotalCost())->toBe(0.0);
});

test('counts usage records', function () {
    $storage = new InMemoryUsageStorage;

    expect($storage->count())->toBe(0);

    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25));

    expect($storage->count())->toBe(1);

    $storage->save(new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5));

    expect($storage->count())->toBe(2);
});

test('gets latest usage record', function () {
    $storage = new InMemoryUsageStorage;

    expect($storage->getLatest())->toBeNull();

    $data1 = new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25);
    $data2 = new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5);

    $storage->save($data1);
    $storage->save($data2);

    expect($storage->getLatest())->toBe($data2);
});

test('gets latest usage record by agent', function () {
    $storage = new InMemoryUsageStorage;

    $data1 = new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25);
    $data2 = new UsageData('bot2', 'openai', 'gpt-4o', 200, 100, 300, 3.5);
    $data3 = new UsageData('bot1', 'anthropic', 'claude-3-haiku', 50, 25, 75, 0.1);

    $storage->save($data1);
    $storage->save($data2);
    $storage->save($data3);

    expect($storage->getLatestByAgent('bot1'))->toBe($data3)
        ->and($storage->getLatestByAgent('bot2'))->toBe($data2)
        ->and($storage->getLatestByAgent('nonexistent'))->toBeNull();
});

test('returns empty arrays for non-existent agent', function () {
    $storage = new InMemoryUsageStorage;

    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25));

    $records = $storage->byAgent('nonexistent');

    expect($records)->toBeEmpty()
        ->and($storage->getTotalCostByAgent('nonexistent'))->toBe(0.0);
});

test('returns empty arrays for non-existent session', function () {
    $storage = new InMemoryUsageStorage;

    $storage->save(new UsageData('bot1', 'anthropic', 'claude-3-opus', 100, 50, 150, 2.25, null, null, 'session-1'));

    $records = $storage->bySession('nonexistent');

    expect($records)->toBeEmpty()
        ->and($storage->getTotalCostBySession('nonexistent'))->toBe(0.0);
});
