<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Stream;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired for each chunk received during streaming.
 *
 * Allows listeners to:
 * - Process chunks in real-time
 * - Update UI progressively
 * - Track streaming performance
 * - Implement custom buffering
 */
final class StreamChunkEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $chunk,
        public readonly int $chunkNumber,
    ) {
        parent::__construct();
    }
}
