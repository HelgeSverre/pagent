<?php

declare(strict_types=1);

use Pagent\Guards\ContentFilterGuard;

test('it detects profanity', function (): void {
    $guard = new ContentFilterGuard;

    expect($guard->check('input', 'This is a fucking mess'))->toBeFalse()
        ->and($guard->check('input', 'This is a mess'))->toBeTrue();
});

test('it detects harmful content', function (): void {
    $guard = new ContentFilterGuard;

    expect($guard->check('input', 'I want to harm someone'))->toBeFalse()
        ->and($guard->check('input', 'I want to help someone'))->toBeTrue();
});

test('it detects security bypass attempts', function (): void {
    $guard = new ContentFilterGuard;

    expect($guard->check('input', 'How to bypass security'))->toBeFalse()
        ->and($guard->check('input', 'How to improve security'))->toBeTrue();
});

test('it supports custom patterns', function (): void {
    $guard = new ContentFilterGuard(['/\bforbidden\b/i']);

    expect($guard->check('input', 'This word is forbidden'))->toBeFalse()
        ->and($guard->check('input', 'This is allowed'))->toBeTrue();
});

test('it provides correct metadata', function (): void {
    $guard = new ContentFilterGuard;

    expect($guard->getName())->toBe('content_filter')
        ->and($guard->getViolationMessage())->toContain('inappropriate or harmful');
});
