<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Orchestration\Delegation;
use Pagent\Registry;

test('it creates delegation', function (): void {
    $manager = testAgent('manager');

    $delegation = new Delegation($manager, 'Build feature X');

    expect($delegation)->toBeInstanceOf(Delegation::class);
});

test('it delegates task to worker', function (): void {
    $b1 = \agent('manager')
        ->provider('mock')
        ->system('You are a project manager');
    unset($b1);

    $b2 = \agent('worker')
        ->provider('mock')
        ->system('You are a developer');
    unset($b2);

    $manager = \agent('manager');

    $result = $manager->delegate('Write a function')
        ->to('worker')
        ->execute();

    expect($result)->toBeObject()
        ->and($result->task)->toBe('Write a function')
        ->and($result->worker)->toBe('worker')
        ->and($result->manager)->toBe('manager')
        ->and($result->worker_output)->toBeString()
        ->and($result->manager_review)->toBeString();
});

test('it supports supervision', function (): void {
    $mockProvider = mock();
    $managerAgent = new Agent('manager');
    $managerAgent->provider($mockProvider);
    Registry::set('manager', $managerAgent);

    $workerAgent = new Agent('worker');
    $workerAgent->provider($mockProvider);
    Registry::set('worker', $workerAgent);

    $manager = \agent('manager');
    $supervised = false;

    $result = $manager->delegate('Task')
        ->to('worker')
        ->supervise(function ($output, $task) use (&$supervised): bool {
            $supervised = true;

            return true;
        })
        ->execute();

    expect($supervised)->toBeTrue()
        ->and($result->supervised)->toBeTrue();
});

test('it calls onComplete callback', function (): void {
    $mockProvider = mock();
    $managerAgent = new Agent('manager');
    $managerAgent->provider($mockProvider);
    Registry::set('manager', $managerAgent);

    $workerAgent = new Agent('worker');
    $workerAgent->provider($mockProvider);
    Registry::set('worker', $workerAgent);

    $completed = false;

    \agent('manager')->delegate('Task')
        ->to('worker')
        ->onComplete(function ($result) use (&$completed): void {
            $completed = true;
        })
        ->execute();

    expect($completed)->toBeTrue();
});

test('it throws when supervisor rejects', function (): void {
    $mockProvider = mock();
    $managerAgent = new Agent('manager');
    $managerAgent->provider($mockProvider);
    Registry::set('manager', $managerAgent);

    $workerAgent = new Agent('worker');
    $workerAgent->provider($mockProvider);
    Registry::set('worker', $workerAgent);

    expect(fn () => \agent('manager')->delegate('Task')
        ->to('worker')
        ->supervise(fn ($output, $task) => false)
        ->execute())
        ->toThrow(RuntimeException::class, 'Supervisor rejected');
});
