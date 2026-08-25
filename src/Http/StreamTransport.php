<?php

declare(strict_types=1);

namespace Pagent\Http;

use Closure;
use Generator;

final class StreamTransport
{
    private bool $closed = false;

    /**
     * @param  resource  $resource
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $info
     */
    public function __construct(
        private $resource,
        private int $status,
        private array $headers,
        private array $info = [],
        /** @var (Closure(): ?string)|null */
        private ?Closure $nextChunk = null,
        /** @var (Closure(): void)|null */
        private ?Closure $awaitHeaders = null,
        /** @var (Closure(): array{status: int, headers: array<string, string>, info: array<string, mixed>})|null */
        private ?Closure $metadata = null,
        /** @var (Closure(): void)|null */
        private ?Closure $closeCallback = null,
    ) {}

    /**
     * @return resource
     */
    public function resource()
    {
        $this->drain();
        rewind($this->resource);

        return $this->resource;
    }

    public function status(): int
    {
        $this->awaitHeaders();
        $this->refreshMetadata();

        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $this->awaitHeaders();
        $this->refreshMetadata();

        return $this->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $this->refreshMetadata();

        return $this->info;
    }

    public function getContent(): string
    {
        $this->drain();
        rewind($this->resource);

        return stream_get_contents($this->resource) ?: '';
    }

    /**
     * Yield response bytes as soon as cURL receives them.
     *
     * For transports created from an existing resource this reads that resource
     * in chunks. For live HTTP transports, abandoning the iterator cancels the
     * in-flight request; use {@see close()} when not iterating at all.
     *
     * @return Generator<int, string>
     */
    public function chunks(): Generator
    {
        if ($this->nextChunk === null) {
            rewind($this->resource);

            while (! feof($this->resource)) {
                $chunk = fread($this->resource, 8192);
                if ($chunk === false) {
                    throw new ConnectionException('Unable to read stream response.');
                }

                if ($chunk !== '') {
                    yield $chunk;
                }
            }

            return;
        }

        $completed = false;

        try {
            while (($chunk = ($this->nextChunk)()) !== null) {
                if ($chunk !== '') {
                    yield $chunk;
                }
            }

            $completed = true;
            $this->closed = true;
        } finally {
            if (! $completed) {
                $this->close();
            }
        }
    }

    /**
     * Cancel an in-flight request and release its cURL resources.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->closeCallback === null) {
            return;
        }

        $close = $this->closeCallback;
        $this->closeCallback = null;
        $close();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function awaitHeaders(): void
    {
        if ($this->awaitHeaders === null) {
            return;
        }

        ($this->awaitHeaders)();
        $this->awaitHeaders = null;
    }

    private function refreshMetadata(): void
    {
        if ($this->metadata === null) {
            return;
        }

        $metadata = ($this->metadata)();
        $this->status = $metadata['status'];
        $this->headers = $metadata['headers'];
        $this->info = $metadata['info'];
    }

    private function drain(): void
    {
        if ($this->nextChunk === null) {
            return;
        }

        foreach ($this->chunks() as $_) {
            // The cURL write callback has already copied the bytes into $resource.
        }
    }
}
