<?php

declare(strict_types=1);

use Pagent\Providers\Anthropic;

it('requires api key', function (): void {
    expect(fn() => new Anthropic(["api_key" => ""]))
        ->toThrow(RuntimeException::class, 'Anthropic API key not configured');
});

it('accepts api key in config', function (): void {
    $provider = new Anthropic(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(Anthropic::class);
});
