<?php

declare(strict_types=1);

use Pagent\Exceptions\ConfigurationException;
use Pagent\Exceptions\RuntimeException;
use Pagent\Support\Web\UrlAccessPolicy;

test('allow-list patterns are evaluated without network access', function (
    array $patterns,
    string $url,
    bool $allowed,
) {
    $policy = new UrlAccessPolicy(allowList: $patterns);

    expect($policy->isAllowed($url))->toBe($allowed);
})->with([
    'exact domain' => [['example.com'], 'https://example.com/path', true],
    'exact domain mismatch' => [['example.com'], 'https://other.test/path', false],
    'wildcard subdomain' => [['*.example.com'], 'https://api.example.com', true],
    'wildcard excludes parent' => [['*.example.com'], 'https://example.com', false],
    'path wildcard' => [['api.example.com/public/*'], 'https://api.example.com/public/items?limit=1', true],
    'path mismatch' => [['api.example.com/public/*'], 'https://api.example.com/private/items', false],
    'case insensitive' => [['EXAMPLE.COM'], 'https://example.com', true],
    'wildcard in middle' => [['api.*.example.com'], 'https://api.staging.example.com', true],
    'multiple wildcards' => [['*.api.*.com'], 'https://v1.api.example.com', true],
    'port ignored for host match' => [['localhost'], 'http://localhost:8080/api', true],
    'IPv4 CIDR match' => [['192.168.1.0/24'], 'http://192.168.1.42', true],
    'IPv4 CIDR mismatch' => [['192.168.1.0/24'], 'http://192.168.2.42', false],
]);

test('disallow-list patterns are evaluated without network access', function (
    array $patterns,
    string $url,
    bool $allowed,
) {
    $policy = new UrlAccessPolicy(disallowList: $patterns);

    expect($policy->isAllowed($url))->toBe($allowed);
})->with([
    'exact domain blocked' => [['blocked.example'], 'https://blocked.example', false],
    'other domain allowed' => [['blocked.example'], 'https://allowed.example', true],
    'wildcard blocked' => [['*.blocked.example'], 'https://api.blocked.example', false],
    'path blocked' => [['example.com/admin/*'], 'https://example.com/admin/users', false],
    'other path allowed' => [['example.com/admin/*'], 'https://example.com/public/users', true],
]);

test('a non-empty allow list takes precedence over the disallow list', function () {
    $policy = new UrlAccessPolicy(
        allowList: ['example.com'],
        disallowList: ['example.com'],
    );

    expect($policy->isAllowed('https://example.com'))->toBeTrue();
});

test('empty access lists allow valid URLs', function () {
    expect((new UrlAccessPolicy)->isAllowed('https://example.com'))->toBeTrue();
});

test('invalid URLs fail closed', function () {
    $policy = new UrlAccessPolicy;

    expect($policy->isAllowed('not a URL'))->toBeFalse()
        ->and(fn () => $policy->assertAllowed('not a URL'))
        ->toThrow(RuntimeException::class, 'Invalid URL');
});

test('assertAllowed preserves useful allow and disallow errors', function () {
    expect(fn () => (new UrlAccessPolicy(allowList: ['example.com']))
        ->assertAllowed('https://other.example'))
        ->toThrow(RuntimeException::class, 'URL not in allow list');

    expect(fn () => (new UrlAccessPolicy(disallowList: ['example.com']))
        ->assertAllowed('https://example.com'))
        ->toThrow(RuntimeException::class, 'URL is in disallow list');
});

test('access-list entries must be strings', function () {
    expect(fn () => new UrlAccessPolicy(allowList: [123]))
        ->toThrow(ConfigurationException::class, 'patterns must be strings');
});
