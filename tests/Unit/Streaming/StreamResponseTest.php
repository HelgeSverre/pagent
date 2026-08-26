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

test('StreamResponse runs completion handlers exactly once after natural completion', function () {
    $calls = 0;
    $response = new StreamResponse((function () {
        yield StreamChunk::text('done');
        yield StreamChunk::end();
    })(), 'mock', 'mock');

    $response->onComplete(function (StreamResponse $completed) use (&$calls): void {
        $calls++;
        expect($completed->getFullContent())->toBe('done')
            ->and($completed->isComplete())->toBeTrue();
    });

    expect($response->collect())->toBe('done')
        ->and($calls)->toBe(1)
        ->and($response->isComplete())->toBeTrue();
});

test('StreamResponse lets a policy inspect chunks before delivery', function () {
    $seenByPolicy = [];
    $delivered = [];
    $response = new StreamResponse((function () {
        yield StreamChunk::text('first');
        yield StreamChunk::text('second');
        yield StreamChunk::end();
    })(), 'mock', 'mock');

    $response->consume(
        beforeDelivery: function (StreamChunk $chunk) use (&$seenByPolicy): void {
            $seenByPolicy[] = $chunk->content;
        },
        onChunk: function (StreamChunk $chunk) use (&$delivered): void {
            $delivered[] = $chunk->content;
        },
    );

    expect($seenByPolicy)->toBe(['first', 'second', ''])
        ->and($delivered)->toBe(['first', 'second', '']);
});

test('StreamResponse reports parser failures without treating them as cancellation', function () {
    $reported = null;
    $response = new StreamResponse((function () {
        yield StreamChunk::text('partial');
        throw new RuntimeException('broken stream');
    })(), 'mock', 'mock');

    $response->onError(function (Throwable $error) use (&$reported): void {
        $reported = $error->getMessage();
    });

    expect(fn () => $response->collect())->toThrow(RuntimeException::class, 'broken stream')
        ->and($reported)->toBe('broken stream')
        ->and($response->isComplete())->toBeFalse()
        ->and($response->isCancelled())->toBeFalse();
});

test('StreamResponse treats provider error chunks as failed streams', function () {
    $reported = null;
    $response = new StreamResponse((function () {
        yield StreamChunk::text('partial');
        yield StreamChunk::error('rate limited');
    })(), 'mock', 'mock');

    $response->onError(function (Throwable $error) use (&$reported): void {
        $reported = $error->getMessage();
    });

    expect(fn () => $response->collect())->toThrow(RuntimeException::class, 'Provider stream failed: rate limited')
        ->and($reported)->toBe('Provider stream failed: rate limited')
        ->and($response->isComplete())->toBeFalse();
});

test('StreamResponse does not complete a truncated provider stream', function () {
    $response = new StreamResponse((function () {
        yield StreamChunk::text('partial');
    })(), 'mock', 'mock');

    expect(fn () => $response->collect())
        ->toThrow(RuntimeException::class, 'ended without a terminal chunk')
        ->and($response->isComplete())->toBeFalse();
});

test('StreamResponse reports completion hook failures as stream failures', function () {
    $failed = false;
    $response = new StreamResponse((function () {
        yield StreamChunk::end();
    })(), 'mock', 'mock');

    $response->onComplete(function (): void {
        throw new RuntimeException('persistence failed');
    })->onError(function () use (&$failed): void {
        $failed = true;
    });

    expect(fn () => $response->collect())->toThrow(RuntimeException::class, 'persistence failed')
        ->and($failed)->toBeTrue()
        ->and($response->isComplete())->toBeFalse();
});

test('StreamResponse cancellation releases the underlying stream exactly once', function () {
    $cancelled = 0;
    $response = new StreamResponse((function () {
        yield StreamChunk::text('partial');
        yield StreamChunk::text('never consumed');
    })(), 'mock', 'mock', function () use (&$cancelled): void {
        $cancelled++;
    });

    $response->cancel();
    $response->cancel();

    expect($cancelled)->toBe(1)
        ->and($response->isCancelled())->toBeTrue();
});

test('StreamResponse cancellation always releases the transport after observer failures', function () {
    $transportClosed = false;
    $response = new StreamResponse((function () {
        yield StreamChunk::text('partial');
    })(), 'mock', 'mock', function () use (&$transportClosed): void {
        $transportClosed = true;
    });

    $response->onCancel(function (): void {
        throw new RuntimeException('rollback observer failed');
    });

    $response->cancel();

    expect($transportClosed)->toBeTrue();
});

test('StreamResponse settles before delivering its terminal chunk', function () {
    $released = 0;
    $response = new StreamResponse((function () {
        yield StreamChunk::text('done');
        yield StreamChunk::end();
    })(), 'mock', 'requested', releaser: function () use (&$released): void {
        $released++;
    });

    foreach ($response->getStream() as $chunk) {
        if ($chunk->isEnd()) {
            expect($response->isComplete())->toBeTrue();
            break;
        }
    }

    expect($response->isComplete())->toBeTrue()
        ->and($response->isCancelled())->toBeFalse()
        ->and($released)->toBe(1);
});

test('StreamResponse releases its transport on failure', function () {
    $released = 0;
    $response = new StreamResponse((function () {
        yield StreamChunk::error('broken');
    })(), 'mock', 'mock', releaser: function () use (&$released): void {
        $released++;
    });

    expect(fn () => $response->collect())->toThrow(RuntimeException::class, 'broken')
        ->and($released)->toBe(1)
        ->and($response->isCancelled())->toBeFalse();
});

test('StreamResponse keeps cancellation and generic release callbacks distinct', function (): void {
    $cancelled = 0;
    $released = 0;
    $response = new StreamResponse(
        (function (): Generator {
            yield StreamChunk::end();
        })(),
        'mock',
        'mock',
        canceller: function () use (&$cancelled): void {
            $cancelled++;
        },
        releaser: function () use (&$released): void {
            $released++;
        },
    );

    $response->collect();

    expect($cancelled)->toBe(0)
        ->and($released)->toBe(1);
});

test('StreamResponse cannot be consumed after cancellation', function (): void {
    $response = new StreamResponse((function (): Generator {
        yield StreamChunk::text('must not leak');
        yield StreamChunk::end();
    })(), 'mock', 'mock');

    $response->cancel();

    expect(fn () => $response->collect())
        ->toThrow(LogicException::class, 'cancelled StreamResponse cannot be consumed')
        ->and($response->getFullContent())->toBe('')
        ->and($response->getChunkCount())->toBe(0);
});

test('StreamResponse stops an active iterator after cancellation', function (): void {
    $response = new StreamResponse((function (): Generator {
        yield StreamChunk::text('first');
        yield StreamChunk::text('must not leak');
        yield StreamChunk::end();
    })(), 'mock', 'mock');
    $stream = $response->getStream();
    $stream->rewind();

    expect($stream->current()->content)->toBe('first');

    $response->cancel();
    $stream->next();

    expect($stream->valid())->toBeFalse()
        ->and($response->getFullContent())->toBe('first')
        ->and($response->getChunkCount())->toBe(1);
});

test('StreamResponse can count chunks without retaining every chunk', function () {
    $response = new StreamResponse((function () {
        yield StreamChunk::text('one');
        yield StreamChunk::text('two');
        yield StreamChunk::end();
    })(), 'mock', 'mock', retainChunks: false);

    expect($response->collect())->toBe('onetwo')
        ->and($response->getChunks())->toBe([])
        ->and($response->getChunkCount())->toBe(3);
});

test('StreamResponse exposes the provider-reported model separately from the requested model', function () {
    $response = new StreamResponse((function () {
        yield StreamChunk::start(['model' => 'actual-model']);
        yield StreamChunk::end(['model' => 'actual-model']);
    })(), 'mock', 'requested-model');

    $response->collect();

    expect($response->getRequestedModel())->toBe('requested-model')
        ->and($response->getModel())->toBe('actual-model');
});

test('StreamResponse assembles and normalizes streamed tool calls', function () {
    $response = new StreamResponse((function () {
        yield new StreamChunk('tool_call', '{"city"', metadata: [
            'tool_call_id' => 'call_1',
            'tool_name' => 'weather',
            'index' => 0,
        ]);
        yield new StreamChunk('tool_call', ':"Oslo"}', metadata: [
            'tool_call_id' => 'call_1',
            'index' => 0,
        ]);
        yield StreamChunk::end();
    })(), 'mock', 'mock');

    $response->collect();

    expect($response->getToolCalls())->toBe([[
        'id' => 'call_1',
        'name' => 'weather',
        'arguments' => ['city' => 'Oslo'],
        'raw_arguments' => '{"city":"Oslo"}',
    ]]);
});

test('StreamResponse preserves zero-argument and multiple streamed tool calls', function () {
    $response = new StreamResponse((function () {
        yield new StreamChunk('tool_call', '', metadata: [
            'tool_call_id' => 'call_1',
            'tool_name' => 'now',
            'index' => 0,
        ]);
        yield new StreamChunk('tool_call', '{}', metadata: [
            'tool_call_id' => 'call_2',
            'tool_name' => 'health',
            'index' => 1,
        ]);
        yield StreamChunk::end();
    })(), 'mock', 'mock');

    $response->collect();

    expect($response->getToolCalls())->toMatchArray([
        ['id' => 'call_1', 'name' => 'now', 'arguments' => [], 'raw_arguments' => ''],
        ['id' => 'call_2', 'name' => 'health', 'arguments' => [], 'raw_arguments' => '{}'],
    ]);
});

test('StreamResponse gives missing provider tool ids a stable unique id', function () {
    $response = new StreamResponse((function () {
        yield new StreamChunk('tool_call', '{}', metadata: [
            'tool_name' => 'health',
            'index' => 0,
        ]);
        yield StreamChunk::end();
    })(), 'mock', 'mock');

    $response->collect();
    $first = $response->getToolCalls()[0]['id'];
    $second = $response->getToolCalls()[0]['id'];

    expect($first)->toStartWith('call_')
        ->and($second)->toBe($first);
});
