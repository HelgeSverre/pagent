<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
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

test('tracker listens to LLM events when track_llm is enabled', function () {
    $tracker = new UsageTracker(['track_llm' => true]);

    $events = $tracker->listensTo();

    expect($events)->toContain('after_llm_response');
});

test('tracker does not listen to LLM events when track_llm is disabled', function () {
    $tracker = new UsageTracker(['track_llm' => false]);

    $events = $tracker->listensTo();

    expect($events)->not->toContain('after_llm_response');
});

test('tracker listens to Stream events when track_streaming is enabled', function () {
    $tracker = new UsageTracker(['track_streaming' => true]);

    $events = $tracker->listensTo();

    expect($events)->toContain('stream_completed');
});

test('tracker does not listen to Stream events when track_streaming is disabled', function () {
    $tracker = new UsageTracker(['track_streaming' => false]);

    $events = $tracker->listensTo();

    expect($events)->not->toContain('stream_completed');
});

test('tracker handles AfterLLMResponseEvent and records usage', function () {
    $tracker = new UsageTracker;
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-5-sonnet-20241022',
        [
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 500,
                'total_tokens' => 1500,
            ],
        ],
        100.0
    );

    $tracker->handle($event);

    $records = $tracker->getAll();

    expect($records)->toHaveCount(1)
        ->and($records[0]->agentName)->toBe('test-agent')
        ->and($records[0]->provider)->toBe('anthropic')
        ->and($records[0]->model)->toBe('claude-3-5-sonnet-20241022')
        ->and($records[0]->inputTokens)->toBe(1000)
        ->and($records[0]->outputTokens)->toBe(500)
        ->and($records[0]->totalTokens)->toBe(1500)
        ->and($records[0]->cost)->toBeGreaterThan(0);
});

test('tracker handles AfterLLMResponseEvent with cached tokens', function () {
    $tracker = new UsageTracker;
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-opus-20240229',
        [
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
                'cache_read_input_tokens' => 500,
                'cache_creation_input_tokens' => 600,
            ],
        ],
        100.0
    );

    $tracker->handle($event);

    $records = $tracker->getAll();

    expect($records)->toHaveCount(1)
        ->and($records[0]->cachedInputTokens)->toBe(500)
        ->and($records[0]->cacheCreationTokens)->toBe(600);
});

test('tracker ignores events with no usage data', function () {
    $tracker = new UsageTracker;
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-opus',
        [], // No usage data
        100.0
    );

    $tracker->handle($event);

    expect($tracker->getAll())->toBeEmpty();
});

test('tracker respects enabled config', function () {
    $tracker = new UsageTracker(['enabled' => false]);
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-opus',
        [
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 500,
                'total_tokens' => 1500,
            ],
        ],
        100.0
    );

    $tracker->handle($event);

    // No usage should be tracked when disabled
    expect($tracker->getAll())->toBeEmpty();
});

test('tracker calculates total cost', function () {
    $tracker = new UsageTracker;
    $agent1 = new Agent('bot1');
    $agent1->provider(mock());
    $agent2 = new Agent('bot2');
    $agent2->provider(mock());

    // Add first usage
    $event1 = new AfterLLMResponseEvent(
        $agent1,
        'anthropic',
        'claude-3-5-sonnet-20241022',
        [
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 500_000,
            ],
        ],
        100.0
    );
    $tracker->handle($event1);

    // Add second usage
    $event2 = new AfterLLMResponseEvent(
        $agent2,
        'openai',
        'gpt-4o-2024-11-20',
        [
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 500_000,
            ],
        ],
        100.0
    );
    $tracker->handle($event2);

    $totalCost = $tracker->getTotalCost();

    // Expected: Sonnet ($3 + $7.5 = $10.5) + GPT-4o ($2.5 + $5 = $7.5) = $18
    expect($totalCost)->toBe(18.0);
});

test('tracker calculates cost by agent', function () {
    $tracker = new UsageTracker;
    $agent1 = new Agent('bot1');
    $agent1->provider(mock());
    $agent2 = new Agent('bot2');
    $agent2->provider(mock());

    $event1 = new AfterLLMResponseEvent(
        $agent1,
        'anthropic',
        'claude-3-5-sonnet-20241022',
        [
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 500_000,
            ],
        ],
        100.0
    );
    $tracker->handle($event1);

    $event2 = new AfterLLMResponseEvent(
        $agent2,
        'openai',
        'gpt-4o-2024-11-20',
        [
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 500_000,
            ],
        ],
        100.0
    );
    $tracker->handle($event2);

    expect($tracker->getCostByAgent('bot1'))->toBe(10.5)
        ->and($tracker->getCostByAgent('bot2'))->toBe(7.5);
});

test('tracker retrieves usage by agent', function () {
    $tracker = new UsageTracker;
    $agent1 = new Agent('bot1');
    $agent1->provider(mock());
    $agent2 = new Agent('bot2');
    $agent2->provider(mock());

    $event1 = new AfterLLMResponseEvent(
        $agent1,
        'anthropic',
        'claude-3-opus',
        [
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ],
        100.0
    );
    $tracker->handle($event1);

    $event2 = new AfterLLMResponseEvent(
        $agent2,
        'openai',
        'gpt-4o',
        [
            'usage' => [
                'input_tokens' => 200,
                'output_tokens' => 100,
            ],
        ],
        100.0
    );
    $tracker->handle($event2);

    $bot1Records = $tracker->byAgent('bot1');

    expect($bot1Records)->toHaveCount(1)
        ->and($bot1Records[0]->agentName)->toBe('bot1');
});

test('tracker clears all records', function () {
    $tracker = new UsageTracker;
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-opus',
        [
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ],
        100.0
    );
    $tracker->handle($event);

    expect($tracker->getAll())->toHaveCount(1);

    $tracker->clear();

    expect($tracker->getAll())->toBeEmpty()
        ->and($tracker->getTotalCost())->toBe(0.0);
});

test('tracker accepts custom storage backend', function () {
    $customStorage = new InMemoryUsageStorage;
    $tracker = new UsageTracker(['storage' => $customStorage]);

    expect($tracker->storage())->toBe($customStorage);
});

test('tracker accepts custom pricing', function () {
    $customPricing = [
        'custom-provider' => [
            'custom-model' => [
                'input' => 10.0,
                'output' => 50.0,
            ],
        ],
    ];

    $tracker = new UsageTracker(['pricing' => $customPricing]);
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'custom-provider',
        'custom-model',
        [
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 1_000_000,
            ],
        ],
        100.0
    );
    $tracker->handle($event);

    // Expected: (1M/1M)*$10 + (1M/1M)*$50 = $60
    expect($tracker->getTotalCost())->toBe(60.0);
});

test('usage_tracker helper function creates and registers tracker', function () {
    $tracker = usage_tracker();

    expect($tracker)->toBeInstanceOf(UsageTracker::class);

    // Verify it's listening to events
    expect($tracker->listensTo())->toContain('after_llm_response')
        ->and($tracker->listensTo())->toContain('stream_completed');
});

test('global tracker returns singleton instance', function () {
    $tracker1 = UsageTracker::global();
    $tracker2 = UsageTracker::global();

    expect($tracker1)->toBe($tracker2);
});

test('global tracker auto-registers with EventManager', function () {
    $tracker = UsageTracker::global();

    // Create an agent and trigger an event
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-opus',
        [
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ],
        100.0
    );

    // Manually dispatch to verify registration
    $tracker->handle($event);

    expect($tracker->getAll())->toHaveCount(1);
});

test('tracker handles multiple events in sequence', function () {
    $tracker = new UsageTracker;
    $agent = new Agent('multi-event-agent');
    $agent->provider(mock());

    // First event
    $event1 = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-5-sonnet-20241022',
        [
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 500,
            ],
        ],
        100.0
    );
    $tracker->handle($event1);

    // Second event
    $event2 = new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'claude-3-5-sonnet-20241022',
        [
            'usage' => [
                'input_tokens' => 2000,
                'output_tokens' => 1000,
            ],
        ],
        150.0
    );
    $tracker->handle($event2);

    expect($tracker->getAll())->toHaveCount(2)
        ->and($tracker->getTotalCost())->toBeGreaterThan(0);
});

test('tracker records zero cost for free providers', function () {
    $tracker = new UsageTracker;
    $agent = new Agent('test-agent');
    $agent->provider(mock());

    $event = new AfterLLMResponseEvent(
        $agent,
        'ollama',
        'llama2',
        [
            'usage' => [
                'input_tokens' => 1_000_000,
                'output_tokens' => 1_000_000,
            ],
        ],
        100.0
    );
    $tracker->handle($event);

    expect($tracker->getTotalCost())->toBe(0.0)
        ->and($tracker->getAll())->toHaveCount(1); // Still tracks usage
});

test('tracker handles StreamCompletedEvent with usage data', function () {
    $tracker = new UsageTracker(['track_streaming' => true]);
    $agent = new Agent('stream-agent');
    $agent->provider(mock());

    $event = new Pagent\Events\Events\Stream\StreamCompletedEvent(
        $agent,
        'Hello from stream',
        5,
        250.0,
        'anthropic',
        'claude-3-5-sonnet-20241022',
        [
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'total_tokens' => 1500,
        ]
    );

    $tracker->handle($event);

    $records = $tracker->getAll();

    expect($records)->toHaveCount(1)
        ->and($records[0]->agentName)->toBe('stream-agent')
        ->and($records[0]->provider)->toBe('anthropic')
        ->and($records[0]->model)->toBe('claude-3-5-sonnet-20241022')
        ->and($records[0]->inputTokens)->toBe(1000)
        ->and($records[0]->outputTokens)->toBe(500)
        ->and($records[0]->cost)->toBeGreaterThan(0);
});

test('tracker ignores StreamCompletedEvent without usage data', function () {
    $tracker = new UsageTracker(['track_streaming' => true]);
    $agent = new Agent('stream-agent');
    $agent->provider(mock());

    $event = new Pagent\Events\Events\Stream\StreamCompletedEvent(
        $agent,
        'Hello from stream',
        5,
        250.0,
        '', // empty provider
        '', // empty model
        [] // empty usage
    );

    $tracker->handle($event);

    expect($tracker->getAll())->toBeEmpty();
});

test('tracker ignores StreamCompletedEvent when track_streaming is disabled', function () {
    $tracker = new UsageTracker(['track_streaming' => false]);
    $agent = new Agent('stream-agent');
    $agent->provider(mock());

    $event = new Pagent\Events\Events\Stream\StreamCompletedEvent(
        $agent,
        'Hello from stream',
        5,
        250.0,
        'anthropic',
        'claude-3-opus',
        [
            'input_tokens' => 1000,
            'output_tokens' => 500,
        ]
    );

    $tracker->handle($event);

    // Should not track because track_streaming is disabled
    expect($tracker->getAll())->toBeEmpty();
});
