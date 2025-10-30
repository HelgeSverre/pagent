<?php

declare(strict_types=1);

namespace Pagent\Http;

final class StreamTransport
{
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
    ) {}

    /**
     * @return resource
     */
    public function resource()
    {
        return $this->resource;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return $this->info;
    }

    public function getContent(): string
    {
        rewind($this->resource);

        return stream_get_contents($this->resource) ?: '';
    }
}
