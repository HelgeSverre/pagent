<?php

declare(strict_types=1);

use Pagent\Streaming\StreamChunk;

test('StreamChunk can be created with all properties', function () {
    $chunk = new StreamChunk(
        type: 'text',
        content: 'Hello',
        delta: ['text' => 'Hello'],
        metadata: ['index' => 0],
        isComplete: false
    );

    expect($chunk->type)->toBe('text')
        ->and($chunk->content)->toBe('Hello')
        ->and($chunk->delta)->toBe(['text' => 'Hello'])
        ->and($chunk->metadata)->toBe(['index' => 0])
        ->and($chunk->isComplete)->toBeFalse();
});

test('StreamChunk::isText() identifies text chunks correctly', function () {
    $textChunk = new StreamChunk('text', 'Hello');
    $otherChunk = new StreamChunk('start', '');

    expect($textChunk->isText())->toBeTrue()
        ->and($otherChunk->isText())->toBeFalse();
});

test('StreamChunk::isThinking() identifies thinking chunks correctly', function () {
    $thinkingChunk = new StreamChunk('thinking_delta', 'Let me think...');
    $textChunk = new StreamChunk('text', 'Hello');

    expect($thinkingChunk->isThinking())->toBeTrue()
        ->and($textChunk->isThinking())->toBeFalse();
});

test('StreamChunk::isToolCall() identifies tool call chunks correctly', function () {
    $toolChunk = new StreamChunk('tool_call', '{"arg": "value"}');
    $jsonDeltaChunk = new StreamChunk('input_json_delta', '{"arg"');
    $textChunk = new StreamChunk('text', 'Hello');

    expect($toolChunk->isToolCall())->toBeTrue()
        ->and($jsonDeltaChunk->isToolCall())->toBeTrue()
        ->and($textChunk->isToolCall())->toBeFalse();
});

test('StreamChunk::isStart() identifies start chunks correctly', function () {
    $start = new StreamChunk('start', '');
    $text = new StreamChunk('text', 'Hello');

    expect($start->isStart())->toBeTrue()
        ->and($text->isStart())->toBeFalse();
});

test('StreamChunk::isEnd() identifies end chunks correctly', function () {
    $done = new StreamChunk('done', '', isComplete: true);
    $complete = new StreamChunk('text', 'Hello', isComplete: true);
    $text = new StreamChunk('text', 'Hello');

    expect($done->isEnd())->toBeTrue()
        ->and($complete->isEnd())->toBeTrue()
        ->and($text->isEnd())->toBeFalse();
});

test('StreamChunk::isError() identifies error chunks correctly', function () {
    $error = new StreamChunk('error', 'Something went wrong');
    $text = new StreamChunk('text', 'Hello');

    expect($error->isError())->toBeTrue()
        ->and($text->isError())->toBeFalse();
});

test('StreamChunk::getText() returns content', function () {
    $chunk = new StreamChunk('text', 'Hello World');

    expect($chunk->getText())->toBe('Hello World');
});

test('StreamChunk::getMetadata() returns metadata values', function () {
    $chunk = new StreamChunk(
        'text',
        'Hello',
        metadata: ['index' => 0, 'model' => 'gpt-4']
    );

    expect($chunk->getMetadata('index'))->toBe(0)
        ->and($chunk->getMetadata('model'))->toBe('gpt-4')
        ->and($chunk->getMetadata('missing'))->toBeNull()
        ->and($chunk->getMetadata('missing', 'default'))->toBe('default');
});

test('StreamChunk::text() creates text chunk', function () {
    $chunk = StreamChunk::text('Hello', ['index' => 0]);

    expect($chunk->type)->toBe('text')
        ->and($chunk->content)->toBe('Hello')
        ->and($chunk->metadata)->toBe(['index' => 0])
        ->and($chunk->isText())->toBeTrue();
});

test('StreamChunk::start() creates start chunk', function () {
    $chunk = StreamChunk::start(['model' => 'gpt-4']);

    expect($chunk->type)->toBe('start')
        ->and($chunk->content)->toBe('')
        ->and($chunk->metadata)->toBe(['model' => 'gpt-4'])
        ->and($chunk->isStart())->toBeTrue();
});

test('StreamChunk::end() creates end chunk', function () {
    $chunk = StreamChunk::end(['usage' => ['total' => 100]]);

    expect($chunk->type)->toBe('done')
        ->and($chunk->content)->toBe('')
        ->and($chunk->isComplete)->toBeTrue()
        ->and($chunk->metadata)->toBe(['usage' => ['total' => 100]])
        ->and($chunk->isEnd())->toBeTrue();
});

test('StreamChunk::error() creates error chunk', function () {
    $chunk = StreamChunk::error('API Error', ['code' => 500]);

    expect($chunk->type)->toBe('error')
        ->and($chunk->content)->toBe('API Error')
        ->and($chunk->isComplete)->toBeTrue()
        ->and($chunk->metadata)->toBe(['code' => 500])
        ->and($chunk->isError())->toBeTrue();
});
