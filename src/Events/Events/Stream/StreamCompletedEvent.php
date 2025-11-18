<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Stream;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired when a streaming response completes.
 *
 * Allows listeners to:
 * - Finalize stream processing
 * - Log total chunks and duration
 * - Track streaming metrics
 * - Clean up resources
 */
final class StreamCompletedEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $fullContent,
        public readonly int $totalChunks,
        public readonly float $durationMs,
    ) {
        parent::__construct();
    }
}
