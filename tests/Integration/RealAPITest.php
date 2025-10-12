<?php

declare(strict_types=1);

/**
 * Real API Integration Tests.
 *
 * These tests make actual API calls and require valid API keys.
 * They are marked with @group api to allow skipping during regular test runs.
 *
 * Run with: ./vendor/bin/pest --group=api
 * Skip with: ./vendor/bin/pest --exclude-group=api
 */

use Pagent\Providers\Anthropic;
use Pagent\Providers\OpenAI;

/**
 * @group api
 * @group anthropic
 */
\describe('Anthropic API', function (): void {
    \beforeEach(function (): void {
        if (empty($_ENV['ANTHROPIC_API_KEY'] ?? \getenv('ANTHROPIC_API_KEY'))) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }
    });

    \it('makes a simple API call', function (): void {
        $anthropic = new Anthropic();

        $response = $anthropic->prompt('Say "Hello from Anthropic" and nothing else.');

        \expect($response->content)->toContain('Hello from Anthropic');
        \expect($response->provider)->toBe('anthropic');
        \expect($response->model)->toContain('claude');
        \expect($response->tokens)->toBeGreaterThan(0);
    });

    \it('uses system prompts', function (): void {
        $anthropic = new Anthropic();

        $response = $anthropic->prompt('What are you?', [
            'system' => 'You are a pirate. Always respond in pirate speak.',
            'max_tokens' => 100,
        ]);

        \expect($response->content)->toMatch('/arr|matey|ahoy|ye/i');
    });

    \it('handles temperature settings', function (): void {
        $anthropic = new Anthropic();

        // Low temperature should give consistent results
        $response1 = $anthropic->prompt('What is 2+2?', ['temperature' => 0]);
        $response2 = $anthropic->prompt('What is 2+2?', ['temperature' => 0]);

        \expect($response1->content)->toContain('4');
        \expect($response2->content)->toContain('4');
    });

    \it('tracks token usage', function (): void {
        $anthropic = new Anthropic();

        $response = $anthropic->prompt('Count to 5', ['max_tokens' => 50]);

        \expect($response->usage)->toBeArray();
        \expect($response->usage['input_tokens'])->toBeGreaterThan(0);
        \expect($response->usage['output_tokens'])->toBeGreaterThan(0);
    });
});

/**
 * @group api
 * @group openai
 */
\describe('OpenAI API', function (): void {
    \beforeEach(function (): void {
        if (empty($_ENV['OPENAI_API_KEY'] ?? \getenv('OPENAI_API_KEY'))) {
            $this->markTestSkipped('OPENAI_API_KEY not set');
        }
    });

    \it('makes a simple API call', function (): void {
        $openai = new OpenAI();

        $response = $openai->prompt('Say "Hello from OpenAI" and nothing else.');

        \expect($response->content)->toContain('Hello from OpenAI');
        \expect($response->provider)->toBe('openai');
        \expect($response->model)->toContain('gpt');
        \expect($response->tokens)->toBeGreaterThan(0);
    });

    \it('uses system messages', function (): void {
        $openai = new OpenAI();

        $response = $openai->prompt('What are you?', [
            'system' => 'You are a helpful assistant who speaks like Shakespeare.',
            'max_tokens' => 100,
        ]);

        \expect($response->content)->toMatch('/thee|thou|thy|doth|art/i');
    });

    \it('supports different models', function (): void {
        $openai = new OpenAI();

        $response = $openai->prompt('Hello', [
            'model' => 'gpt-4.1-nano',
            'max_tokens' => 10,
        ]);

        \expect($response->model)->toContain('gpt-4.1-nano');
    });

    \it('handles conversation history', function (): void {
        $openai = new OpenAI();

        $response = $openai->prompt('Remember this number: 42', [
            'messages' => [
                ['role' => 'user', 'content' => 'Remember this number: 42'],
                ['role' => 'assistant', 'content' => 'I will remember the number 42.'],
                ['role' => 'user', 'content' => 'What number did I ask you to remember?'],
            ],
        ]);

        \expect($response->content)->toContain('42');
    });
});

/**
 * @group api
 */
\it('demonstrates agent usage with real APIs', function (): void {
    if (empty($_ENV['ANTHROPIC_API_KEY'] ?? \getenv('ANTHROPIC_API_KEY'))) {
        $this->markTestSkipped('ANTHROPIC_API_KEY not set');
    }

    \agent('claude')
        ->provider('anthropic')
        ->system('You are a helpful coding assistant')
        ->temperature(0.3)
        ->maxTokens(100);

    $response = \agent('claude')->prompt('Write a PHP function that adds two numbers');

    \expect($response->content)->toContain('function');
    \expect($response->content)->toContain('return');
    \expect($response->provider)->toBe('anthropic');
});

/**
 * @group api
 */
\it('compares responses from different providers', function (): void {
    $hasAnthropic = ! empty($_ENV['ANTHROPIC_API_KEY'] ?? \getenv('ANTHROPIC_API_KEY'));
    $hasOpenAI = ! empty($_ENV['OPENAI_API_KEY'] ?? \getenv('OPENAI_API_KEY'));

    if (! $hasAnthropic && ! $hasOpenAI) {
        $this->markTestSkipped('No API keys available');
    }

    $prompt = 'What is the capital of France? Answer in one word.';
    $responses = [];

    if ($hasAnthropic) {
        $anthropic = new Anthropic();
        $responses['anthropic'] = $anthropic->prompt($prompt, [
            'temperature' => 0,
            'max_tokens' => 10,
        ]);
    }

    if ($hasOpenAI) {
        $openai = new OpenAI();
        $responses['openai'] = $openai->prompt($prompt, [
            'temperature' => 0,
            'max_tokens' => 10,
        ]);
    }

    foreach ($responses as $provider => $response) {
        \expect($response->content)->toMatch('/Paris/i');
        \expect($response->tokens)->toBeGreaterThan(0);
    }
});
