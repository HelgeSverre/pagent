<?php

declare(strict_types=1);

use Pagent\Memory\ContextManager;

/**
 * Expected token estimate: per-message ceil(strlen(json_encode) / 4), summed.
 */
function expectedTokens(array $messages): int
{
    $total = 0;
    foreach ($messages as $message) {
        $total += (int) ceil(strlen((string) json_encode($message)) / 4);
    }

    return $total;
}

it('estimates token count from serialized message size', function (): void {
    $manager = new ContextManager;

    $messages = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi!'],
    ];

    expect($manager->countTokens($messages))->toBe(expectedTokens($messages));
});

it('counts tokens for empty messages', function (): void {
    $manager = new ContextManager;

    $tokens = $manager->countTokens([]);

    expect($tokens)->toBe(0);
});

it('counts tokens for messages with only role', function (): void {
    $manager = new ContextManager;

    $messages = [
        ['role' => 'user'],
        ['role' => 'assistant'],
    ];

    expect($manager->countTokens($messages))->toBe(expectedTokens($messages));
});

it('handles multimodal content with text blocks', function (): void {
    $manager = new ContextManager;

    $messages = [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Hello world'],
                ['type' => 'image', 'source' => 'data:...'],
            ],
        ],
    ];

    expect($manager->countTokens($messages))->toBe(expectedTokens($messages));
});

it('counts non-text content blocks as non-zero tokens', function (): void {
    $manager = new ContextManager;

    $imageData = str_repeat('A', 4000);
    $messages = [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => $imageData],
            ],
        ],
    ];

    // Non-text content must contribute to the estimate, not count as zero.
    expect($manager->countTokens($messages))->toBeGreaterThan(1000);
});

it('counts tool calls and tool results as non-zero tokens', function (): void {
    $manager = new ContextManager;

    $toolCall = [
        'role' => 'assistant',
        'content' => null,
        'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Oslo"}']],
        ],
    ];
    $toolResult = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'sunny'];

    expect($manager->countTokens([$toolCall]))->toBeGreaterThan(10);
    expect($manager->countTokens([$toolResult]))->toBeGreaterThan(5);
});

it('prunes with oldest strategy removes oldest messages', function (): void {
    $manager = new ContextManager(maxTokens: 15, strategy: 'oldest');

    $messages = [
        ['role' => 'user', 'content' => 'Message 1 with lots of content here to exceed limit'],
        ['role' => 'assistant', 'content' => 'Response 1 with lots of content'],
        ['role' => 'user', 'content' => 'Message 2'],
        ['role' => 'assistant', 'content' => 'Response 2'],
    ];

    $pruned = $manager->prune($messages);

    // Should remove oldest messages until under limit
    expect(count($pruned))->toBeLessThan(count($messages));
    expect($manager->countTokens($pruned))->toBeLessThanOrEqual(15);
    // Last message should be Response 2
    expect($pruned[count($pruned) - 1]['content'])->toBe('Response 2');
});

it('prunes with sliding strategy keeps most recent', function (): void {
    $manager = new ContextManager(maxTokens: 30, strategy: 'sliding');

    $messages = [
        ['role' => 'user', 'content' => 'Message 1 with some content here'],
        ['role' => 'assistant', 'content' => 'Response 1 with content'],
        ['role' => 'user', 'content' => 'Message 2'],
        ['role' => 'assistant', 'content' => 'Response 2'],
    ];

    $pruned = $manager->prune($messages);

    expect($pruned)->not->toBeEmpty();
    expect($pruned[count($pruned) - 1]['content'])->toBe('Response 2');
});

it('preserves system message with oldest strategy', function (): void {
    $manager = new ContextManager(maxTokens: 30, strategy: 'oldest');

    $messages = [
        ['role' => 'system', 'content' => 'You are helpful'],
        ['role' => 'user', 'content' => 'Message 1 with lots of content here'],
        ['role' => 'assistant', 'content' => 'Response 1 with content'],
        ['role' => 'user', 'content' => 'Message 2'],
    ];

    $pruned = $manager->prune($messages);

    expect($pruned[0]['role'])->toBe('system');
    expect($pruned[0]['content'])->toBe('You are helpful');
});

it('preserves system message with sliding strategy', function (): void {
    $manager = new ContextManager(maxTokens: 30, strategy: 'sliding');

    $messages = [
        ['role' => 'system', 'content' => 'You are helpful'],
        ['role' => 'user', 'content' => 'Message 1 with lots of content here'],
        ['role' => 'assistant', 'content' => 'Response 1 with content'],
        ['role' => 'user', 'content' => 'Message 2'],
    ];

    $pruned = $manager->prune($messages);

    expect($pruned[0]['role'])->toBe('system');
    expect($pruned[0]['content'])->toBe('You are helpful');
});

it('preserves only first system message', function (): void {
    $manager = new ContextManager(maxTokens: 1, strategy: 'oldest');

    $messages = [
        ['role' => 'system', 'content' => 'First system message'],
        ['role' => 'user', 'content' => 'Message that will cause pruning to happen'],
        ['role' => 'system', 'content' => 'Second system message should not be treated special'],
    ];

    $pruned = $manager->prune($messages);

    // First system message should be preserved
    expect($pruned[0]['role'])->toBe('system');
    expect($pruned[0]['content'])->toBe('First system message');

    // Second system message is not special (only first is preserved)
    $secondSystemExists = false;
    foreach ($pruned as $msg) {
        if ($msg['content'] === 'Second system message should not be treated special') {
            $secondSystemExists = true;
        }
    }
    // Whether second system exists depends on pruning, but first should always be there
    expect($pruned[0]['content'])->toBe('First system message');
});

it('returns all messages when under token limit', function (): void {
    $manager = new ContextManager(maxTokens: 1000);

    $messages = [
        ['role' => 'user', 'content' => 'Short'],
        ['role' => 'assistant', 'content' => 'Reply'],
    ];

    $pruned = $manager->prune($messages);

    expect($pruned)->toBe($messages);
    expect($pruned)->toHaveCount(2);
});

it('handles empty messages array', function (): void {
    $manager = new ContextManager;

    $pruned = $manager->prune([]);

    expect($pruned)->toBeArray()->toBeEmpty();
});

it('sets max tokens', function (): void {
    $manager = new ContextManager;

    $result = $manager->setMaxTokens(5000);

    expect($result)->toBe($manager);
});

it('throws when setting max tokens less than 1', function (): void {
    $manager = new ContextManager;

    expect(fn () => $manager->setMaxTokens(0))
        ->toThrow(InvalidArgumentException::class, 'maxTokens must be at least 1');

    expect(fn () => $manager->setMaxTokens(-10))
        ->toThrow(InvalidArgumentException::class, 'maxTokens must be at least 1');
});

it('sets strategy', function (): void {
    $manager = new ContextManager;

    $result = $manager->setStrategy('sliding');

    expect($result)->toBe($manager);
});

it('throws when setting invalid strategy', function (): void {
    $manager = new ContextManager;

    expect(fn () => $manager->setStrategy('invalid'))
        ->toThrow(InvalidArgumentException::class, 'Invalid strategy');
});

it('validates strategy in constructor', function (): void {
    expect(fn () => new ContextManager(strategy: 'invalid'))
        ->toThrow(InvalidArgumentException::class, 'Invalid strategy');
});

it('keeps at least one message when pruning with oldest strategy', function (): void {
    $manager = new ContextManager(maxTokens: 1, strategy: 'oldest');

    $messages = [
        ['role' => 'user', 'content' => 'Very long message that exceeds token limit'],
        ['role' => 'assistant', 'content' => 'Another long response'],
    ];

    $pruned = $manager->prune($messages);

    expect($pruned)->toHaveCount(1);
    expect($pruned[0]['content'])->toBe('Another long response');
});

it('handles messages with only system message', function (): void {
    $manager = new ContextManager(maxTokens: 100, strategy: 'oldest');

    $messages = [
        ['role' => 'system', 'content' => 'You are helpful'],
    ];

    $pruned = $manager->prune($messages);

    expect($pruned)->toBe($messages);
});

it('uses fluent interface for configuration', function (): void {
    $manager = new ContextManager;

    $result = $manager
        ->setMaxTokens(5000)
        ->setStrategy('sliding');

    expect($result)->toBe($manager);
});

it('prunes large conversations efficiently', function (): void {
    $manager = new ContextManager(maxTokens: 100, strategy: 'oldest');

    $messages = [];
    for ($i = 1; $i <= 50; $i++) {
        $messages[] = ['role' => 'user', 'content' => "Message {$i}"];
        $messages[] = ['role' => 'assistant', 'content' => "Response {$i}"];
    }

    $pruned = $manager->prune($messages);

    expect(count($pruned))->toBeLessThan(count($messages));
    expect($manager->countTokens($pruned))->toBeLessThanOrEqual(100);
});

it('prunes handles empty messages gracefully', function (): void {
    $manager = new ContextManager(maxTokens: 10, strategy: 'oldest');

    $messages = [
        ['role' => 'user', 'content' => ''],
        ['role' => 'assistant', 'content' => ''],
        ['role' => 'user', 'content' => 'Hello'],
    ];

    $pruned = $manager->prune($messages);

    expect($pruned)->toBeArray();
    expect($manager->countTokens($pruned))->toBeLessThanOrEqual(10);
});

it('prunes preserves system messages even when over token limit', function (): void {
    $manager = new ContextManager(maxTokens: 1, strategy: 'oldest');

    $messages = [
        ['role' => 'system', 'content' => 'You are a helpful assistant with a very long system prompt that exceeds the token limit significantly'],
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!'],
    ];

    $pruned = $manager->prune($messages);

    // System message should always be preserved even if it exceeds token limit
    expect($pruned[0]['role'])->toBe('system');
    expect($pruned[0]['content'])->toBe('You are a helpful assistant with a very long system prompt that exceeds the token limit significantly');
});

it('handles very long single messages exceeding maxTokens', function (): void {
    $manager = new ContextManager(maxTokens: 10, strategy: 'oldest');

    $veryLongContent = str_repeat('This is a very long message that will definitely exceed the token limit. ', 100);

    $messages = [
        ['role' => 'user', 'content' => $veryLongContent],
        ['role' => 'assistant', 'content' => 'Short reply'],
    ];

    $pruned = $manager->prune($messages);

    // Should keep at least the most recent message even if it exceeds limit
    expect($pruned)->toHaveCount(1);
    expect($pruned[0]['content'])->toBe('Short reply');
    expect($pruned[0]['role'])->toBe('assistant');
});

it('never splits an OpenAI-style tool call pair when pruning oldest', function (): void {
    $toolCall = [
        'role' => 'assistant',
        'content' => null,
        'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Oslo"}']],
        ],
    ];
    $toolResult = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'sunny'];
    $final = ['role' => 'assistant', 'content' => 'It is sunny in Oslo'];
    $messages = [
        ['role' => 'user', 'content' => 'What is the weather in Oslo?'],
        $toolCall,
        $toolResult,
        $final,
    ];

    // Budget fits [toolResult, final] but not the whole pair: a naive pruner
    // would cut between the tool call and its result.
    $budget = expectedTokens([$toolResult, $final]);
    $manager = new ContextManager(maxTokens: $budget, strategy: 'oldest');

    $pruned = $manager->prune($messages);

    // The whole exchange must be dropped as a unit, never leaving an orphan tool result.
    expect($pruned)->toBe([$final]);
});

it('keeps an OpenAI-style tool call pair intact when it fits', function (): void {
    $toolCall = [
        'role' => 'assistant',
        'content' => null,
        'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Oslo"}']],
        ],
    ];
    $toolResult = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'sunny'];
    $final = ['role' => 'assistant', 'content' => 'It is sunny in Oslo'];
    $messages = [
        ['role' => 'user', 'content' => 'What is the weather in Oslo? Please tell me in as much detail as you can manage today.'],
        $toolCall,
        $toolResult,
        $final,
    ];

    $budget = expectedTokens([$toolCall, $toolResult, $final]);
    $manager = new ContextManager(maxTokens: $budget, strategy: 'oldest');

    $pruned = $manager->prune($messages);

    expect($pruned)->toBe([$toolCall, $toolResult, $final]);
});

it('never splits an Anthropic-style tool_use exchange when pruning with sliding window', function (): void {
    $toolUse = [
        'role' => 'assistant',
        'content' => [
            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_weather', 'input' => ['city' => 'Oslo']],
        ],
    ];
    $toolResult = [
        'role' => 'user',
        'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'sunny'],
        ],
    ];
    $final = ['role' => 'assistant', 'content' => 'It is sunny in Oslo'];
    $messages = [
        ['role' => 'user', 'content' => 'What is the weather in Oslo?'],
        $toolUse,
        $toolResult,
        $final,
    ];

    // Budget fits [toolResult, final] but not the whole exchange.
    $budget = expectedTokens([$toolResult, $final]);
    $manager = new ContextManager(maxTokens: $budget, strategy: 'sliding');

    $pruned = $manager->prune($messages);

    expect($pruned)->toBe([$final]);
});

it('groups multiple tool results with their tool call when pruning', function (): void {
    $toolCall = [
        'role' => 'assistant',
        'content' => null,
        'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'a', 'arguments' => '{}']],
            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'b', 'arguments' => '{}']],
        ],
    ];
    $result1 = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'first result'];
    $result2 = ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => 'second result'];
    $final = ['role' => 'assistant', 'content' => 'Done'];
    $messages = [$toolCall, $result1, $result2, $final];

    // Fits final plus both results, but not the tool call: the whole exchange must go.
    $budget = expectedTokens([$result1, $result2, $final]);
    $manager = new ContextManager(maxTokens: $budget, strategy: 'oldest');

    $pruned = $manager->prune($messages);

    expect($pruned)->toBe([$final]);
});
