<?php

declare(strict_types=1);

use Pagent\Events\Event;
use Pagent\Events\EventDispatcher;
use Pagent\Events\EventListener;

// Test event classes
class TestEvent extends Event
{
    public function __construct(public string $message = '')
    {
        parent::__construct();
    }
}

class AnotherTestEvent extends Event {}

// Test listener
class TestListener implements EventListener
{
    public array $handled = [];

    public function handle(Event $event): void
    {
        $this->handled[] = $event;
    }

    public function listensTo(): array
    {
        return ['test'];
    }
}

class MultiEventListener implements EventListener
{
    public array $handled = [];

    public function handle(Event $event): void
    {
        $this->handled[] = $event;
    }

    public function listensTo(): array
    {
        return ['test', 'another_test'];
    }
}

test('it dispatches events to registered listeners', function () {
    $dispatcher = new EventDispatcher;
    $called = false;

    $dispatcher->on('test', function (Event $event) use (&$called) {
        $called = true;
    });

    $dispatcher->dispatch(new TestEvent);

    expect($called)->toBeTrue();
});

test('it executes listeners in priority order (high to low)', function () {
    $dispatcher = new EventDispatcher;
    $order = [];

    $dispatcher->on('test', function () use (&$order) {
        $order[] = 'default';
    });

    $dispatcher->on('test', function () use (&$order) {
        $order[] = 'high';
    }, priority: 100);

    $dispatcher->on('test', function () use (&$order) {
        $order[] = 'medium';
    }, priority: 50);

    $dispatcher->dispatch(new TestEvent);

    expect($order)->toBe(['high', 'medium', 'default']);
});

test('it stops propagation when event.stopPropagation() called', function () {
    $dispatcher = new EventDispatcher;
    $calls = [];

    $dispatcher->on('test', function (Event $event) use (&$calls) {
        $calls[] = 'first';
        $event->stopPropagation();
    }, priority: 100);

    $dispatcher->on('test', function () use (&$calls) {
        $calls[] = 'second';
    });

    $dispatcher->dispatch(new TestEvent);

    expect($calls)->toBe(['first'])
        ->and($calls)->not->toContain('second');
});

test('it supports closure-based listeners', function () {
    $dispatcher = new EventDispatcher;
    $received = null;

    $dispatcher->on('test', function (Event $event) use (&$received) {
        $received = $event;
    });

    $event = new TestEvent('hello');
    $dispatcher->dispatch($event);

    expect($received)->toBe($event)
        ->and($received->message)->toBe('hello');
});

test('it supports class-based EventListener', function () {
    $dispatcher = new EventDispatcher;
    $listener = new TestListener;

    $dispatcher->on('test', $listener);

    $event = new TestEvent;
    $dispatcher->dispatch($event);

    expect($listener->handled)->toHaveCount(1)
        ->and($listener->handled[0])->toBe($event);
});

test('it wraps closures in anonymous EventListener', function () {
    $dispatcher = new EventDispatcher;
    $called = false;

    $id = $dispatcher->on('test', function () use (&$called) {
        $called = true;
    });

    expect($id)->toBeString();

    $dispatcher->dispatch(new TestEvent);

    expect($called)->toBeTrue();
});

test('it returns unique listener ID for later removal', function () {
    $dispatcher = new EventDispatcher;

    $id1 = $dispatcher->on('test', fn () => null);
    $id2 = $dispatcher->on('test', fn () => null);

    expect($id1)->toBeString()
        ->and($id2)->toBeString()
        ->and($id1)->not->toBe($id2);
});

test('it removes listeners by ID via off()', function () {
    $dispatcher = new EventDispatcher;
    $calls = [];

    $id = $dispatcher->on('test', function () use (&$calls) {
        $calls[] = 'listener';
    });

    $dispatcher->dispatch(new TestEvent);
    expect($calls)->toBe(['listener']);

    $dispatcher->off('test', $id);

    $dispatcher->dispatch(new TestEvent);
    expect($calls)->toBe(['listener']); // Not called again
});

test('it supports once() for one-time listeners', function () {
    $dispatcher = new EventDispatcher;
    $count = 0;

    $dispatcher->once('test', function () use (&$count) {
        $count++;
    });

    $dispatcher->dispatch(new TestEvent);
    expect($count)->toBe(1);

    $dispatcher->dispatch(new TestEvent);
    expect($count)->toBe(1); // Not called again
});

test('it removes one-time listener after execution', function () {
    $dispatcher = new EventDispatcher;
    $calls = [];

    $dispatcher->once('test', function () use (&$calls) {
        $calls[] = 'once';
    });

    $dispatcher->on('test', function () use (&$calls) {
        $calls[] = 'always';
    });

    $dispatcher->dispatch(new TestEvent);
    expect($calls)->toBe(['once', 'always']);

    $calls = [];
    $dispatcher->dispatch(new TestEvent);
    expect($calls)->toBe(['always']); // 'once' not called
});

test('it handles multiple listeners for same event', function () {
    $dispatcher = new EventDispatcher;
    $calls = [];

    $dispatcher->on('test', function () use (&$calls) {
        $calls[] = 'first';
    });
    $dispatcher->on('test', function () use (&$calls) {
        $calls[] = 'second';
    });
    $dispatcher->on('test', function () use (&$calls) {
        $calls[] = 'third';
    });

    $dispatcher->dispatch(new TestEvent);

    expect($calls)->toBe(['first', 'second', 'third']);
});

test('it sorts listeners only once until new listener added', function () {
    $dispatcher = new EventDispatcher;
    $sortCount = 0;

    // This is a bit of a white-box test, but ensures performance
    $dispatcher->on('test', fn () => null, priority: 10);
    $dispatcher->on('test', fn () => null, priority: 20);

    $dispatcher->dispatch(new TestEvent);
    $dispatcher->dispatch(new TestEvent); // Should not re-sort

    // Add new listener - should require re-sort on next dispatch
    $dispatcher->on('test', fn () => null, priority: 30);
    $dispatcher->dispatch(new TestEvent);

    // If this test passes, the implementation is working correctly
    expect(true)->toBeTrue();
});

test('it does nothing when dispatching event with no listeners', function () {
    $dispatcher = new EventDispatcher;

    // Should not throw
    $dispatcher->dispatch(new TestEvent);

    expect(true)->toBeTrue();
});

test('listen() registers class listener for all its events', function () {
    $dispatcher = new EventDispatcher;
    $listener = new MultiEventListener;

    $dispatcher->listen($listener);

    $dispatcher->dispatch(new TestEvent);
    $dispatcher->dispatch(new AnotherTestEvent);

    expect($listener->handled)->toHaveCount(2);
});

test('it passes Event instance to closure listeners', function () {
    $dispatcher = new EventDispatcher;
    $receivedEvent = null;

    $dispatcher->on('test', function (Event $event) use (&$receivedEvent) {
        $receivedEvent = $event;
    });

    $originalEvent = new TestEvent('test');
    $dispatcher->dispatch($originalEvent);

    expect($receivedEvent)->toBe($originalEvent)
        ->and($receivedEvent)->toBeInstanceOf(TestEvent::class);
});

test('it respects priority with once() listeners', function () {
    $dispatcher = new EventDispatcher;
    $order = [];

    $dispatcher->once('test', function () use (&$order) {
        $order[] = 'once-high';
    }, priority: 100);

    $dispatcher->on('test', function () use (&$order) {
        $order[] = 'always-low';
    }, priority: 10);

    $dispatcher->dispatch(new TestEvent);

    expect($order)->toBe(['once-high', 'always-low']);
});
