<?php

declare(strict_types=1);

use Pagent\Events\Event;
use Pagent\Events\EventListener;
use Pagent\Events\EventManager;

// Test event classes for EventManager tests
class GlobalTestEvent extends Event
{
    public function __construct(public string $message = '')
    {
        parent::__construct();
    }
}

class GlobalAnotherTestEvent extends Event {}

// Test listener for EventManager tests
class GlobalTestListener implements EventListener
{
    public array $handled = [];

    public function handle(Event $event): void
    {
        $this->handled[] = $event;
    }

    public function listensTo(): array
    {
        return ['global_test'];
    }
}

class GlobalMultiEventListener implements EventListener
{
    public array $handled = [];

    public function handle(Event $event): void
    {
        $this->handled[] = $event;
    }

    public function listensTo(): array
    {
        return ['global_test', 'global_another_test'];
    }
}

afterEach(function () {
    // Reset singleton after each test for isolation
    EventManager::reset();
});

test('it returns singleton instance', function () {
    $instance1 = EventManager::instance();
    $instance2 = EventManager::instance();

    expect($instance1)->toBe($instance2)
        ->and($instance1)->toBeInstanceOf(EventManager::class);
});

test('it creates new instance after reset', function () {
    $instance1 = EventManager::instance();
    EventManager::reset();
    $instance2 = EventManager::instance();

    expect($instance1)->not->toBe($instance2)
        ->and($instance2)->toBeInstanceOf(EventManager::class);
});

test('it dispatches events globally via on()', function () {
    $manager = EventManager::instance();
    $called = false;

    $manager->on('global_test', function (Event $event) use (&$called) {
        $called = true;
    });

    $manager->dispatch(new GlobalTestEvent);

    expect($called)->toBeTrue();
});

test('it executes listeners in priority order', function () {
    $manager = EventManager::instance();
    $order = [];

    $manager->on('global_test', function () use (&$order) {
        $order[] = 'default';
    });

    $manager->on('global_test', function () use (&$order) {
        $order[] = 'high';
    }, priority: 100);

    $manager->on('global_test', function () use (&$order) {
        $order[] = 'medium';
    }, priority: 50);

    $manager->dispatch(new GlobalTestEvent);

    expect($order)->toBe(['high', 'medium', 'default']);
});

test('it supports once() for one-time global listeners', function () {
    $manager = EventManager::instance();
    $count = 0;

    $manager->once('global_test', function () use (&$count) {
        $count++;
    });

    $manager->dispatch(new GlobalTestEvent);
    expect($count)->toBe(1);

    $manager->dispatch(new GlobalTestEvent);
    expect($count)->toBe(1); // Not called again
});

test('it removes listeners via off()', function () {
    $manager = EventManager::instance();
    $calls = [];

    $id = $manager->on('global_test', function () use (&$calls) {
        $calls[] = 'listener';
    });

    $manager->dispatch(new GlobalTestEvent);
    expect($calls)->toBe(['listener']);

    $manager->off('global_test', $id);

    $manager->dispatch(new GlobalTestEvent);
    expect($calls)->toBe(['listener']); // Not called again
});

test('it supports class-based EventListener via on()', function () {
    $manager = EventManager::instance();
    $listener = new GlobalTestListener;

    $manager->on('global_test', $listener);

    $event = new GlobalTestEvent;
    $manager->dispatch($event);

    expect($listener->handled)->toHaveCount(1)
        ->and($listener->handled[0])->toBe($event);
});

test('listen() registers class listener for all its events', function () {
    $manager = EventManager::instance();
    $listener = new GlobalMultiEventListener;

    $manager->listen($listener);

    $manager->dispatch(new GlobalTestEvent);
    $manager->dispatch(new GlobalAnotherTestEvent);

    expect($listener->handled)->toHaveCount(2);
});

test('it maintains listeners across multiple dispatches', function () {
    $manager = EventManager::instance();
    $count = 0;

    $manager->on('global_test', function () use (&$count) {
        $count++;
    });

    $manager->dispatch(new GlobalTestEvent);
    $manager->dispatch(new GlobalTestEvent);
    $manager->dispatch(new GlobalTestEvent);

    expect($count)->toBe(3);
});

test('it clears all listeners after reset', function () {
    $manager = EventManager::instance();
    $called = false;

    $manager->on('global_test', function () use (&$called) {
        $called = true;
    });

    EventManager::reset();
    $newManager = EventManager::instance();

    $newManager->dispatch(new GlobalTestEvent);

    expect($called)->toBeFalse();
});

test('it handles multiple listeners from different registration sources', function () {
    $manager = EventManager::instance();
    $calls = [];

    // Closure listener
    $manager->on('global_test', function () use (&$calls) {
        $calls[] = 'closure';
    });

    // Class-based listener via on()
    $listener1 = new GlobalTestListener;
    $manager->on('global_test', $listener1);

    // Class-based listener via listen()
    $listener2 = new GlobalMultiEventListener;
    $manager->listen($listener2);

    $manager->dispatch(new GlobalTestEvent);

    expect($calls)->toBe(['closure'])
        ->and($listener1->handled)->toHaveCount(1)
        ->and($listener2->handled)->toHaveCount(1);
});

test('it passes Event instance to closure listeners', function () {
    $manager = EventManager::instance();
    $receivedEvent = null;

    $manager->on('global_test', function (Event $event) use (&$receivedEvent) {
        $receivedEvent = $event;
    });

    $originalEvent = new GlobalTestEvent('hello');
    $manager->dispatch($originalEvent);

    expect($receivedEvent)->toBe($originalEvent)
        ->and($receivedEvent->message)->toBe('hello');
});

test('it respects stopPropagation in global events', function () {
    $manager = EventManager::instance();
    $calls = [];

    $manager->on('global_test', function (Event $event) use (&$calls) {
        $calls[] = 'first';
        $event->stopPropagation();
    }, priority: 100);

    $manager->on('global_test', function () use (&$calls) {
        $calls[] = 'second';
    });

    $manager->dispatch(new GlobalTestEvent);

    expect($calls)->toBe(['first'])
        ->and($calls)->not->toContain('second');
});

test('it handles multiple event types independently', function () {
    $manager = EventManager::instance();
    $testCalls = [];
    $anotherCalls = [];

    $manager->on('global_test', function () use (&$testCalls) {
        $testCalls[] = 'test';
    });

    $manager->on('global_another_test', function () use (&$anotherCalls) {
        $anotherCalls[] = 'another';
    });

    $manager->dispatch(new GlobalTestEvent);
    expect($testCalls)->toBe(['test'])
        ->and($anotherCalls)->toBe([]);

    $manager->dispatch(new GlobalAnotherTestEvent);
    expect($testCalls)->toBe(['test'])
        ->and($anotherCalls)->toBe(['another']);
});

test('it does nothing when dispatching event with no listeners', function () {
    $manager = EventManager::instance();

    // Should not throw
    $manager->dispatch(new GlobalTestEvent);

    expect(true)->toBeTrue();
});
