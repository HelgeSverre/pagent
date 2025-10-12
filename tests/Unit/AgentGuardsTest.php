<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Exceptions\GuardException;
use Pagent\Guards\PIIGuard;

test('it adds guard to agent', function (): void {
    $agent = testAgent();
    $agent->guard('pii');

    expect($agent->getGuards())->toHaveCount(1)
        ->and($agent->getGuards()[0])->toBeInstanceOf(PIIGuard::class);
});

test('it adds guard via class instance', function (): void {
    $agent = testAgent();
    $agent->guard(new PIIGuard);

    expect($agent->getGuards())->toHaveCount(1)
        ->and($agent->getGuards()[0])->toBeInstanceOf(PIIGuard::class);
});

test('it adds guard via closure', function (): void {
    $agent = testAgent();
    $agent->guard('custom', fn (string $input, string $output): bool => ! str_contains($output, 'bad'));

    expect($agent->getGuards())->toHaveCount(1)
        ->and($agent->getGuards()[0]->getName())->toBe('Custom');
});

test('it throws exception when guard is violated', function (): void {
    $mockProvider = mock([
        'What is your email?' => 'My email is test@example.com',
    ]);

    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);
    $agent->guard('pii');

    expect(fn () => $agent->prompt('What is your email?'))
        ->toThrow(GuardException::class);
});

test('it uses fallback when guard is violated', function (): void {
    $mockProvider = mock([
        'What is your SSN?' => 'My SSN is 123-45-6789',
    ]);

    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);
    $agent->guard('pii')
        ->fallback(fn ($error) => 'I cannot share that information.');

    $response = $agent->prompt('What is your SSN?');

    expect($response->content)->toBe('I cannot share that information.')
        ->and($response->guard_triggered ?? null)->toBe('pii_guard');
});

test('it supports multiple guards', function (): void {
    $agent = testAgent();
    $agent->guard('pii')
        ->guard('contentFilter')
        ->guard('promptInjection');

    expect($agent->getGuards())->toHaveCount(3);
});

test('it chains guard configuration', function (): void {
    $agent = testAgent();

    $result = $agent->guard('pii')
        ->guard('contentFilter')
        ->fallback(fn ($e) => 'Blocked');

    expect($result)->toBeInstanceOf(Agent::class)
        ->and($agent->getGuards())->toHaveCount(2);
});

test('it passes when no guards are violated', function (): void {
    $mockProvider = mock([
        'Hello' => 'Hello! How can I help you today?',
    ]);

    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);
    $agent->guard('pii');

    $response = $agent->prompt('Hello');

    expect($response->content)->toBe('Hello! How can I help you today?');
});

test('it throws exception for unknown guard class', function (): void {
    $agent = testAgent();

    expect(fn () => $agent->guard('nonexistent'))
        ->toThrow(RuntimeException::class, 'Guard class');
});
