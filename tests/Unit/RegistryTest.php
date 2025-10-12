<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Registry;

it('stores and retrieves agents', function (): void {
    $agent = new Agent('test-agent');

    Registry::set('test-agent', $agent);

    expect(Registry::get('test-agent'))->toBe($agent);
    expect(Registry::has('test-agent'))->toBeTrue();
});

it('returns null for non-existent agents', function (): void {
    expect(Registry::get('non-existent'))->toBeNull();
    expect(Registry::has('non-existent'))->toBeFalse();
});

it('returns all registered agents', function (): void {
    Registry::clear(); // Start fresh

    $agent1 = new Agent('agent-1');
    $agent2 = new Agent('agent-2');

    Registry::set('agent-1', $agent1);
    Registry::set('agent-2', $agent2);

    $all = Registry::all();

    expect($all)->toBeArray();
    expect($all)->toHaveCount(2);
    expect($all['agent-1'])->toBe($agent1);
    expect($all['agent-2'])->toBe($agent2);
});

it('clears all agents', function (): void {
    Registry::clear(); // Start fresh

    Registry::set('agent-1', new Agent('agent-1'));
    Registry::set('agent-2', new Agent('agent-2'));

    expect(Registry::all())->toHaveCount(2);

    Registry::clear();

    expect(Registry::all())->toBeEmpty();
    expect(Registry::has('agent-1'))->toBeFalse();
    expect(Registry::has('agent-2'))->toBeFalse();
});

it('overwrites existing agents with same name', function (): void {
    $agent1 = new Agent('duplicate');
    $agent2 = new Agent('duplicate');

    Registry::set('duplicate', $agent1);
    expect(Registry::get('duplicate'))->toBe($agent1);

    Registry::set('duplicate', $agent2);
    expect(Registry::get('duplicate'))->toBe($agent2);
});
