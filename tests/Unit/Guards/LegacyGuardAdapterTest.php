<?php

declare(strict_types=1);

use Pagent\Contracts\Guard;
use Pagent\Guards\LegacyGuardAdapter;

test('it preserves the legacy two-argument custom guard contract', function (): void {
    $received = null;
    $guard = new LegacyGuardAdapter('legacy', function (string $input, string $output) use (&$received): bool {
        $received = [$input, $output];

        return $output !== 'blocked';
    });

    expect($guard)->toBeInstanceOf(Guard::class)
        ->and($guard->check('user input', 'allowed'))->toBeTrue()
        ->and($received)->toBe(['user input', 'allowed'])
        ->and($guard->check('user input', 'blocked'))->toBeFalse();
});

test('it has a useful default violation message', function (): void {
    $guard = new LegacyGuardAdapter('legacy', fn (string $input, string $output): bool => false);

    expect($guard->getName())->toBe('legacy')
        ->and($guard->getViolationMessage())->toBe('Guard legacy failed');
});
