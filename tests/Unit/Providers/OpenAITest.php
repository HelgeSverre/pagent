<?php

declare(strict_types=1);

use Pagent\Providers\OpenAI;

it('requires api key', function (): void {
    expect(fn() => new OpenAI(["api_key" => ""]))
        ->toThrow(RuntimeException::class, 'OpenAI API key not configured');
});

it('accepts api key in config', function (): void {
    $provider = new OpenAI(['api_key' => 'test-key']);

    expect($provider)->toBeInstanceOf(OpenAI::class);
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

it('handles system messages', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});

it('calculates tokens', function (): void {
    // Real API tests are in Integration/RealAPITest.php
    $this->markTestSkipped('Real API calls tested in integration tests');
});
