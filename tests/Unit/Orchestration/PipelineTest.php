<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Orchestration\Pipeline;
use Pagent\Registry;

test('it creates pipeline', function (): void {
    $pipeline = \pipeline('test-pipeline');

    expect($pipeline)->toBeInstanceOf(Pipeline::class)
        ->and($pipeline->getName())->toBe('test-pipeline');
});

test('it runs pipeline with sequential agents', function (): void {
    $b1 = \agent('extractor')
        ->provider('mock')
        ->system('Extract key information');
    unset($b1);

    $b2 = \agent('formatter')
        ->provider('mock')
        ->system('Format the data');
    unset($b2);

    $result = \pipeline('data-processor')
        ->agent('extractor')
        ->agent('formatter')
        ->run('Process this data');

    expect($result)->toBeString();
});

test('it passes output between stages', function (): void {
    $mockProvider = mock();
    $mockProvider->setResponse('stage1', 'STAGE1_OUTPUT');
    $mockProvider->setResponse('STAGE1_OUTPUT', 'FINAL_OUTPUT');

    $stage1 = new Agent('stage1');
    $stage1->provider($mockProvider);
    Registry::set('stage1', $stage1);

    $stage2 = new Agent('stage2');
    $stage2->provider($mockProvider);
    Registry::set('stage2', $stage2);

    $result = \pipeline('test')
        ->agent('stage1')
        ->agent('stage2')
        ->run('stage1');

    expect($result)->toBe('FINAL_OUTPUT');
});

test('it supports transform functions between stages', function (): void {
    $b1 = \agent('parser')->provider('mock');
    unset($b1);
    $b2 = \agent('processor')->provider('mock');
    unset($b2);

    $result = \pipeline('transform-test')
        ->agent('parser')
        ->agent('processor', fn ($prev) => mb_strtoupper($prev))
        ->run('input');

    expect($result)->toBeString();
});

test('it stores results from each stage', function (): void {
    $b1 = \agent('agent1')->provider('mock');
    unset($b1);
    $b2 = \agent('agent2')->provider('mock');
    unset($b2);

    $pipe = \pipeline('results-test')
        ->agent('agent1')
        ->agent('agent2');

    $pipe->run('test');

    $results = $pipe->getResults();

    expect($results)->toHaveCount(2)
        ->and($results[0])->toHaveKeys(['stage', 'agent', 'input', 'output', 'response']);
});

test('it handles errors with error handler', function (): void {
    $faulty = new Agent('faulty');
    $faulty->provider(mock());
    Registry::set('faulty', $faulty);

    $pipeline = \pipeline('error-test')
        ->agent('faulty')
        ->onError(fn ($error, $stage, $agent) => "Error at stage {$stage}: {$agent}");

    $result = $pipeline
        ->agent('nonexistent')
        ->run('test');

    expect($result)->toContain('Error');
});

test('it throws exception without error handler', function (): void {
    $pipeline = \pipeline('no-handler')
        ->agent('nonexistent');

    expect(fn () => $pipeline->run('test'))
        ->toThrow(RuntimeException::class, 'Pipeline');
});
