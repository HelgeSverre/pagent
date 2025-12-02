<?php

declare(strict_types=1);

namespace Pagent\Mcp\Transports;

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\Exceptions\McpTimeoutException;
use Pagent\Mcp\McpTransport;

use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_select;
use function stream_set_blocking;

/**
 * Stdio transport for MCP communication via process stdin/stdout.
 *
 * Spawns an MCP server process and communicates using JSON-RPC 2.0
 * messages over stdin/stdout.
 */
final class StdioTransport implements McpTransport
{
    /** @var resource|null */
    private $process = null;

    /** @var array<string, resource>|null */
    private ?array $pipes = null;

    private bool $connected = false;

    /**
     * Create a new stdio transport.
     *
     * @param  string  $command  Command to spawn MCP server process
     * @param  string|null  $cwd  Working directory for the process
     * @param  array<string, string>  $env  Environment variables
     * @param  int  $timeoutMs  Request timeout in milliseconds
     */
    public function __construct(
        private readonly string $command,
        private readonly ?string $cwd = null,
        private readonly array $env = [],
        private readonly int $timeoutMs = 30000,
    ) {}

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $this->cwd,
            $this->env ?: null
        );

        if ($process === false || ! is_array($pipes)) {
            throw McpConnectionException::connectionFailed('Failed to spawn MCP server process');
        }

        // Set streams to non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->process = $process;
        $this->pipes = $pipes;
        $this->connected = true;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        if ($this->pipes !== null) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = null;
        }

        if ($this->process !== null && is_resource($this->process)) {
            proc_close($this->process);
            $this->process = null;
        }

        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function sendRequest(array $request): array
    {
        if (! $this->connected || $this->pipes === null) {
            throw McpConnectionException::notConnected();
        }

        // Send request as JSON line
        $json = json_encode($request, JSON_THROW_ON_ERROR);
        $written = fwrite($this->pipes[0], $json."\n");

        if ($written === false) {
            throw McpConnectionException::connectionLost('Failed to write to stdin');
        }

        fflush($this->pipes[0]);

        // Wait for response
        return $this->readResponse();
    }

    public function sendNotification(array $notification): void
    {
        if (! $this->connected || $this->pipes === null) {
            throw McpConnectionException::notConnected();
        }

        // Send notification as JSON line
        $json = json_encode($notification, JSON_THROW_ON_ERROR);
        $written = fwrite($this->pipes[0], $json."\n");

        if ($written === false) {
            throw McpConnectionException::connectionLost('Failed to write to stdin');
        }

        fflush($this->pipes[0]);
    }

    /**
     * Read JSON-RPC response from stdout.
     *
     * @return array<string, mixed>
     *
     * @throws McpProtocolException
     * @throws McpTimeoutException
     * @throws McpConnectionException
     */
    private function readResponse(): array
    {
        if ($this->pipes === null) {
            throw McpConnectionException::notConnected();
        }

        $buffer = '';
        $startTime = microtime(true);
        $timeoutSeconds = $this->timeoutMs / 1000;

        while (true) {
            // Check timeout
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $timeoutSeconds) {
                throw McpTimeoutException::requestTimedOut($this->timeoutMs);
            }

            // Wait for data with remaining timeout
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $remainingTimeout = (int) (($timeoutSeconds - $elapsed) * 1000000);

            $ready = stream_select($read, $write, $except, 0, $remainingTimeout);

            if ($ready === false) {
                throw McpConnectionException::connectionLost('Stream select failed');
            }

            if ($ready === 0) {
                continue; // Timeout on select, will check total timeout on next iteration
            }

            // Read available data
            $chunk = fgets($this->pipes[1]);

            if ($chunk === false) {
                // Check if process is still alive
                $status = proc_get_status($this->process);
                if ($status === false || ! $status['running']) {
                    throw McpConnectionException::connectionLost('MCP server process terminated');
                }

                continue;
            }

            $buffer .= $chunk;

            // Check if we have a complete JSON line
            if (str_ends_with(trim($buffer), '}')) {
                // Use json_validate for fast early validation (PHP 8.3+)
                if (! json_validate($buffer)) {
                    // Not complete/valid JSON yet, continue reading
                    continue;
                }

                $response = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($response)) {
                    throw McpProtocolException::invalidResponse('Response is not a JSON object');
                }

                // Validate JSON-RPC 2.0 response
                if (! isset($response['jsonrpc']) || $response['jsonrpc'] !== '2.0') {
                    throw McpProtocolException::invalidResponse('Missing or invalid jsonrpc field');
                }

                return $response;
            }
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
