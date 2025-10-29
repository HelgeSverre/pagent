<?php

declare(strict_types=1);

/**
 * Ollama Provider Integration Tests.
 *
 * These tests make actual calls to a local Ollama server.
 * They are marked with @group ollama to allow skipping if Ollama is not available.
 *
 * Run with: ./vendor/bin/pest --group=ollama
 * Skip with: ./vendor/bin/pest --exclude-group=ollama
 *
 * Prerequisites:
 * - Ollama server running locally
 * - Models installed: qwen3:8b, gpt-oss:20b
 */

use Pagent\Providers\Ollama;

/**
 * @group api
 * @group ollama
 */
describe('Ollama Provider', function (): void {
    beforeEach(function (): void {
        skipIfMissingOllama();
    });

    it('makes a simple API call with qwen3:8b', function (): void {
        skipIfMissingOllamaModel('qwen3:8b');

        $ollama = new Ollama;

        $response = $ollama->prompt('Say "Hello from Ollama" and nothing else.', [
            'model' => 'qwen3:8b',
        ]);

        expect($response->content)->toContain('Hello from Ollama');
        expect($response->provider)->toBe('ollama');
        expect($response->model)->toBe('qwen3:8b');
        expect($response->tokens)->toBeGreaterThan(0);
    });

    it('makes a simple API call with gpt-oss:20b', function (): void {
        skipIfMissingOllamaModel('gpt-oss:20b');

        $ollama = new Ollama;

        $response = $ollama->prompt('Say "Hello" and nothing else.', [
            'model' => 'gpt-oss:20b',
        ]);

        expect($response->content)->toContain('Hello');
        expect($response->provider)->toBe('ollama');
        expect($response->model)->toBe('gpt-oss:20b');
        expect($response->tokens)->toBeGreaterThan(0);
    });

    it('uses system messages', function (): void {
        skipIfMissingOllamaModel('qwen3:8b');

        $ollama = new Ollama;

        $response = $ollama->prompt('What are you?', [
            'model' => 'qwen3:8b',
            'system' => 'You are a pirate. Always respond in pirate speak.',
        ]);

        expect($response->content)->toMatch('/arr|matey|ahoy|ye|pirate/i');
    });

    it('handles temperature settings', function (): void {
        skipIfMissingOllamaModel('qwen3:8b');

        $ollama = new Ollama;

        // Low temperature should give more deterministic results
        $response1 = $ollama->prompt('What is 2+2?', [
            'model' => 'qwen3:8b',
            'temperature' => 0,
        ]);
        $response2 = $ollama->prompt('What is 2+2?', [
            'model' => 'qwen3:8b',
            'temperature' => 0,
        ]);

        expect($response1->content)->toContain('4');
        expect($response2->content)->toContain('4');
    });

    it('handles max_tokens settings', function (): void {
        skipIfMissingOllamaModel('qwen3:8b');

        $ollama = new Ollama;

        $response = $ollama->prompt('Count to 100', [
            'model' => 'qwen3:8b',
            'max_tokens' => 10,
        ]);

        ray($response);
        expect($response->content)->not->toBeEmpty();
        // With max_tokens=10, we shouldn't get all the way to 100
        expect($response->content)->not->toContain('100');
    });

    it('tracks token usage correctly', function (): void {
        skipIfMissingOllamaModel('qwen3:8b');

        $ollama = new Ollama;

        $response = $ollama->prompt('Count to 5', [
            'model' => 'qwen3:8b',
        ]);

        expect($response->usage)->toBeArray();
        expect($response->usage['prompt_tokens'])->toBeGreaterThan(0);
        expect($response->usage['completion_tokens'])->toBeGreaterThan(0);
        expect($response->usage['total_tokens'])->toBe(
            $response->usage['prompt_tokens'] + $response->usage['completion_tokens']
        );
        expect($response->tokens)->toBe($response->usage['total_tokens']);
    });

    it('handles multi-turn conversations', function (): void {
        skipIfMissingOllamaModel('qwen3:8b');

        $ollama = new Ollama;

        // First turn
        $response1 = $ollama->prompt('My name is Bob.', [
            'model' => 'qwen3:8b',
        ]);

        expect($response1->content)->not->toBeEmpty();

        // Second turn - include conversation history
        $response2 = $ollama->prompt('What is my name?', [
            'model' => 'qwen3:8b',
            'messages' => [
                ['role' => 'user', 'content' => 'My name is Bob.'],
                ['role' => 'assistant', 'content' => $response1->content],
                ['role' => 'user', 'content' => 'What is my name?'],
            ],
        ]);

        expect($response2->content)->toContain('Bob');
    });

    it('handles errors gracefully when model not found', function (): void {
        $ollama = new Ollama;

        expect(fn () => $ollama->prompt('test', [
            'model' => 'nonexistent-model-12345',
        ]))->toThrow(RuntimeException::class);
    });

    it('respects custom base URL configuration', function (): void {
        $ollama = new Ollama([
            'base_url' => getOllamaBaseUrl(),
        ]);

        skipIfMissingOllamaModel('qwen3:8b');

        $response = $ollama->prompt('Say "test"', [
            'model' => 'qwen3:8b',
        ]);

        expect($response->provider)->toBe('ollama');
    });

    it('respects custom timeout configuration', function (): void {
        $ollama = new Ollama([
            'timeout' => 180,
        ]);

        skipIfMissingOllamaModel('qwen3:8b');

        $response = $ollama->prompt('Say "test"', [
            'model' => 'qwen3:8b',
        ]);

        expect($response->provider)->toBe('ollama');
    });

    it('reads OLLAMA_HOST from environment', function (): void {
        // Set env var temporarily
        $originalHost = $_ENV['OLLAMA_HOST'] ?? null;
        $_ENV['OLLAMA_HOST'] = getOllamaBaseUrl();

        $ollama = new Ollama;

        skipIfMissingOllamaModel('qwen3:8b');

        $response = $ollama->prompt('Say "test"', [
            'model' => 'qwen3:8b',
        ]);

        expect($response->provider)->toBe('ollama');

        // Restore original env
        if ($originalHost !== null) {
            $_ENV['OLLAMA_HOST'] = $originalHost;
        } else {
            unset($_ENV['OLLAMA_HOST']);
        }
    });

    it('uses helper function', function (): void {
        skipIfMissingOllamaModel('qwen3:8b');

        $ollama = ollama();

        $response = $ollama->prompt('Say "test"', [
            'model' => 'qwen3:8b',
        ]);

        expect($response->provider)->toBe('ollama');
    });

    it('works with qwen2.5-coder:7b model', function (): void {
        skipIfMissingOllamaModel('qwen2.5-coder:7b');

        $ollama = new Ollama;

        $response = $ollama->prompt('Write a PHP function that adds two numbers', [
            'model' => 'qwen2.5-coder:7b',
            'max_tokens' => 100,
        ]);

        expect($response->content)->not->toBeEmpty();
        expect($response->provider)->toBe('ollama');
        expect($response->model)->toBe('qwen2.5-coder:7b');
        expect($response->tokens)->toBeGreaterThan(0);
        // Should contain code-related content
        expect($response->content)->toMatch('/function|php|add|\+/i');
    });
});
