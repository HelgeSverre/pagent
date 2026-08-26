<?php

declare(strict_types=1);

use Pagent\Providers\Anthropic;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

beforeEach(function () {
    $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY');
    if (empty($apiKey)) {
        test()->markTestSkipped('ANTHROPIC_API_KEY not configured');
    }
});

test('Anthropic provider has streamPrompt method', function () {
    $provider = new Anthropic([
        'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'),
    ]);

    expect(method_exists($provider, 'streamPrompt'))->toBeTrue();
});

test('Anthropic streamPrompt returns StreamResponse', function () {
    $provider = new Anthropic([
        'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'),
    ]);

    $response = $provider->streamPrompt('Say "test" only', [
        'model' => 'claude-3-5-haiku-20241022',
        'max_tokens' => 10,
    ]);

    expect($response)->toBeInstanceOf(StreamResponse::class)
        ->and($response->getProvider())->toBe('anthropic')
        ->and($response->getModel())->toContain('claude');
});

test('Anthropic streaming collects full content correctly', function () {
    $provider = new Anthropic([
        'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'),
    ]);

    $response = $provider->streamPrompt('Say "hello world" only', [
        'model' => 'claude-3-5-haiku-20241022',
        'max_tokens' => 20,
    ]);

    $fullContent = $response->collect();

    expect($fullContent)->toBeString()
        ->and(strlen($fullContent))->toBeGreaterThan(0)
        ->and($response->getUsage())->toBeArray()
        ->and($response->getStopReason())->toBeString();
});

test('Anthropic streaming produces text chunks', function () {
    $provider = new Anthropic([
        'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'),
    ]);

    $response = $provider->streamPrompt('Count to 3', [
        'model' => 'claude-3-5-haiku-20241022',
        'max_tokens' => 50,
    ]);

    $hasTextChunk = false;
    $hasStartChunk = false;
    $hasEndChunk = false;

    $response->streamTo(function (StreamChunk $chunk) use (&$hasTextChunk, &$hasStartChunk, &$hasEndChunk) {
        if ($chunk->isText()) {
            $hasTextChunk = true;
        }
        if ($chunk->isStart()) {
            $hasStartChunk = true;
        }
        if ($chunk->isEnd()) {
            $hasEndChunk = true;
        }
    });

    expect($hasTextChunk)->toBeTrue('Should have at least one text chunk')
        ->and($hasStartChunk)->toBeTrue('Should have a start chunk')
        ->and($hasEndChunk)->toBeTrue('Should have an end chunk');
});

test('Anthropic streaming handles errors gracefully', function () {
    $provider = new Anthropic([
        'api_key' => 'invalid-key',
    ]);

    expect(fn () => $provider->streamPrompt('test'))
        ->toThrow(RuntimeException::class);
});
