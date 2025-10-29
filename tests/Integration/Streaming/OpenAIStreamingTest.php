<?php

declare(strict_types=1);

use Pagent\Providers\OpenAI;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

beforeEach(function () {
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
    if (empty($apiKey)) {
        test()->markTestSkipped('OPENAI_API_KEY not configured');
    }
});

test('OpenAI provider has streamPrompt method', function () {
    $provider = new OpenAI([
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'),
    ]);

    expect(method_exists($provider, 'streamPrompt'))->toBeTrue();
});

test('OpenAI streamPrompt returns StreamResponse', function () {
    $provider = new OpenAI([
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'),
    ]);

    $response = $provider->streamPrompt('Say "test" only', [
        'model' => 'gpt-4.1-nano',
        'max_tokens' => 10,
    ]);

    expect($response)->toBeInstanceOf(StreamResponse::class)
        ->and($response->getProvider())->toBe('openai')
        ->and($response->getModel())->toContain('gpt');
});

test('OpenAI streaming collects full content correctly', function () {
    $provider = new OpenAI([
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'),
    ]);

    $response = $provider->streamPrompt('Say "hello world" only', [
        'model' => 'gpt-4.1-nano',
        'max_tokens' => 20,
    ]);

    $fullContent = $response->collect();

    expect($fullContent)->toBeString()
        ->and(strlen($fullContent))->toBeGreaterThan(0);
});

test('OpenAI streaming produces text chunks', function () {
    $provider = new OpenAI([
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'),
    ]);

    $response = $provider->streamPrompt('Count to 3', [
        'model' => 'gpt-4.1-nano',
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

test('OpenAI streaming handles errors gracefully', function () {
    $provider = new OpenAI([
        'api_key' => 'invalid-key',
    ]);

    expect(fn () => $provider->streamPrompt('test'))
        ->toThrow(RuntimeException::class);
});
