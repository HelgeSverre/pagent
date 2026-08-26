<?php

declare(strict_types=1);

namespace Pagent\Mcp\Transports;

use Pagent\Exceptions\InvalidArgumentException;
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
    /** @var non-empty-list<string> */
    private readonly array $command;

    private readonly ?string $cwd;

    /** @var array<string, string> */
    private readonly array $env;

    private readonly int $timeoutMs;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    private bool $connected = false;

    /** @var (callable(array<string, mixed>): void)|null */
    private $notificationHandler = null;

    /** @var array<int|string, array<string, mixed>> Responses received for other request ids */
    private array $responseBuffer = [];

    /** Bounded stderr capture for error reporting. */
    private string $stderrBuffer = '';

    private const STDERR_BUFFER_LIMIT = 8192;

    /**
     * Create a new stdio transport.
     *
     * String commands are tokenized without invoking a shell. Prefer an argv
     * list whenever arguments are not static literals.
     *
     * @param  string|non-empty-list<string>  $command  Executable and arguments
     * @param  string|null  $cwd  Working directory for the process
     * @param  array<string, string>  $env  Environment variables
     * @param  int  $timeoutMs  Request timeout in milliseconds
     */
    public function __construct(
        string|array $command,
        ?string $cwd = null,
        array $env = [],
        int $timeoutMs = 30000,
    ) {
        $this->command = is_string($command)
            ? self::tokenizeCommand($command)
            : self::validateArgv($command);
        $this->cwd = $cwd;
        $this->env = self::validateEnvironment($env);
        $this->timeoutMs = $timeoutMs;

        if ($timeoutMs < 1) {
            throw new InvalidArgumentException('MCP stdio timeout must be at least one millisecond');
        }
    }

    /**
     * Explicitly opt into shell parsing for commands that require pipes,
     * redirection, expansion, or other shell syntax.
     *
     * Never pass untrusted input to this method.
     *
     * @param  array<string, string>  $env
     */
    public static function fromShellCommand(
        string $command,
        ?string $cwd = null,
        array $env = [],
        int $timeoutMs = 30000,
    ): self {
        if (trim($command) === '' || str_contains($command, "\0")) {
            throw new InvalidArgumentException('MCP shell command must be a non-empty string without null bytes');
        }

        $argv = PHP_OS_FAMILY === 'Windows'
            ? ['cmd.exe', '/D', '/S', '/C', $command]
            : ['/bin/sh', '-c', $command];

        return new self($argv, $cwd, $env, $timeoutMs);
    }

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

        $errorMessage = null;
        set_error_handler(static function (int $severity, string $message) use (&$errorMessage): bool {
            $errorMessage = $message;

            return true;
        });

        try {
            $environment = $this->env === []
                ? null
                : array_replace(self::currentEnvironment(), $this->env);
            $process = proc_open(
                $this->command,
                $descriptors,
                $pipes,
                $this->cwd,
                $environment,
                ['bypass_shell' => true],
            );
        } finally {
            restore_error_handler();
        }

        if ($process === false || ! is_array($pipes)) {
            throw McpConnectionException::connectionFailed(
                $errorMessage ?? 'Failed to spawn MCP server process',
            );
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
        $this->responseBuffer = [];
        $this->stderrBuffer = '';
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

        // Wait for the response matching this request's id
        /** @var int|string|null $requestId */
        $requestId = $request['id'] ?? null;

        return $this->readResponse($requestId);
    }

    /** @param (callable(array<string, mixed>): void)|null $handler */
    public function setNotificationHandler(?callable $handler): void
    {
        $this->notificationHandler = $handler;
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
     * Read the JSON-RPC response matching the given request id from stdout.
     *
     * Server notifications (messages without an id) are dispatched to the
     * notification handler; responses for other ids are buffered.
     *
     * @return array<string, mixed>
     *
     * @throws McpProtocolException
     * @throws McpTimeoutException
     * @throws McpConnectionException
     */
    private function readResponse(int|string|null $requestId): array
    {
        if ($this->pipes === null) {
            throw McpConnectionException::notConnected();
        }

        $stdout = $this->pipes[1];
        $stderr = $this->pipes[2];

        // Response may already have arrived while waiting for another request
        if ($requestId !== null && isset($this->responseBuffer[$requestId])) {
            $response = $this->responseBuffer[$requestId];
            unset($this->responseBuffer[$requestId]);

            return $response;
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

            // Wait for data with remaining timeout; also drain stderr so a
            // chatty server cannot fill the pipe and deadlock.
            $read = [$stdout, $stderr];
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

            $this->drainStderr();

            // Read available data
            $chunk = fgets($stdout);

            if ($chunk === false) {
                // Check if process is still alive
                if ($this->process === null) {
                    throw McpConnectionException::connectionLost($this->terminatedMessage());
                }

                $status = proc_get_status($this->process);
                if (! $status['running']) {
                    throw McpConnectionException::connectionLost($this->terminatedMessage());
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

                $message = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
                $buffer = '';

                if (! is_array($message)) {
                    throw McpProtocolException::invalidResponse('Response is not a JSON object');
                }

                // Validate JSON-RPC 2.0 message
                if (! isset($message['jsonrpc']) || $message['jsonrpc'] !== '2.0') {
                    throw McpProtocolException::invalidResponse('Missing or invalid jsonrpc field');
                }

                // Messages without an id are server notifications, not the response
                if (! array_key_exists('id', $message) || $message['id'] === null) {
                    if ($this->notificationHandler !== null) {
                        ($this->notificationHandler)($message);
                    }

                    continue;
                }

                /** @var int|string $messageId */
                $messageId = $message['id'];

                if ($requestId === null || $messageId === $requestId) {
                    return $message;
                }

                // Response for a different request: buffer it
                $this->responseBuffer[$messageId] = $message;
            }
        }
    }

    /**
     * Drain any pending stderr output into a bounded buffer.
     */
    private function drainStderr(): void
    {
        if ($this->pipes === null || ! is_resource($this->pipes[2])) {
            return;
        }

        while (($chunk = fread($this->pipes[2], 8192)) !== false && $chunk !== '') {
            $this->stderrBuffer .= $chunk;

            // Keep only the tail; enough for error reporting.
            if (strlen($this->stderrBuffer) > self::STDERR_BUFFER_LIMIT) {
                $this->stderrBuffer = substr($this->stderrBuffer, -self::STDERR_BUFFER_LIMIT);
            }
        }
    }

    /**
     * Build a termination error message including captured stderr, if any.
     */
    private function terminatedMessage(): string
    {
        $this->drainStderr();
        $message = 'MCP server process terminated';

        if ($this->stderrBuffer !== '') {
            $message .= '. Stderr: '.trim($this->stderrBuffer);
        }

        return $message;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Parse a legacy command string into argv without invoking a shell.
     *
     * Supports whitespace separation, single/double quotes, and backslash
     * escaping. Unquoted shell control operators are rejected so a command
     * that previously depended on shell behavior fails closed.
     *
     * @return non-empty-list<string>
     */
    private static function tokenizeCommand(string $command): array
    {
        if (trim($command) === '' || str_contains($command, "\0")) {
            throw new InvalidArgumentException('MCP command must be a non-empty string without null bytes');
        }

        $tokens = [];
        $token = '';
        $quote = null;
        $inToken = false;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $character = $command[$index];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                    $inToken = true;

                    continue;
                }

                if ($quote === '"' && $character === '\\') {
                    if (++$index >= $length) {
                        throw new InvalidArgumentException('MCP command ends with an incomplete escape sequence');
                    }
                    $token .= $command[$index];
                    $inToken = true;

                    continue;
                }

                $token .= $character;
                $inToken = true;

                continue;
            }

            if (ctype_space($character)) {
                if ($inToken) {
                    $tokens[] = $token;
                    $token = '';
                    $inToken = false;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $inToken = true;

                continue;
            }

            if ($character === '\\') {
                if (++$index >= $length) {
                    throw new InvalidArgumentException('MCP command ends with an incomplete escape sequence');
                }
                $token .= $command[$index];
                $inToken = true;

                continue;
            }

            if (str_contains('|&;<>()`', $character)) {
                throw new InvalidArgumentException(
                    'Shell operators are not allowed in MCP commands; use an argv array or fromShellCommand()',
                );
            }

            $token .= $character;
            $inToken = true;
        }

        if ($quote !== null) {
            throw new InvalidArgumentException('MCP command contains an unterminated quote');
        }

        if ($inToken) {
            $tokens[] = $token;
        }

        return self::validateArgv($tokens);
    }

    /**
     * @param  array<mixed>  $command
     * @return non-empty-list<string>
     */
    private static function validateArgv(array $command): array
    {
        if ($command === [] || ! array_is_list($command)) {
            throw new InvalidArgumentException('MCP command argv must be a non-empty list');
        }

        foreach ($command as $index => $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('MCP command arguments must be strings without null bytes');
            }
            if ($index === 0 && $argument === '') {
                throw new InvalidArgumentException('MCP command executable must not be empty');
            }
        }

        /** @var non-empty-list<string> $command */
        return $command;
    }

    /**
     * @param  array<mixed>  $env
     * @return array<string, string>
     */
    private static function validateEnvironment(array $env): array
    {
        foreach ($env as $name => $value) {
            if (! is_string($name) || $name === '' || str_contains($name, "\0") || str_contains($name, '=')) {
                throw new InvalidArgumentException('MCP environment variable names must be non-empty strings without null bytes or equals signs');
            }
            if (! is_string($value) || str_contains($value, "\0")) {
                throw new InvalidArgumentException('MCP environment variable values must be strings without null bytes');
            }
        }

        /** @var array<string, string> $env */
        return $env;
    }

    /** @return array<string, string> */
    private static function currentEnvironment(): array
    {
        return getenv();
    }
}
