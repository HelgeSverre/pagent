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
        ->and($agent->getGuards()[0]->getName())->toBe('custom');
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

// ========================================
// GUARD EXECUTION ORDER & EDGE CASES
// ========================================

test('it stops guard execution at first violation', function (): void {
    $firstGuardCalled = false;
    $secondGuardCalled = false;

    $mockProvider = mock(['test' => 'response with bad content']);

    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);

    // Add first guard that will fail
    $agent->guard('first', function ($input, $output) use (&$firstGuardCalled) {
        $firstGuardCalled = true;

        return false; // Violation
    });

    // Add second guard that should NOT be called
    $agent->guard('second', function ($input, $output) use (&$secondGuardCalled) {
        $secondGuardCalled = true;

        return true;
    });

    expect(fn () => $agent->prompt('test'))
        ->toThrow(GuardException::class);

    expect($firstGuardCalled)->toBeTrue();
    expect($secondGuardCalled)->toBeFalse(); // Should NOT be called
});

test('it handles empty response content correctly', function (): void {
    $mockProvider = new class implements \Pagent\Contracts\Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => '',  // Empty content
                'tokens' => 10,
                'model' => 'mock',
                'provider' => 'mock',
            ];
        }
    };

    $guardCalled = false;
    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);
    $agent->guard('check_empty', function ($input, $output) use (&$guardCalled) {
        $guardCalled = true;

        return true; // Pass
    });

    $agent->prompt('test');

    // Guard should be called with empty string
    expect($guardCalled)->toBeTrue();
    expect($agent->messages)->toHaveCount(1); // Only user message (empty not added)
});

test('it handles null response content correctly', function (): void {
    $mockProvider = new class implements \Pagent\Contracts\Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => null,  // Null content
                'tokens' => 10,
                'model' => 'mock',
                'provider' => 'mock',
            ];
        }
    };

    $guardCalled = false;
    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);
    $agent->guard('check_null', function ($input, $output) use (&$guardCalled) {
        $guardCalled = true;

        return true; // Pass
    });

    $response = $agent->prompt('test');

    // Guard should be called
    expect($guardCalled)->toBeTrue();
    expect($response->content)->toBeNull();
    expect($agent->messages)->toHaveCount(1); // Only user message
});

test('it does not add empty content to history', function (): void {
    $mockProvider = new class implements \Pagent\Contracts\Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) [
                'content' => '',
                'tokens' => 10,
                'model' => 'mock',
                'provider' => 'mock',
            ];
        }
    };

    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);

    $agent->prompt('test');

    // Should only have user message, no assistant message with empty content
    expect($agent->messages)->toHaveCount(1);
    expect($agent->messages[0]['role'])->toBe('user');
});

test('it runs all guards when all pass', function (): void {
    $guard1Called = false;
    $guard2Called = false;
    $guard3Called = false;

    $mockProvider = mock(['test' => 'safe response']);

    $agent = new Agent('test-agent');
    $agent->provider($mockProvider);

    $agent->guard('guard1', function ($input, $output) use (&$guard1Called) {
        $guard1Called = true;

        return true;
    });

    $agent->guard('guard2', function ($input, $output) use (&$guard2Called) {
        $guard2Called = true;

        return true;
    });

    $agent->guard('guard3', function ($input, $output) use (&$guard3Called) {
        $guard3Called = true;

        return true;
    });

    $agent->prompt('test');

    // All guards should be called
    expect($guard1Called)->toBeTrue();
    expect($guard2Called)->toBeTrue();
    expect($guard3Called)->toBeTrue();
});
