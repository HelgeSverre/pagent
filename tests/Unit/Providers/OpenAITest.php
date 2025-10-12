<?php

declare(strict_types=1);

use Pagent\Providers\OpenAI;

it('requires api key', function (): void {
    expect(fn () => new OpenAI(['api_key' => '']))
        ->toThrow(RuntimeException::class, 'OpenAI API key not configured');
});

it('accepts api key in config', function (): void {
    $provider = new OpenAI(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(OpenAI::class);
});
