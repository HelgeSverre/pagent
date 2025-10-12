<?php

declare(strict_types=1);

use Pagent\Guards\PIIGuard;

test('it detects social security numbers', function (): void {
    $guard = new PIIGuard;

    expect($guard->check('input', 'My SSN is 123-45-6789'))->toBeFalse()
        ->and($guard->check('input', 'Hello, how are you?'))->toBeTrue();
});

test('it detects credit card numbers', function (): void {
    $guard = new PIIGuard;

    expect($guard->check('input', 'Card: 1234-5678-9012-3456'))->toBeFalse()
        ->and($guard->check('input', 'Card: 1234 5678 9012 3456'))->toBeFalse()
        ->and($guard->check('input', 'No card here'))->toBeTrue();
});

test('it detects email addresses', function (): void {
    $guard = new PIIGuard;

    expect($guard->check('input', 'Contact me at user@example.com'))->toBeFalse()
        ->and($guard->check('input', 'No contact info'))->toBeTrue();
});

test('it detects phone numbers', function (): void {
    $guard = new PIIGuard;

    expect($guard->check('input', 'Call me at (555) 123-4567'))->toBeFalse()
        ->and($guard->check('input', 'Call me at 555-123-4567'))->toBeFalse()
        ->and($guard->check('input', 'No phone number'))->toBeTrue();
});

test('it can be configured with specific checks', function (): void {
    $guardEmailOnly = new PIIGuard(['email']);

    expect($guardEmailOnly->check('input', 'SSN: 123-45-6789'))->toBeTrue()
        ->and($guardEmailOnly->check('input', 'Email: test@example.com'))->toBeFalse();
});

test('it provides correct name and message', function (): void {
    $guard = new PIIGuard;

    expect($guard->getName())->toBe('pii_guard')
        ->and($guard->getViolationMessage())->toContain('personally identifiable information');
});
