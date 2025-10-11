<?php

declare(strict_types=1);

use Pagent\Providers\Anthropic;

it('requires api key', function (): void {
    // Unset env vars temporarily
    $oldKey = $_ENV['ANTHROPIC_API_KEY'] ?? null;
    unset($_ENV['ANTHROPIC_API_KEY']);

    expect(fn() => new Anthropic())
        ->toThrow(RuntimeException::class, 'Anthropic API key not configured');

    // Restore
    if ($oldKey) {
        $_ENV['ANTHROPIC_API_KEY'] = $oldKey;
    }
})->skip(fn() => ! empty($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY')), 'Skipped because API key is set in environment');

it('accepts api key in config', function (): void {
    $provider = new Anthropic(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(Anthropic::class);
});

it('returns response object with correct structure', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});

it('uses default model', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});

it('allows custom model', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});

it('includes system message in response', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});

it('calculates tokens', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});

it('handles message history', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});
