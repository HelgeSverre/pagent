<?php

declare(strict_types=1);

namespace Pagent\Mcp\Transports;

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\Exceptions\McpTimeoutException;
use Pagent\Mcp\McpTransport;

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

    private ?string $sseBuffer = '';

    /** @var resource|false|null */
    private $sseHandle = null;

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

        $this->sseHandle = @fopen($sseUrl, 'r', false, $context);

        if ($this->sseHandle === false) {
            throw McpConnectionException::connectionFailed("Failed to connect to SSE endpoint: {$sseUrl}");
        }

        // Set stream to non-blocking for polling
        stream_set_blocking($this->sseHandle, false);

        $this->connected = true;
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

        $requestId = $request['id'] ?? null;

        // Send request via HTTP POST
        $this->postMessage($request);

        // Wait for response from SSE stream
        return $this->waitForResponse($requestId);
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
        $messageUrl = rtrim($this->baseUrl, '/').'/message';
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

        // Check HTTP status code
        if (isset($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
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
        if ($this->sseHandle === null || $this->sseHandle === false || ! is_resource($this->sseHandle)) {
            return;
        }

        // Read available data from SSE stream
        while (! feof($this->sseHandle)) {
            $chunk = fgets($this->sseHandle);

            if ($chunk === false) {
                break; // No data available
            }

            $this->sseBuffer .= $chunk;

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

        // Process the event
        if ($eventType === 'message' && ! empty($data)) {
            $jsonData = implode("\n", $data);

            try {
                $message = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($message) && isset($message['jsonrpc']) && $message['jsonrpc'] === '2.0') {
                    // Store response indexed by request ID
                    $id = $message['id'] ?? 'notification';
                    $this->responseBuffer[$id] = $message;
                }
            } catch (\JsonException $e) {
                // Invalid JSON, skip
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
