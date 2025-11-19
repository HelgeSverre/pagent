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
 * - Track usage and cost
 * - Clean up resources
 */
final class StreamCompletedEvent extends Event
{
    /**
     * @param  Agent  $agent  The agent that executed the stream
     * @param  string  $fullContent  The complete streamed content
     * @param  int  $totalChunks  Total number of chunks received
     * @param  float  $durationMs  Stream duration in milliseconds
     * @param  string  $provider  Provider name (anthropic, openai, etc.)
     * @param  string  $model  Model name
     * @param  array<string, mixed>  $usage  Usage data from provider response
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly string $fullContent,
        public readonly int $totalChunks,
        public readonly float $durationMs,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly array $usage = [],
    ) {
        parent::__construct();
    }
}
