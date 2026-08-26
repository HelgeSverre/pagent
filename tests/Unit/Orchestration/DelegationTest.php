<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Orchestration\Delegation;
use Pagent\Orchestration\DelegationResult;
use Pagent\Registry;

test('it creates delegation', function (): void {
    $manager = testAgent('manager');

    $delegation = new Delegation($manager, 'Build feature X');

    expect($delegation)->toBeInstanceOf(Delegation::class);
});

test('it rejects an unregistered worker without creating one', function (): void {
    $manager = testAgent('manager');

    expect(fn () => (new Delegation($manager, 'Build feature X'))->to('missing-worker'))
        ->toThrow(RuntimeException::class, "Worker agent 'missing-worker' not found");
    expect(getAgent('missing-worker'))->toBeNull();
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

    expect($result)->toBeInstanceOf(DelegationResult::class)
        ->and($result->task)->toBe('Write a function')
        ->and($result->workerAgent)->toBe('worker')
        ->and($result->managerAgent)->toBe('manager')
        ->and($result->output)->toBeString()
        ->and($result->reviewed)->toBeFalse()
        ->and($result->worker)->toBe('worker')
        ->and($result->manager)->toBe('manager')
        ->and($result->worker_output)->toBe($result->workerOutput)
        ->and($result->manager_review)->toBe('')
        ->and($result->supervised)->toBeFalse();
});

test('it supports opt-in manager review', function (): void {
    $mockProvider = mock();
    $managerAgent = new Agent('manager');
    $managerAgent->provider($mockProvider);
    Registry::set('manager', $managerAgent);

    $workerAgent = new Agent('worker');
    $workerAgent->provider($mockProvider);
    Registry::set('worker', $workerAgent);

    $result = \agent('manager')->delegate('Task')
        ->to('worker')
        ->review()
        ->execute();

    expect($result->reviewed)->toBeTrue()
        ->and($result->output)->toBeString()
        ->and($result->managerReview)->toBe($result->output)
        ->and($result->manager_review)->toBe($result->output)
        ->and($result->worker_output)->toBe($result->workerOutput);
});

test('it does not pollute registered agent histories', function (): void {
    $mockProvider = mock();
    $managerAgent = new Agent('manager');
    $managerAgent->provider($mockProvider);
    Registry::set('manager', $managerAgent);

    $workerAgent = new Agent('worker');
    $workerAgent->provider($mockProvider);
    Registry::set('worker', $workerAgent);

    \agent('manager')->delegate('Task')
        ->to('worker')
        ->review()
        ->execute();

    expect($workerAgent->messages)->toBeEmpty()
        ->and($managerAgent->messages)->toBeEmpty();
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
        ->and($result->output)->toBeString()
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
