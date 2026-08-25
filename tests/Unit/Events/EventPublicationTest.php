<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Events\Event;
use Pagent\Events\EventDispatcher;
use Pagent\Events\EventListener;
use Pagent\Events\EventManager;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Usage\UsageTracker;

final class PublishedEventForPublicationTest extends Event
{
    public function getEventName(): string
    {
        return 'published_for_test';
    }
}

final class CountingPublicationListener implements EventListener
{
    public int $calls = 0;

    public function handle(Event $event): void
    {
        $this->calls++;
    }

    public function listensTo(): array
    {
        return ['published_for_test'];
    }
}

beforeEach(function (): void {
    EventManager::reset();
    UsageTracker::resetGlobal();
});

afterEach(function (): void {
    UsageTracker::resetGlobal();
    EventManager::reset();
});

test('canonical publication delivers global and scoped listeners', function (): void {
    $scoped = new EventDispatcher;
    $globalCalls = 0;
    $scopedCalls = 0;

    EventManager::instance()->on('published_for_test', function () use (&$globalCalls): void {
        $globalCalls++;
    });
    $scoped->on('published_for_test', function () use (&$scopedCalls): void {
        $scopedCalls++;
    });

    EventManager::publish(new PublishedEventForPublicationTest, $scoped);

    expect($globalCalls)->toBe(1)
        ->and($scopedCalls)->toBe(1);
});

test('canonical publication does not deliver the same listener twice across scopes', function (): void {
    $listener = new CountingPublicationListener;
    $scoped = new EventDispatcher;

    EventManager::instance()->listen($listener);
    $scoped->listen($listener);

    EventManager::publish(new PublishedEventForPublicationTest, $scoped);

    expect($listener->calls)->toBe(1);
});

test('subscriptions can be explicitly cancelled', function (): void {
    $listener = new CountingPublicationListener;
    $subscription = EventManager::instance()->subscribe($listener);

    expect($subscription->isActive())->toBeTrue();

    $subscription->unsubscribe();
    $subscription->unsubscribe();
    EventManager::instance()->dispatch(new PublishedEventForPublicationTest);

    expect($subscription->isActive())->toBeFalse()
        ->and($listener->calls)->toBe(0);
});

test('global usage tracker rebinds after an event manager reset without duplicate delivery', function (): void {
    $tracker = UsageTracker::global();
    $agent = new Agent('usage-publication-agent');
    $agent->provider(mock());

    EventManager::instance()->dispatch(new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'test-model',
        ['usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
        1.0,
    ));

    EventManager::reset();
    $rebound = UsageTracker::global();

    EventManager::instance()->dispatch(new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'test-model',
        ['usage' => ['input_tokens' => 20, 'output_tokens' => 10]],
        1.0,
    ));

    expect($rebound)->toBe($tracker)
        ->and($tracker->getAll())->toHaveCount(2);
});

test('resetting the global usage tracker unsubscribes it from the active manager', function (): void {
    $tracker = UsageTracker::global();
    UsageTracker::resetGlobal();

    $agent = new Agent('usage-reset-agent');
    $agent->provider(mock());

    EventManager::instance()->dispatch(new AfterLLMResponseEvent(
        $agent,
        'anthropic',
        'test-model',
        ['usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
        1.0,
    ));

    expect($tracker->getAll())->toBeEmpty();
});

test('agent publication reaches global listeners without double-counting agent-scoped listeners', function (): void {
    $globalTracker = UsageTracker::global();
    $agent = new Agent('agent-publication-agent');
    $agent->provider(mock())->trackUsage()->prompt('track this turn');

    expect($globalTracker->getAll())->toHaveCount(1)
        ->and($agent->getUsage())->toHaveCount(1);
});
