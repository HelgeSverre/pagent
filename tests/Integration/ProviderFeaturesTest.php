<?php

declare(strict_types=1);

/**
 * Provider-Specific Features Tests.
 *
 * These tests demonstrate the intentionally leaky abstraction,
 * showing how to use provider-specific features.
 *
 * @group api
 */

/**
 * @group api
 * @group anthropic
 */
describe('Anthropic-specific features', function (): void {
    beforeEach(function (): void {
        skipIfMissingAnthropicKey();
    });

    it('uses Claude 3 Opus for complex tasks', function (): void {
        $anthropic = anthropic();

        $response = $anthropic->prompt('Explain quantum computing in simple terms', [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 200,
            'temperature' => 0.1,
        ]);

        expect($response->model)->toContain('claude-3-5-haiku');
        expect($response->content)->toContain('quantum');
        expect(mb_strlen($response->content))->toBeGreaterThan(100);
    });

    it('uses Claude 3 Haiku for fast responses', function (): void {
        $anthropic = anthropic();

        $start = microtime(true);
        $response = $anthropic->prompt('Say yes or no', [
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => 10,
            'temperature' => 0,
        ]);
        $duration = microtime(true) - $start;

        expect($response->model)->toContain('claude-3-haiku');
        expect($duration)->toBeLessThan(2.0); // Haiku is fast
    });

    it('handles multi-turn conversations', function (): void {
        agent('claude-chat')
            ->provider('anthropic')
            ->system('You are a math tutor')
            ->model('claude-sonnet-4-20250514')
            ->temperature(0.3);

        $chat = agent('claude-chat');

        $chat->prompt('What is the Pythagorean theorem?');
        $response = $chat->prompt('Can you give me an example with numbers?');

        expect($response->content)->toMatch('/3.*4.*5|5.*12.*13/'); // Common examples
        expect($chat->messages)->toHaveCount(4); // 2 user + 2 assistant
    });
});

/**
 * @group api
 * @group openai
 */
describe('OpenAI-specific features', function (): void {
    beforeEach(function (): void {
        skipIfMissingOpenAiKey();
    });

    it('uses GPT-4 for complex reasoning', function (): void {
        $openai = openai();

        $response = $openai->prompt('Write a haiku about programming', [
            'model' => 'gpt-4.1-mini',
            'temperature' => 0.8,
            'max_tokens' => 50,
        ]);

        expect($response->model)->toContain('gpt-4.1-mini');
        $lines = explode("\n", mb_trim($response->content));
        expect(count($lines))->toBe(3); // Haiku has 3 lines
    });

    it('uses GPT-3.5 for fast responses', function (): void {
        $openai = openai();

        $start = microtime(true);
        $response = $openai->prompt('Complete this: The sky is', [
            'model' => 'gpt-3.5-turbo',
            'max_tokens' => 5,
            'temperature' => 0,
        ]);
        $duration = microtime(true) - $start;

        expect($response->model)->toContain('gpt-3.5');
        expect($response->content)->toContain('blue');
        expect($duration)->toBeLessThan(2.0);
    });

    it('demonstrates JSON mode (if available)', function (): void {
        $openai = openai();

        try {
            $response = $openai->prompt('List 3 programming languages as JSON', [
                'model' => 'gpt-3.5-turbo-1106', // Model that supports JSON mode
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 100,
            ]);

            $json = json_decode($response->content, true);
            expect($json)->toBeArray();
        } catch (Exception $e) {
            // JSON mode might not be available
            expect($e->getMessage())->toContain('API');
        }
    });
});

/**
 * @group api
 */
it('demonstrates provider switching based on task', function (): void {
    if (! hasAnthropicKey() || ! hasOpenAiKey()) {
        $this->markTestSkipped('Both API keys required for this test');
    }

    // Use Anthropic for detailed analysis
    agent('analyzer')
        ->provider('anthropic')
        ->model('claude-sonnet-4-20250514')
        ->system('You are a code analyzer');

    // Use OpenAI for quick summaries
    agent('summarizer')
        ->provider('openai')
        ->model('gpt-4.1-mini')
        ->system('You create brief summaries');

    $code = 'function add($a, $b) { return $a + $b; }';

    $analysis = agent('analyzer')->prompt("Analyze this code: {$code}");
    $summary = agent('summarizer')->prompt(
        "Summarize in 10 words or less: {$analysis->content}",
    );

    expect($analysis->provider)->toBe('anthropic');
    expect($analysis->content)->toContain('function');

    expect($summary->provider)->toBe('openai');
    expect(str_word_count($summary->content))->toBeLessThanOrEqual(15);
});
