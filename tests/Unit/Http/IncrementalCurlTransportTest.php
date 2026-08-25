<?php

declare(strict_types=1);

use Pagent\Http\CurlTransport;

/**
 * @return array{0: resource, 1: int}
 */
function startDelayedStreamServer(): array
{
    if (! function_exists('proc_open')) {
        test()->markTestSkipped('proc_open is required to run the local stream fixture.');
    }

    $port = random_int(20_000, 40_000);
    $fixture = realpath(__DIR__.'/../../Fixtures/delayed-stream-server.php');
    assert($fixture !== false);

    $process = proc_open(
        sprintf('%s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($fixture)),
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start delayed stream fixture server.');
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        set_error_handler(static fn (): bool => true);
        try {
            $socket = fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
        } finally {
            restore_error_handler();
        }

        if ($socket !== false) {
            fclose($socket);

            return [$process, $port];
        }

        usleep(20_000);
    }

    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('Delayed stream fixture did not become ready.');
}

/** @param resource $process */
function stopDelayedStreamServer($process): void
{
    proc_terminate($process);
    proc_close($process);
}

test('it yields the first HTTP bytes before a delayed response completes', function (): void {
    [$process, $port] = startDelayedStreamServer();
    $stream = null;

    try {
        $startedAt = microtime(true);
        $stream = (new CurlTransport)->streamJson('GET', "http://127.0.0.1:{$port}");
        $createdAt = microtime(true);

        expect($createdAt - $startedAt)->toBeLessThan(0.15)
            ->and($stream->status())->toBe(200);

        $chunks = $stream->chunks();
        $chunks->rewind();

        expect($chunks->current())->toBe("data: first\n\n")
            ->and(microtime(true) - $startedAt)->toBeLessThan(0.4);

        $chunks->next();

        expect($chunks->current())->toBe("data: second\n\n");
    } finally {
        $stream?->close();
        stopDelayedStreamServer($process);
    }
});

test('it can cancel a lazy HTTP stream without consuming it', function (): void {
    [$process, $port] = startDelayedStreamServer();

    try {
        $stream = (new CurlTransport)->streamJson('GET', "http://127.0.0.1:{$port}");
        $stream->close();

        expect($stream->isClosed())->toBeTrue();
    } finally {
        stopDelayedStreamServer($process);
    }
});

test('it materializes a complete resource for legacy consumers', function (): void {
    [$process, $port] = startDelayedStreamServer();
    $stream = null;

    try {
        $stream = (new CurlTransport)->streamJson('GET', "http://127.0.0.1:{$port}");

        expect(stream_get_contents($stream->resource()))->toBe("data: first\n\ndata: second\n\n")
            ->and($stream->isClosed())->toBeTrue();
    } finally {
        $stream?->close();
        stopDelayedStreamServer($process);
    }
});

test('it exposes final response metadata after following redirects', function (): void {
    [$process, $port] = startDelayedStreamServer();
    $stream = null;

    try {
        $stream = (new CurlTransport)->streamJson('GET', "http://127.0.0.1:{$port}/redirect");

        expect($stream->status())->toBe(200)
            ->and($stream->headers())->not->toHaveKey('x-redirect-only')
            ->and($stream->headers()['content-type'] ?? null)->toBe('text/event-stream;charset=UTF-8');
    } finally {
        $stream?->close();
        stopDelayedStreamServer($process);
    }
});
