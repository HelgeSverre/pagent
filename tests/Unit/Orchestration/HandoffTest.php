<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Orchestration\Handoff;
use Pagent\Registry;

test('it creates handoff from agent', function (): void {
    $agent = testAgent('source');

    $handoff = new Handoff($agent);

    expect($handoff)->toBeInstanceOf(Handoff::class);
});

test('it transfers to target agent', function (): void {
    $source = testAgent('source');
    $source->prompt('Hello, I need help with legal matters');

    $b = agent('target')
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
