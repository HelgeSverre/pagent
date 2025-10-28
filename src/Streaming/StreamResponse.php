<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;

/**
 * Response object for streaming LLM responses
 */
final class StreamResponse
{
    private string $fullContent = '';

    private array $chunks = [];

    private ?array $usage = null;

    private ?string $stopReason = null;

    /**
     * @param  Generator<StreamChunk>  $stream
     */
    public function __construct(
        private readonly Generator $stream,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    /**
     * Get the underlying stream generator
     *
     * @return Generator<StreamChunk>
     */
    public function getStream(): Generator
    {
        return $this->stream;
    }

    /**
     * Iterate through all chunks and collect full content
     */
    public function collect(): string
    {
        foreach ($this->stream as $chunk) {
            if ($chunk->isText()) {
                $this->fullContent .= $chunk->content;
            }

            $this->chunks[] = $chunk;

            // Collect metadata from final chunks
            if ($chunk->isEnd()) {
                $this->usage = $chunk->getMetadata('usage');
                $this->stopReason = $chunk->getMetadata('stop_reason');
            }
        }

        return $this->fullContent;
    }

    /**
     * Stream chunks to a callback
     */
    public function streamTo(callable $callback): void
    {
        foreach ($this->stream as $chunk) {
            if ($chunk->isText()) {
                $this->fullContent .= $chunk->content;
            }

            $this->chunks[] = $chunk;

            // Call the callback with the chunk
            $callback($chunk);

            // Collect metadata from final chunks
            if ($chunk->isEnd()) {
                $this->usage = $chunk->getMetadata('usage');
                $this->stopReason = $chunk->getMetadata('stop_reason');
            }
        }
    }

    /**
     * Get the full collected content
     */
    public function getFullContent(): string
    {
        return $this->fullContent;
    }

    /**
     * Get all chunks received
     *
     * @return StreamChunk[]
     */
    public function getChunks(): array
    {
        return $this->chunks;
    }

    /**
     * Get usage statistics
     */
    public function getUsage(): ?array
    {
        return $this->usage;
    }

    /**
     * Get stop reason
     */
    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * Get provider name
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Get model name
     */
    public function getModel(): string
    {
        return $this->model;
    }
}
