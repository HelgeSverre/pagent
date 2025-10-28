<?php

declare(strict_types=1);

use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

test('StreamResponse can be created with generator', function () {
    $generator = (function () {
        yield StreamChunk::text('Hello');
        yield StreamChunk::text(' World');
    })();

    $response = new StreamResponse($generator, 'anthropic', 'claude-3');

    expect($response)->toBeInstanceOf(StreamResponse::class)
        ->and($response->getProvider())->toBe('anthropic')
        ->and($response->getModel())->toBe('claude-3');
});

test('StreamResponse::collect() accumulates all text content', function () {
    $generator = (function () {
        yield StreamChunk::text('Hello');
        yield StreamChunk::text(' ');
        yield StreamChunk::text('World');
        yield StreamChunk::end();
    })();

    $response = new StreamResponse($generator, 'anthropic', 'claude-3');
    $fullContent = $response->collect();

    expect($fullContent)->toBe('Hello World');
});

test('StreamResponse::collect() collects metadata from end chunk', function () {
    $generator = (function () {
        yield StreamChunk::text('Hello');
        yield StreamChunk::end([
            'usage' => ['input' => 10, 'output' => 5],
            'stop_reason' => 'end_turn',
        ]);
    })();

    $response = new StreamResponse($generator, 'anthropic', 'claude-3');
    $response->collect();

    expect($response->getUsage())->toBe(['input' => 10, 'output' => 5])
        ->and($response->getStopReason())->toBe('end_turn');
});

test('StreamResponse::streamTo() calls callback for each chunk', function () {
    $generator = (function () {
        yield StreamChunk::start();
        yield StreamChunk::text('Hello');
        yield StreamChunk::text(' World');
        yield StreamChunk::end();
    })();

    $response = new StreamResponse($generator, 'anthropic', 'claude-3');
    $chunks = [];

    $response->streamTo(function ($chunk) use (&$chunks) {
        $chunks[] = $chunk;
    });

    expect($chunks)->toHaveCount(4)
        ->and($chunks[0]->isStart())->toBeTrue()
        ->and($chunks[1]->content)->toBe('Hello')
        ->and($chunks[2]->content)->toBe(' World')
        ->and($chunks[3]->isEnd())->toBeTrue();
});

test('StreamResponse::streamTo() accumulates full content', function () {
    $generator = (function () {
        yield StreamChunk::text('Hello');
        yield StreamChunk::text(' World');
        yield StreamChunk::end();
    })();

    $response = new StreamResponse($generator, 'anthropic', 'claude-3');
    $response->streamTo(function () {});

    expect($response->getFullContent())->toBe('Hello World');
});

test('StreamResponse::getChunks() returns all chunks after collection', function () {
    $generator = (function () {
        yield StreamChunk::start();
        yield StreamChunk::text('Hello');
        yield StreamChunk::end();
    })();

    $response = new StreamResponse($generator, 'anthropic', 'claude-3');
    $response->collect();

    $chunks = $response->getChunks();

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0])->toBeInstanceOf(StreamChunk::class)
        ->and($chunks[1])->toBeInstanceOf(StreamChunk::class)
        ->and($chunks[2])->toBeInstanceOf(StreamChunk::class);
});

test('StreamResponse tracks provider and model info', function () {
    $generator = (function () {
        yield StreamChunk::text('Test');
    })();

    $response = new StreamResponse($generator, 'openai', 'gpt-4');

    expect($response->getProvider())->toBe('openai')
        ->and($response->getModel())->toBe('gpt-4');
});

test('StreamResponse ignores non-text chunks in content accumulation', function () {
    $generator = (function () {
        yield StreamChunk::start();
        yield StreamChunk::text('Hello');
        yield new StreamChunk('tool_call', '{"arg": "value"}');
        yield StreamChunk::text(' World');
        yield StreamChunk::end();
    })();

    $response = new StreamResponse($generator, 'anthropic', 'claude-3');
    $fullContent = $response->collect();

    expect($fullContent)->toBe('Hello World');
});
