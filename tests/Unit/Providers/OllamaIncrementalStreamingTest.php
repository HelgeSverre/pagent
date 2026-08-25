<?php

declare(strict_types=1);

use Pagent\Http\CurlTransport;
use Pagent\Providers\Ollama;

/** @return array{0: resource, 1: int} */
function startOllamaStreamingFixture(): array
{
    $port = random_int(40_001, 50_000);
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
        throw new RuntimeException('Unable to start Ollama streaming fixture.');
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
    throw new RuntimeException('Ollama streaming fixture did not become ready.');
}

/** @param resource $process */
function stopOllamaStreamingFixture($process): void
{
    proc_terminate($process);
    proc_close($process);
}

test('Ollama provider exposes parsed chunks before a delayed stream completes', function (): void {
    [$process, $port] = startOllamaStreamingFixture();

    try {
        $startedAt = microtime(true);
        $response = (new Ollama([
            'base_url' => "http://127.0.0.1:{$port}",
        ], new CurlTransport))->streamPrompt('Hello');

        $stream = $response->getStream();
        $stream->rewind();

        expect($stream->current()->isStart())->toBeTrue()
            ->and(microtime(true) - $startedAt)->toBeLessThan(0.4);

        while ($stream->valid()) {
            // Finish the stream so completion hooks and resources are released.
            $stream->next();
        }

        expect($response->getFullContent())->toBe('Hello')
            ->and($response->isComplete())->toBeTrue();
    } finally {
        $response?->cancel();
        stopOllamaStreamingFixture($process);
    }
});
