<?php

declare(strict_types=1);

use Pagent\Workflow\Metadata;
use Pagent\Workflow\StepMetadata;

/**
 * Metadata and StepMetadata Edge Case Tests
 *
 * Tests edge cases for metadata classes including factory methods and array conversion.
 */

// ===== Metadata Tests =====

test('Metadata creates with all parameters', function () {
    $metadata = new Metadata(
        totalTokens: 1000,
        duration: 5.5,
        stepsExecuted: 3,
        startedAt: '2025-01-01 10:00:00',
        completedAt: '2025-01-01 10:00:05'
    );

    expect($metadata->totalTokens)->toBe(1000)
        ->and($metadata->duration)->toBe(5.5)
        ->and($metadata->stepsExecuted)->toBe(3)
        ->and($metadata->startedAt)->toBe('2025-01-01 10:00:00')
        ->and($metadata->completedAt)->toBe('2025-01-01 10:00:05');
});

test('Metadata::create() factory method generates timestamps', function () {
    $metadata = Metadata::create(500, 2.5, 2);

    expect($metadata->totalTokens)->toBe(500)
        ->and($metadata->duration)->toBe(2.5)
        ->and($metadata->stepsExecuted)->toBe(2)
        ->and($metadata->startedAt)->toBeString()
        ->and($metadata->completedAt)->toBeString()
        ->and($metadata->startedAt)->toBe($metadata->completedAt);  // Same timestamp
});

test('Metadata::create() generates valid datetime format', function () {
    $metadata = Metadata::create(100, 1.0, 1);

    // Check format is Y-m-d H:i:s
    expect($metadata->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($metadata->completedAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

test('Metadata toArray() converts to snake_case keys', function () {
    $metadata = new Metadata(
        totalTokens: 1500,
        duration: 10.5,
        stepsExecuted: 5,
        startedAt: '2025-01-01 09:00:00',
        completedAt: '2025-01-01 09:00:10'
    );

    $array = $metadata->toArray();

    expect($array)->toBeArray()
        ->and($array)->toHaveKey('total_tokens')
        ->and($array)->toHaveKey('duration')
        ->and($array)->toHaveKey('steps_executed')
        ->and($array)->toHaveKey('started_at')
        ->and($array)->toHaveKey('completed_at')
        ->and($array['total_tokens'])->toBe(1500)
        ->and($array['duration'])->toBe(10.5)
        ->and($array['steps_executed'])->toBe(5)
        ->and($array['started_at'])->toBe('2025-01-01 09:00:00')
        ->and($array['completed_at'])->toBe('2025-01-01 09:00:10');
});

test('Metadata handles zero values', function () {
    $metadata = Metadata::create(0, 0.0, 0);

    expect($metadata->totalTokens)->toBe(0)
        ->and($metadata->duration)->toBe(0.0)
        ->and($metadata->stepsExecuted)->toBe(0);

    $array = $metadata->toArray();
    expect($array['total_tokens'])->toBe(0)
        ->and($array['duration'])->toBe(0.0)
        ->and($array['steps_executed'])->toBe(0);
});

test('Metadata handles large values', function () {
    $metadata = Metadata::create(1000000, 999.99, 100);

    expect($metadata->totalTokens)->toBe(1000000)
        ->and($metadata->duration)->toBe(999.99)
        ->and($metadata->stepsExecuted)->toBe(100);
});

test('Metadata handles negative duration', function () {
    // Edge case: what if duration is negative (shouldn't happen but test handling)
    $metadata = new Metadata(
        totalTokens: 100,
        duration: -1.5,
        stepsExecuted: 1,
        startedAt: '2025-01-01 10:00:00',
        completedAt: '2025-01-01 09:59:58'
    );

    expect($metadata->duration)->toBe(-1.5);
});

test('Metadata handles very small duration', function () {
    $metadata = Metadata::create(10, 0.001, 1);

    expect($metadata->duration)->toBe(0.001);
});

test('Metadata is readonly', function () {
    $metadata = Metadata::create(100, 1.0, 1);

    expect(fn () => $metadata->totalTokens = 200)
        ->toThrow(Error::class);
});

// ===== StepMetadata Tests =====

test('StepMetadata creates with all parameters', function () {
    $stepMeta = new StepMetadata(
        tokens: 250,
        duration: 1.5,
        timestamp: '2025-01-01 10:00:00'
    );

    expect($stepMeta->tokens)->toBe(250)
        ->and($stepMeta->duration)->toBe(1.5)
        ->and($stepMeta->timestamp)->toBe('2025-01-01 10:00:00');
});

test('StepMetadata::create() factory method generates timestamp', function () {
    $stepMeta = StepMetadata::create(100, 0.5);

    expect($stepMeta->tokens)->toBe(100)
        ->and($stepMeta->duration)->toBe(0.5)
        ->and($stepMeta->timestamp)->toBeString();
});

test('StepMetadata::create() generates valid datetime format', function () {
    $stepMeta = StepMetadata::create(50, 0.25);

    expect($stepMeta->timestamp)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

test('StepMetadata toArray() converts correctly', function () {
    $stepMeta = new StepMetadata(
        tokens: 300,
        duration: 2.5,
        timestamp: '2025-01-01 10:00:00'
    );

    $array = $stepMeta->toArray();

    expect($array)->toBeArray()
        ->and($array)->toHaveKey('tokens')
        ->and($array)->toHaveKey('duration')
        ->and($array)->toHaveKey('timestamp')
        ->and($array['tokens'])->toBe(300)
        ->and($array['duration'])->toBe(2.5)
        ->and($array['timestamp'])->toBe('2025-01-01 10:00:00');
});

test('StepMetadata handles zero values', function () {
    $stepMeta = StepMetadata::create(0, 0.0);

    expect($stepMeta->tokens)->toBe(0)
        ->and($stepMeta->duration)->toBe(0.0);

    $array = $stepMeta->toArray();
    expect($array['tokens'])->toBe(0)
        ->and($array['duration'])->toBe(0.0);
});

test('StepMetadata handles large values', function () {
    $stepMeta = StepMetadata::create(100000, 500.5);

    expect($stepMeta->tokens)->toBe(100000)
        ->and($stepMeta->duration)->toBe(500.5);
});

test('StepMetadata handles negative duration', function () {
    $stepMeta = new StepMetadata(
        tokens: 100,
        duration: -0.5,
        timestamp: '2025-01-01 10:00:00'
    );

    expect($stepMeta->duration)->toBe(-0.5);
});

test('StepMetadata handles very small duration', function () {
    $stepMeta = StepMetadata::create(5, 0.0001);

    expect($stepMeta->duration)->toBe(0.0001);
});

test('StepMetadata is readonly', function () {
    $stepMeta = StepMetadata::create(100, 1.0);

    expect(fn () => $stepMeta->tokens = 200)
        ->toThrow(Error::class);
});

test('StepMetadata handles fractional tokens', function () {
    // Edge case: tokens is int, but what if we try to create with float?
    // PHP will cast to int
    $stepMeta = new StepMetadata(
        tokens: 100,  // Must be int
        duration: 1.5,
        timestamp: '2025-01-01 10:00:00'
    );

    expect($stepMeta->tokens)->toBeInt()
        ->and($stepMeta->tokens)->toBe(100);
});

test('Metadata and StepMetadata timestamp formats are consistent', function () {
    $metadata = Metadata::create(100, 1.0, 1);
    $stepMeta = StepMetadata::create(50, 0.5);

    // Both should use same format (Y-m-d H:i:s)
    expect($metadata->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($stepMeta->timestamp)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

test('Metadata handles different start and completion times', function () {
    $metadata = new Metadata(
        totalTokens: 500,
        duration: 10.5,
        stepsExecuted: 3,
        startedAt: '2025-01-01 10:00:00',
        completedAt: '2025-01-01 10:00:10'
    );

    expect($metadata->startedAt)->toBe('2025-01-01 10:00:00')
        ->and($metadata->completedAt)->toBe('2025-01-01 10:00:10')
        ->and($metadata->startedAt)->not->toBe($metadata->completedAt);
});

test('Metadata toArray() preserves all data', function () {
    $metadata = Metadata::create(750, 7.5, 4);
    $array = $metadata->toArray();

    // Reconstruct from array data should match
    $reconstructed = new Metadata(
        totalTokens: $array['total_tokens'],
        duration: $array['duration'],
        stepsExecuted: $array['steps_executed'],
        startedAt: $array['started_at'],
        completedAt: $array['completed_at']
    );

    expect($reconstructed->totalTokens)->toBe($metadata->totalTokens)
        ->and($reconstructed->duration)->toBe($metadata->duration)
        ->and($reconstructed->stepsExecuted)->toBe($metadata->stepsExecuted);
});

test('StepMetadata toArray() preserves all data', function () {
    $stepMeta = StepMetadata::create(200, 2.0);
    $array = $stepMeta->toArray();

    $reconstructed = new StepMetadata(
        tokens: $array['tokens'],
        duration: $array['duration'],
        timestamp: $array['timestamp']
    );

    expect($reconstructed->tokens)->toBe($stepMeta->tokens)
        ->and($reconstructed->duration)->toBe($stepMeta->duration)
        ->and($reconstructed->timestamp)->toBe($stepMeta->timestamp);
});
