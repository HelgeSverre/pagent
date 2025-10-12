<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Registry;

\describe('Quick Wins: Better Error Messages', function (): void {
    \it('suggests similar tool names when tool not found', function (): void {
        $agent = \testAgent('bot');
        $agent->tool('calculate', 'Calculate', fn(int $a, int $b) => $a + $b);
        $agent->tool('multiply', 'Multiply', fn(int $a, int $b) => $a * $b);

        try {
            $agent->executeTool('calc', ['a' => 1, 'b' => 2]);
            \expect(false)->toBeTrue('Should have thrown exception');
        } catch (RuntimeException $e) {
            \expect($e->getMessage())
                ->toContain('calculate') // Suggested similar name
                ->toContain('Available tools:'); // Lists available tools
        }
    });

    \it('lists available tools when tool not found', function (): void {
        $agent = \testAgent('bot');
        $agent->tool('add', 'Add', fn(int $a, int $b) => $a + $b);
        $agent->tool('subtract', 'Subtract', fn(int $a, int $b) => $a - $b);

        try {
            $agent->executeTool('unknown', ['a' => 1, 'b' => 2]);
            \expect(false)->toBeTrue('Should have thrown exception');
        } catch (RuntimeException $e) {
            \expect($e->getMessage())
                ->toContain('Available tools: add, subtract');
        }
    });
});

\describe('Quick Wins: Reset Methods', function (): void {
    \it('can clear tools', function (): void {
        $agent = \testAgent('bot');
        $agent->tool('add', 'Add', fn(int $a, int $b) => $a + $b);

        \expect($agent->getTools())->toHaveCount(1);

        $agent->clearTools();

        \expect($agent->getTools())->toHaveCount(0);
    });

    \it('can clear guards', function (): void {
        $agent = \testAgent('bot');
        $agent->guard('pii');

        \expect($agent->getGuards())->toHaveCount(1);

        $agent->clearGuards();

        \expect($agent->getGuards())->toHaveCount(0);
    });

    \it('can clear middleware', function (): void {
        $agent = \testAgent('bot');
        $agent->middleware('logging');

        \expect($agent->getMiddleware())->toHaveCount(1);

        $agent->clearMiddleware();

        \expect($agent->getMiddleware())->toHaveCount(0);
    });

    \it('can reset agent completely', function (): void {
        $agent = \testAgent('bot');
        $agent->tool('add', 'Add', fn(int $a, int $b) => $a + $b);
        $agent->guard('pii');
        $agent->middleware('logging');
        $agent->messages = [['role' => 'user', 'content' => 'hello']];

        $agent->reset();

        \expect($agent->getTools())->toHaveCount(0);
        \expect($agent->getGuards())->toHaveCount(0);
        \expect($agent->getMiddleware())->toHaveCount(0);
        \expect($agent->messages)->toHaveCount(0);
    });

    \it('supports chaining reset methods', function (): void {
        $agent = \testAgent('bot');
        $agent->tool('add', 'Add', fn(int $a, int $b) => $a + $b);
        $agent->guard('pii');

        $result = $agent->clearTools()->clearGuards();

        \expect($result)->toBe($agent);
        \expect($agent->getTools())->toHaveCount(0);
        \expect($agent->getGuards())->toHaveCount(0);
    });
});

\describe('Quick Wins: Agent Cloning', function (): void {
    \it('can clone an agent', function (): void {
        $original = \testAgent('bot1');
        $original->system('You are helpful');
        $original->temperature(0.7);
        $original->tool('add', 'Add', fn(int $a, int $b) => $a + $b);

        $clone = $original->clone('bot2');

        \expect($clone)->toBeInstanceOf(Agent::class);
        \expect($clone->getName())->toBe('bot2');
        \expect($clone->getTools())->toHaveCount(1);
    });

    \it('cloned agent has fresh conversation', function (): void {
        $original = \testAgent('bot1');
        $original->messages = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi'],
        ];

        $clone = $original->clone('bot2');

        \expect($original->messages)->toHaveCount(2);
        \expect($clone->messages)->toHaveCount(0); // Fresh start
    });

    \it('cloned agent can be registered', function (): void {
        $original = \testAgent('bot1');
        $clone = $original->clone('bot2');

        Registry::set('bot2', $clone);

        \expect(\agent('bot2'))->toBe($clone);
    });
});

\describe('Quick Wins: Conversation Export/Import', function (): void {
    \it('can export conversation as JSON', function (): void {
        $agent = \testAgent('bot');
        $agent->messages = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi there'],
        ];

        $json = $agent->exportConversation();

        \expect($json)->toBeString();

        $data = \json_decode($json, true);

        \expect($data)->toHaveKey('agent');
        \expect($data)->toHaveKey('messages');
        \expect($data)->toHaveKey('exported_at');
        \expect($data['messages'])->toHaveCount(2);
    });

    \it('can import conversation from JSON', function (): void {
        $agent = \testAgent('bot');

        $json = \json_encode([
            'agent' => 'bot',
            'messages' => [
                ['role' => 'user', 'content' => 'hello'],
                ['role' => 'assistant', 'content' => 'hi'],
            ],
            'exported_at' => \date('c'),
        ]);

        $agent->importConversation($json);

        \expect($agent->messages)->toHaveCount(2);
        \expect($agent->messages[0]['content'])->toBe('hello');
    });

    \it('throws on invalid conversation data', function (): void {
        $agent = \testAgent('bot');

        \expect(fn() => $agent->importConversation('invalid json'))
            ->toThrow(RuntimeException::class);
    });

    \it('can export and re-import conversation', function (): void {
        $agent1 = \testAgent('bot1');
        $agent1->messages = [
            ['role' => 'user', 'content' => 'test'],
            ['role' => 'assistant', 'content' => 'response'],
        ];

        $json = $agent1->exportConversation();

        $agent2 = \testAgent('bot2');
        $agent2->importConversation($json);

        \expect($agent2->messages)->toEqual($agent1->messages);
    });
});

\describe('Quick Wins: Stats Tracking', function (): void {
    \it('provides agent statistics', function (): void {
        $agent = \testAgent('bot');
        $agent->tool('add', 'Add', fn(int $a, int $b) => $a + $b);
        $agent->guard('pii');
        $agent->messages = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi'],
            ['role' => 'user', 'content' => 'how are you'],
        ];

        $stats = $agent->getStats();

        \expect($stats)->toHaveKey('agent');
        \expect($stats)->toHaveKey('total_messages');
        \expect($stats)->toHaveKey('user_messages');
        \expect($stats)->toHaveKey('assistant_messages');
        \expect($stats)->toHaveKey('tools_registered');
        \expect($stats)->toHaveKey('guards_active');

        \expect($stats['total_messages'])->toBe(3);
        \expect($stats['user_messages'])->toBe(2);
        \expect($stats['assistant_messages'])->toBe(1);
        \expect($stats['tools_registered'])->toBe(1);
        \expect($stats['guards_active'])->toBe(1);
    });

    \it('provides guard statistics', function (): void {
        $agent = \testAgent('bot');
        $agent->guard('pii');
        $agent->guard('contentFilter');

        $guardStats = $agent->getGuardStats();

        \expect($guardStats)->toHaveCount(2);
        \expect($guardStats[0])->toHaveKey('name');
        \expect($guardStats[0])->toHaveKey('active');
        \expect($guardStats[0]['active'])->toBeTrue();
    });

    \it('returns empty stats for new agent', function (): void {
        $agent = \testAgent('bot');

        $stats = $agent->getStats();

        \expect($stats['total_messages'])->toBe(0);
        \expect($stats['tools_registered'])->toBe(0);
        \expect($stats['guards_active'])->toBe(0);
    });
});
