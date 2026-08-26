<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Contracts\Memory;
use Pagent\Orchestration\Handoff;
use Pagent\Registry;

test('it creates handoff from agent', function (): void {
    $agent = testAgent('source');

    $handoff = new Handoff($agent);

    expect($handoff)->toBeInstanceOf(Handoff::class);
});

test('it rejects an unregistered target without creating one', function (): void {
    $source = testAgent('source');

    expect(fn () => (new Handoff($source))->to('missing-target'))
        ->toThrow(RuntimeException::class, "Target agent 'missing-target' not found for handoff");
    expect(getAgent('missing-target'))->toBeNull();
});

test('it transfers to target agent', function (): void {
    $source = testAgent('source');
    $source->prompt('Hello, I need help with legal matters');

    $b = \agent('target')
        ->provider('mock')
        ->system('You are a legal expert');
    unset($b);

    $handoff = new Handoff($source);
    $target = $handoff->to('target')->transfer();

    expect($target)->toBeInstanceOf(Agent::class)
        ->and($target->getName())->toBe('target')
        ->and($target->messages)->not->toBeEmpty();
});

test('it includes conversation history in handoff', function (): void {
    $source = testAgent('source');
    $source->prompt('First message');
    $source->prompt('Second message');

    $targetAgent = new Agent('target');
    $targetAgent->provider(mock());
    Registry::set('target', $targetAgent);

    $handoff = new Handoff($source);
    $target = $handoff->to('target')->transfer();

    $contextMessage = $target->messages[0]['content'];

    expect($contextMessage)->toContain('First message')
        ->and($contextMessage)->toContain('Second message')
        ->and($contextMessage)->toContain('Previous conversation');
});

test('it includes handoff reason', function (): void {
    $source = testAgent('source');
    $source->prompt('Legal question');

    $targetAgent = new Agent('target');
    $targetAgent->provider(mock());
    Registry::set('target', $targetAgent);

    $handoff = new Handoff($source);
    $target = $handoff
        ->to('target')
        ->because('User needs legal expertise')
        ->transfer();

    $contextMessage = $target->messages[0]['content'];

    expect($contextMessage)->toContain('Handoff reason: User needs legal expertise');
});

test('repeated handoffs replace the transferred transcript instead of accumulating', function (): void {
    $source = testAgent('source');
    $source->prompt('First message');

    $targetAgent = new Agent('target');
    $targetAgent->provider(mock());
    Registry::set('target', $targetAgent);

    (new Handoff($source))->to('target')->transfer();
    $countAfterFirst = count($targetAgent->messages);

    $source->prompt('Second message');
    (new Handoff($source))->to('target')->transfer();

    expect($targetAgent->messages)->toHaveCount($countAfterFirst);

    $contextMessages = array_filter(
        $targetAgent->messages,
        fn (array $message): bool => is_string($message['content']) && str_contains($message['content'], 'Previous conversation'),
    );

    expect($contextMessages)->toHaveCount(1);

    $context = array_values($contextMessages)[0]['content'];
    expect($context)->toContain('Second message');
});

test('it uses agent handoff method', function (): void {
    $source = testAgent('source');
    $source->prompt('Help me');

    $targetAgent = new Agent('target');
    $targetAgent->provider(mock());
    Registry::set('target', $targetAgent);

    $target = $source->handoff('target', 'Needs specialist');

    expect($target)->toBeInstanceOf(Agent::class)
        ->and($target->getName())->toBe('target');
});

test('handoff merges and persists a lazily loaded target session', function (): void {
    $memory = new class implements Memory
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $sessions = [];

        public function load(string $sessionId): array
        {
            return $this->sessions[$sessionId] ?? [];
        }

        public function save(string $sessionId, array $messages): void
        {
            $this->sessions[$sessionId] = $messages;
        }

        public function delete(string $sessionId): void
        {
            unset($this->sessions[$sessionId]);
        }

        public function exists(string $sessionId): bool
        {
            return isset($this->sessions[$sessionId]);
        }

        public function prune(string $sessionId, int $maxMessages): array
        {
            return $this->sessions[$sessionId] = array_slice($this->load($sessionId), -$maxMessages);
        }
    };
    $memory->save('target-session', [['role' => 'assistant', 'content' => 'existing target context']]);

    $source = testAgent('session-source');
    $source->prompt('transferred question');

    $target = (new Agent('session-target'))
        ->provider(mock())
        ->memory($memory)
        ->sessionId('target-session');
    Registry::set('session-target', $target);

    (new Handoff($source))->to($target)->transfer();

    $persisted = $memory->load('target-session');
    expect($persisted[0]['content'])->toBe('existing target context')
        ->and($persisted[1]['content'])->toContain('transferred question');

    $target->prompt('continue');
    expect(array_column($target->getMessages(), 'content'))->toContain('existing target context');

    $fresh = (new Agent('fresh-target'))->memory($memory)->sessionId('target-session');
    expect($fresh->hasMessages())->toBeTrue()
        ->and($fresh->messageCount())->toBeGreaterThan(1)
        ->and(array_column($fresh->getMessages(), 'content'))
        ->toContain('existing target context')
        ->toContain($persisted[1]['content']);
});
