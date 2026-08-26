<?php

declare(strict_types=1);

/**
 * Ollama Streaming Integration Tests.
 *
 * These tests verify NDJSON streaming functionality for Ollama.
 *
 * @group api
 * @group ollama
 */

use Pagent\Providers\Ollama;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

beforeEach(function () {
    skipIfMissingOllama();
});

test('Ollama provider has streamPrompt method', function () {
    $provider = new Ollama;

    expect(method_exists($provider, 'streamPrompt'))->toBeTrue();
});

test('Ollama streamPrompt returns StreamResponse', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Say "test" only', [
        'model' => 'qwen3:8b',
        'max_tokens' => 10,
    ]);

    expect($response)->toBeInstanceOf(StreamResponse::class)
        ->and($response->getProvider())->toBe('ollama')
        ->and($response->getModel())->toBe('qwen3:8b');
});

test('Ollama streaming collects full content correctly', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Say "hello world" only', [
        'model' => 'qwen3:8b',
        'max_tokens' => 100,
    ]);

    $fullContent = $response->collect();

    expect($fullContent)->toBeString()
        ->and(strlen($fullContent))->toBeGreaterThan(0)
        ->and(strtolower($fullContent))->toContain('hello');
});

test('Ollama streaming produces text chunks', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Count to 3', [
        'model' => 'qwen3:8b',
        'max_tokens' => 50,
    ]);

    $hasTextChunk = false;
    $hasStartChunk = false;
    $hasEndChunk = false;
    $textChunks = [];

    $response->streamTo(function (StreamChunk $chunk) use (&$hasTextChunk, &$hasStartChunk, &$hasEndChunk, &$textChunks) {
        if ($chunk->isText()) {
            $hasTextChunk = true;
            $textChunks[] = $chunk->getText();
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
        ->and($hasEndChunk)->toBeTrue('Should have an end chunk')
        ->and(count($textChunks))->toBeGreaterThan(0);
});

test('Ollama streaming handles callback correctly', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Say "test"', [
        'model' => 'qwen3:8b',
        'max_tokens' => 20,
    ]);

    $callbackCalled = false;
    $collectedContent = '';

    $response->streamTo(function (StreamChunk $chunk) use (&$callbackCalled, &$collectedContent) {
        $callbackCalled = true;
        if ($chunk->isText()) {
            $collectedContent .= $chunk->getText();
        }
    });

    expect($callbackCalled)->toBeTrue()
        ->and($collectedContent)->not->toBeEmpty();
});

test('Ollama streaming incremental content accumulates correctly', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Count: 1, 2, 3', [
        'model' => 'qwen3:8b',
        'max_tokens' => 50,
    ]);

    $chunks = [];

    $response->streamTo(function (StreamChunk $chunk) use (&$chunks) {
        if ($chunk->isText()) {
            $chunks[] = $chunk->getText();
        }
    });

    // Verify we got multiple chunks
    expect(count($chunks))->toBeGreaterThan(0);

    // Verify that concatenating chunks gives us complete content
    $fullContent = implode('', $chunks);
    expect($fullContent)->not->toBeEmpty();
});

test('Ollama streaming includes usage metadata in end chunk', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Say "hello"', [
        'model' => 'qwen3:8b',
        'max_tokens' => 20,
    ]);

    $endMetadata = null;

    $response->streamTo(function (StreamChunk $chunk) use (&$endMetadata) {
        if ($chunk->isEnd()) {
            $endMetadata = $chunk->metadata;
        }
    });

    expect($endMetadata)->not->toBeNull()
        ->and($endMetadata)->toHaveKey('usage')
        ->and($endMetadata['usage'])->toHaveKey('prompt_tokens')
        ->and($endMetadata['usage'])->toHaveKey('completion_tokens')
        ->and($endMetadata['usage'])->toHaveKey('total_tokens')
        ->and($endMetadata['usage']['total_tokens'])->toBeGreaterThan(0);
});

test('Ollama streaming consistency with non-streaming', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    // Non-streaming response
    $regularResponse = $provider->prompt('Say "hello world"', [
        'model' => 'qwen3:8b',
        'temperature' => 0,
        'max_tokens' => 20,
    ]);

    // Streaming response
    $streamResponse = $provider->streamPrompt('Say "hello world"', [
        'model' => 'qwen3:8b',
        'temperature' => 0,
        'max_tokens' => 20,
    ]);

    $streamedContent = $streamResponse->collect();

    // Both should contain the key phrase
    expect($regularResponse->content)->toContain('hello');
    expect($streamedContent)->toContain('hello');
});

test('Ollama streaming handles errors gracefully', function () {
    $provider = new Ollama;

    expect(fn () => $provider->streamPrompt('test', [
        'model' => 'nonexistent-model-12345',
    ]))->toThrow(RuntimeException::class);
});

test('Ollama NDJSON parser handles multiple lines', function () {
    skipIfMissingOllamaModel('qwen3:8b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Write a short sentence.', [
        'model' => 'qwen3:8b',
        'max_tokens' => 30,
    ]);

    $chunkCount = 0;

    $response->streamTo(function (StreamChunk $chunk) use (&$chunkCount) {
        $chunkCount++;
    });

    // Should have multiple chunks (start, text chunks, end)
    expect($chunkCount)->toBeGreaterThan(2);
});

test('Ollama streaming with gpt-oss model', function () {
    skipIfMissingOllamaModel('gpt-oss:20b');

    $provider = new Ollama;

    $response = $provider->streamPrompt('Say "test"', [
        'model' => 'gpt-oss:20b',
        'max_tokens' => 20,
    ]);

    $fullContent = $response->collect();

    expect($fullContent)->not->toBeEmpty()
        ->and($fullContent)->toContain('test');
});
