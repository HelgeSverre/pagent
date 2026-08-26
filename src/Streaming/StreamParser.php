<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;

/**
 * Parses a provider's raw byte stream into StreamChunk objects.
 *
 * Parsers signal API and malformed-data failures by yielding a
 * StreamChunk::error() chunk; StreamResponse converts error chunks into a
 * thrown exception at consumption time.
 */
interface StreamParser
{
    /**
     * @param  resource|iterable<string>  $stream  cURL stream or incremental byte chunks
     * @return Generator<StreamChunk>
     */
    public function parse($stream, string $model): Generator;
}
