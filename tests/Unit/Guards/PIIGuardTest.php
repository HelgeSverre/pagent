<?php

declare(strict_types=1);

use Pagent\Contracts\OutputGuard;
use Pagent\Guards\PIIGuard;

test('it detects social security numbers', function (): void {
    $guard = new PIIGuard;

    expect($guard->check('input', 'My SSN is 123-45-6789'))->toBeFalse()
        ->and($guard->check('input', 'Hello, how are you?'))->toBeTrue();
});

test('it detects Luhn-valid credit card numbers', function (): void {
    $guard = new PIIGuard;

    expect($guard->check('input', 'Card: 4111-1111-1111-1111'))->toBeFalse()
        ->and($guard->check('input', 'Card: 4111 1111 1111 1111'))->toBeFalse()
        ->and($guard->check('input', 'Card: 4111111111111111'))->toBeFalse()
        ->and($guard->check('input', 'No card here'))->toBeTrue();
});

test('it does not flag Luhn-invalid 16-digit numbers as credit cards', function (): void {
    $guard = new PIIGuard(['credit_card']);

    expect($guard->check('input', 'Ref: 1234-5678-9012-3456'))->toBeTrue()
        ->and($guard->check('input', 'Order id 1234567890123456'))->toBeTrue();
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
        ->and($guard->check('input', 'Call me at +1 555.123.4567'))->toBeFalse()
        ->and($guard->check('input', 'No phone number'))->toBeTrue();
});

test('it does not flag bare digit runs like timestamps as phone numbers', function (): void {
    $guard = new PIIGuard(['phone']);

    expect($guard->check('input', 'Unix timestamp: 1724567890'))->toBeTrue()
        ->and($guard->check('input', 'Record id 5551234567'))->toBeTrue();
});

test('it detects ip addresses by default', function (): void {
    $guard = new PIIGuard;

    expect($guard->check('input', 'Server at 192.168.1.100'))->toBeFalse()
        ->and($guard->check('input', 'Version 999.999.999.999'))->toBeTrue()
        ->and($guard->check('input', 'No addresses here'))->toBeTrue();
});

test('it rejects unknown configured checks', function (): void {
    expect(fn () => new PIIGuard(['email', 'typo']))
        ->toThrow(InvalidArgumentException::class, 'Unknown PII check');
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

test('it is an incrementally inspectable output-phase guard', function (): void {
    $guard = new PIIGuard;

    expect($guard)->toBeInstanceOf(OutputGuard::class)
        ->and($guard->supportsIncrementalInspection())->toBeFalse()
        ->and($guard->checkOutput('Email: test@example.com'))->toBeFalse()
        ->and($guard->check('Email: test@example.com', 'No PII here'))->toBeTrue();
});
