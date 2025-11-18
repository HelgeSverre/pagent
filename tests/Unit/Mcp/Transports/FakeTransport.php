<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Transports;

use Pagent\Mcp\McpTransport;

/**
 * Fake transport for testing MCP client logic without real I/O.
 */
final class FakeTransport implements McpTransport
{
    private bool $connected = false;

    /** @var array<int, array<string, mixed>> */
    private array $requestResponses = [];

    private int $requestIndex = 0;

    /**
     * Queue a response for the next sendRequest() call.
     *
     * @param  array<string, mixed>  $response
     */
    public function queueResponse(array $response): void
    {
        $this->requestResponses[] = $response;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function sendRequest(array $request): array
    {
        if (! $this->connected) {
            throw new \RuntimeException('Not connected');
        }

        if (! isset($this->requestResponses[$this->requestIndex])) {
            throw new \RuntimeException('No response queued for request');
        }

        return $this->requestResponses[$this->requestIndex++];
    }

    public function sendNotification(array $notification): void
    {
        if (! $this->connected) {
            throw new \RuntimeException('Not connected');
        }
        // Notifications don't return anything
    }
}
