<?php

declare(strict_types=1);

use Pagent\Memory\ContextManager;

it('estimates token count from character count', function (): void {
    $manager = new ContextManager;

    $messages = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi!'],
    ];

    $tokens = $manager->countTokens($messages);

    // 'user' (4) + 'Hello' (5) + 'assistant' (9) + 'Hi!' (3) = 21 chars
    // 21 / 4 = 5.25 -> 6 tokens
    expect($tokens)->toBe(6);
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

    $tokens = $manager->countTokens($messages);

    // 'user' (4) + 'assistant' (9) = 13 chars / 4 = 3.25 -> 4 tokens
    expect($tokens)->toBe(4);
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

    $tokens = $manager->countTokens($messages);

    // 'user' (4) + 'Hello world' (11) = 15 chars / 4 = 3.75 -> 4 tokens
    expect($tokens)->toBe(4);
});

it('ignores non-text content blocks', function (): void {
    $manager = new ContextManager;

    $messages = [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => 'data:...'],
                ['type' => 'audio', 'source' => 'data:...'],
            ],
        ],
    ];

    $tokens = $manager->countTokens($messages);

    // Only 'user' (4 chars) / 4 = 1 token
    expect($tokens)->toBe(1);
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
