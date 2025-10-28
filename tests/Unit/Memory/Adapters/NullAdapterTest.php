<?php

declare(strict_types=1);

use Pagent\Memory\Adapters\NullAdapter;

it('returns empty array on load', function (): void {
    $adapter = new NullAdapter;

    $messages = $adapter->load('test-session');

    expect($messages)->toBeArray()->toBeEmpty();
});

it('returns empty array on load for any session id', function (): void {
    $adapter = new NullAdapter;

    expect($adapter->load('session-1'))->toBeArray()->toBeEmpty();
    expect($adapter->load('session-2'))->toBeArray()->toBeEmpty();
    expect($adapter->load('completely-different'))->toBeArray()->toBeEmpty();
});

it('returns false on exists check', function (): void {
    $adapter = new NullAdapter;

    expect($adapter->exists('test-session'))->toBeFalse();
});

it('returns false on exists for any session id', function (): void {
    $adapter = new NullAdapter;

    expect($adapter->exists('session-1'))->toBeFalse();
    expect($adapter->exists('session-2'))->toBeFalse();
    expect($adapter->exists('non-existent'))->toBeFalse();
});

it('does nothing on save', function (): void {
    $adapter = new NullAdapter;

    $messages = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!'],
    ];

    $adapter->save('test-session', $messages);

    // Verify it didn't persist
    expect($adapter->load('test-session'))->toBeEmpty();
    expect($adapter->exists('test-session'))->toBeFalse();
});

it('does nothing on delete', function (): void {
    $adapter = new NullAdapter;

    // Should not throw
    $adapter->delete('test-session');
    $adapter->delete('non-existent-session');

    expect(true)->toBeTrue();
});

it('returns empty array on prune', function (): void {
    $adapter = new NullAdapter;

    $result = $adapter->prune('test-session', 5);

    expect($result)->toBeArray()->toBeEmpty();
});

it('accepts config array in constructor', function (): void {
    $adapter = new NullAdapter(['foo' => 'bar', 'baz' => 123]);

    // Config is ignored but should not throw
    expect($adapter->load('test'))->toBeEmpty();
});
