<?php

declare(strict_types=1);

namespace Pagent\Mcp\Transports;

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\Exceptions\McpTimeoutException;
use Pagent\Mcp\McpTransport;
use Throwable;

/**
 * HTTP with Server-Sent Events (SSE) transport for MCP.
 *
 * Implements the MCP HTTP/SSE transport specification where:
 * - Requests are sent via HTTP POST to /message endpoint
 * - Responses are received via Server-Sent Events on /sse endpoint
 * - Uses persistent SSE connection with polling for responses
 *
 * @see https://spec.modelcontextprotocol.io/specification/2024-11-05/basic/transports/#http-with-sse
 */
final class HttpSseTransport implements McpTransport
{
    private bool $connected = false;

    /** @var array<int|string, array<string, mixed>> */
    private array $responseBuffer = [];

    private string $sseBuffer = '';

    /** @var (callable(array<string, mixed>): void)|null */
    private $notificationHandler = null;

    /** @var resource|false|null */
    private $sseHandle = null;

    /**
     * The message endpoint URL received from the server via the 'endpoint' event.
     */
    private ?string $messageEndpoint = null;

    /**
     * Create a new HTTP/SSE transport.
     *
     * @param  string  $baseUrl  Base URL of the MCP server (e.g., 'http://localhost:3000')
     * @param  array<string, string>  $headers  Additional HTTP headers
     * @param  int  $timeoutMs  Request timeout in milliseconds
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $headers = [],
        private readonly int $timeoutMs = 30000,
    ) {}

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        // Initialize SSE stream connection
        $sseUrl = rtrim($this->baseUrl, '/').'/sse';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $this->buildHeaderStrings()),
                'timeout' => $this->timeoutMs / 1000,
            ],
        ]);

        // Use custom error handler to capture connection errors cleanly
        $errorMessage = null;
        set_error_handler(function (int $errno, string $errstr) use (&$errorMessage): bool {
            $errorMessage = $errstr;

            return true; // Suppress the error
        });

        try {
            $this->sseHandle = fopen($sseUrl, 'r', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($this->sseHandle === false) {
            $message = $errorMessage ?? "Failed to connect to SSE endpoint: {$sseUrl}";
            throw McpConnectionException::connectionFailed($message);
        }

        // Set stream to non-blocking for polling
        stream_set_blocking($this->sseHandle, false);

        $this->connected = true;

        // Wait for the endpoint event from the server
        try {
            $this->waitForEndpointEvent();
        } catch (Throwable $exception) {
            $this->disconnect();

            throw $exception;
        }
    }

    /**
     * Wait for the 'endpoint' event that provides the message POST URL.
     *
     * @throws McpTimeoutException
     */
    private function waitForEndpointEvent(): void
    {
        $startTime = microtime(true);
        $timeoutSeconds = $this->timeoutMs / 1000;

        while ($this->messageEndpoint === null) {
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $timeoutSeconds) {
                throw McpTimeoutException::requestTimedOut($this->timeoutMs);
            }

            $this->processSseStream();
            usleep(10000); // 10ms
        }
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        if ($this->sseHandle !== null && $this->sseHandle !== false && is_resource($this->sseHandle)) {
            fclose($this->sseHandle);
            $this->sseHandle = null;
        }

        $this->connected = false;
        $this->sseBuffer = '';
        $this->responseBuffer = [];
        $this->messageEndpoint = null;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function sendRequest(array $request): array
    {
        if (! $this->connected) {
            throw McpConnectionException::notConnected();
        }

        /** @var int|string|null $requestId */
        $requestId = $request['id'] ?? null;

        // Send request via HTTP POST
        $this->postMessage($request);

        // Wait for response from SSE stream
        return $this->waitForResponse($requestId);
    }

    /** @param (callable(array<string, mixed>): void)|null $handler */
    public function setNotificationHandler(?callable $handler): void
    {
        $this->notificationHandler = $handler;
    }

    public function sendNotification(array $notification): void
    {
        if (! $this->connected) {
            throw McpConnectionException::notConnected();
        }

        // Send notification via HTTP POST (no response expected)
        $this->postMessage($notification);
    }

    /**
     * Post a message to the MCP server.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    private function postMessage(array $message): void
    {
        if ($this->messageEndpoint === null) {
            throw McpConnectionException::connectionFailed('No message endpoint received from server');
        }

        // Build full URL from the endpoint path
        $messageUrl = rtrim($this->baseUrl, '/').$this->messageEndpoint;
        $jsonMessage = json_encode($message, JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", array_merge(
                    $this->buildHeaderStrings(),
                    ['Content-Type: application/json']
                )),
                'content' => $jsonMessage,
                'timeout' => $this->timeoutMs / 1000,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($messageUrl, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw McpConnectionException::connectionFailed($error['message'] ?? 'Failed to post message');
        }

        // Check HTTP status code from $http_response_header (set by file_get_contents)
        // @phpstan-ignore-next-line Variable $http_response_header is populated by file_get_contents
        $headers = $http_response_header ?? [];
        if ($headers !== []) {
            $statusLine = $headers[0] ?? '';
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
                $statusCode = (int) $matches[1];
                if ($statusCode !== 200 && $statusCode !== 202 && $statusCode !== 204) {
                    throw McpProtocolException::invalidResponse("HTTP {$statusCode}: {$response}");
                }
            }
        }
    }

    /**
     * Wait for a response matching the given request ID.
     *
     * Polls the SSE stream and processes events until the matching response is found.
     *
     * @return array<string, mixed>
     *
     * @throws McpTimeoutException
     * @throws McpProtocolException
     */
    private function waitForResponse(int|string|null $requestId): array
    {
        // Check if response is already buffered
        if (isset($this->responseBuffer[$requestId])) {
            $response = $this->responseBuffer[$requestId];
            unset($this->responseBuffer[$requestId]);

            return $response;
        }

        $startTime = microtime(true);
        $timeoutSeconds = $this->timeoutMs / 1000;

        while (true) {
            // Check timeout
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $timeoutSeconds) {
                throw McpTimeoutException::requestTimedOut($this->timeoutMs);
            }

            // Process SSE events
            $this->processSseStream();

            // Check if response arrived
            if (isset($this->responseBuffer[$requestId])) {
                $response = $this->responseBuffer[$requestId];
                unset($this->responseBuffer[$requestId]);

                return $response;
            }

            // Small delay to avoid busy waiting
            usleep(10000); // 10ms
        }
    }

    /**
     * Process incoming SSE stream data.
     */
    private function processSseStream(): void
    {
        $handle = $this->sseHandle;
        if (! is_resource($handle)) {
            return;
        }

        // Read available data from SSE stream
        while (! feof($handle)) {
            $chunk = fgets($handle);

            if ($chunk === false) {
                break; // No data available
            }

            // Normalize line endings (CRLF to LF)
            $this->sseBuffer .= str_replace("\r\n", "\n", $chunk);

            // Process complete SSE events (separated by double newline)
            while (($pos = strpos($this->sseBuffer, "\n\n")) !== false) {
                $eventBlock = substr($this->sseBuffer, 0, $pos);
                $this->sseBuffer = substr($this->sseBuffer, $pos + 2);

                $this->parseSseEvent($eventBlock);
            }
        }
    }

    /**
     * Parse an SSE event block.
     *
     * SSE format:
     *   event: message
     *   data: {"jsonrpc":"2.0",...}
     */
    private function parseSseEvent(string $eventBlock): void
    {
        if (trim($eventBlock) === '') {
            return;
        }

        $lines = explode("\n", $eventBlock);
        $eventType = null;
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === ':') {
                continue; // Empty line or comment
            }

            if (str_contains($line, ':')) {
                [$field, $value] = explode(':', $line, 2);
                $field = trim($field);
                $value = trim($value);

                if ($field === 'event') {
                    $eventType = $value;
                } elseif ($field === 'data') {
                    $data[] = $value;
                }
            }
        }

        // Process the event based on type
        if ($eventType === 'endpoint' && ! empty($data)) {
            // Server sends the message endpoint URL
            $this->messageEndpoint = implode('', $data);

            return;
        }

        if ($eventType === 'message' && ! empty($data)) {
            $jsonData = implode("\n", $data);

            // Use json_validate for fast early validation (PHP 8.3+)
            if (! json_validate($jsonData)) {
                return; // Invalid JSON, skip
            }

            $message = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($message) && isset($message['jsonrpc']) && $message['jsonrpc'] === '2.0') {
                // Messages without an id are server notifications, not responses
                if (! array_key_exists('id', $message) || $message['id'] === null) {
                    if ($this->notificationHandler !== null) {
                        ($this->notificationHandler)($message);
                    }

                    return;
                }

                // Store response indexed by request ID
                /** @var int|string $id */
                $id = $message['id'];
                $this->responseBuffer[$id] = $message;
            }
        }
    }

    /**
     * Build HTTP header strings.
     *
     * @return array<int, string>
     */
    private function buildHeaderStrings(): array
    {
        $headers = ['Accept: text/event-stream'];

        foreach ($this->headers as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        return $headers;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
