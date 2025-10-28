<?php

declare(strict_types=1);

use Pagent\Tools\WebFetch;

test('web fetch has correct metadata', function () {
    $tool = new WebFetch;

    expect($tool->name())->toBe('web_fetch');
    expect($tool->description())->toContain('Fetch content');
    expect($tool->parameters())->toHaveKey('properties');
});

test('web fetch throws when url missing', function () {
    $tool = new WebFetch;

    expect(fn () => $tool->execute([]))
        ->toThrow(RuntimeException::class, 'URL parameter is required');
});

test('web fetch blocks localhost with SSRF protection', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://localhost']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});

test('web fetch blocks private IPs with SSRF protection', function () {
    $tool = new WebFetch(ssrfProtection: true);

    expect(fn () => $tool->execute(['url' => 'http://192.168.1.1']))
        ->toThrow(RuntimeException::class, 'SSRF protection');
});
