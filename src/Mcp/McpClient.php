<?php

declare(strict_types=1);

namespace Pagent\Mcp;

use Closure;
use Pagent\Events\Event;
use Pagent\Events\EventDispatcher;
use Pagent\Events\EventListener;
use Pagent\Events\EventManager;
use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;

/**
 * MCP (Model Context Protocol) Client.
 *
 * Connects to MCP servers and provides access to their tools and resources.
 * Implements the JSON-RPC 2.0 protocol as specified in the MCP specification.
 *
 * @see https://spec.modelcontextprotocol.io
 */
final class McpClient
{
    private bool $initialized = false;

    /** @var array<string, mixed>|null */
    private ?array $serverCapabilities = null;

    /** @var array<int, array<string, mixed>> */
    private array $availableTools = [];

    /** @var array<int, array<string, mixed>> */
    private array $availableResources = [];

    /** @var array<int, array<string, mixed>> */
    private array $availablePrompts = [];

    /** @var (callable(string|int|null, int, int|null): void)|null */
    private $progressCallback = null;

    private int $requestId = 1;

    private EventDispatcher $eventDispatcher;

    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $clientName = 'pagent',
        private readonly string $clientVersion = '0.7.0',
        ?EventDispatcher $eventDispatcher = null,
    ) {
        $this->eventDispatcher = $eventDispatcher ?? new EventDispatcher;
    }

    /**
     * Connect and initialize the MCP session.
     *
     * Performs the initialization handshake with the server.
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function connect(): void
    {
        $startTime = microtime(true);

        // Fire connection initiating event
        $this->fireEvent(new \Pagent\Events\Events\Mcp\McpConnectionInitiatingEvent(
            client: $this,
            clientName: $this->clientName,
            clientVersion: $this->clientVersion,
        ));

        try {
            // Connect transport
            $this->transport->connect();

            // Send initialize request
            // Note: capabilities must be objects (not arrays) in JSON
            $response = $this->sendRequest('initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities' => (object) [
                    'experimental' => (object) [],
                    'sampling' => (object) [],
                ],
                'clientInfo' => [
                    'name' => $this->clientName,
                    'version' => $this->clientVersion,
                ],
            ]);

            // Validate response
            if (! isset($response['result'])) {
                throw McpProtocolException::invalidResponse('Missing result in initialize response');
            }

            /** @var array<string, mixed> $result */
            $result = $response['result'];

            // Store server capabilities
            /** @var array<string, mixed> $capabilities */
            $capabilities = is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
            $this->serverCapabilities = $capabilities;

            // Mark as initialized
            $this->initialized = true;

            // Send initialized notification
            $this->sendNotification('notifications/initialized');

            $durationMs = (microtime(true) - $startTime) * 1000;

            // Fire connection established event
            /** @var array<string, mixed> $serverInfo */
            $serverInfo = is_array($result['serverInfo'] ?? null) ? $result['serverInfo'] : [];
            $this->fireEvent(new \Pagent\Events\Events\Mcp\McpConnectionEstablishedEvent(
                client: $this,
                clientName: $this->clientName,
                clientVersion: $this->clientVersion,
                serverCapabilities: $this->serverCapabilities ?? [],
                serverInfo: $serverInfo,
                durationMs: $durationMs,
            ));
        } catch (\Throwable $e) {
            // Fire connection failed event
            $this->fireEvent(new \Pagent\Events\Events\Mcp\McpConnectionFailedEvent(
                client: $this,
                clientName: $this->clientName,
                clientVersion: $this->clientVersion,
                error: $e,
            ));

            throw $e;
        }
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        // Fire disconnecting event
        $this->fireEvent(new \Pagent\Events\Events\Mcp\McpDisconnectingEvent(
            client: $this,
            clientName: $this->clientName,
            clientVersion: $this->clientVersion,
        ));

        $this->transport->disconnect();
        $this->initialized = false;
        $this->serverCapabilities = null;
        $this->availableTools = [];

        // Fire disconnected event
        $this->fireEvent(new \Pagent\Events\Events\Mcp\McpDisconnectedEvent(
            client: $this,
            clientName: $this->clientName,
            clientVersion: $this->clientVersion,
        ));
    }

    /**
     * Check if client is connected and initialized.
     */
    public function isConnected(): bool
    {
        return $this->initialized && $this->transport->isConnected();
    }

    /**
     * Discover available tools from the server.
     *
     * @return array<int, array<string, mixed>> List of tool definitions
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function discoverTools(): array
    {
        $this->ensureConnected();

        $startTime = microtime(true);

        // Fire tools discovering event
        $this->fireEvent(new \Pagent\Events\Events\Mcp\McpToolsDiscoveringEvent(
            client: $this,
        ));

        $response = $this->sendRequest('tools/list', []);

        /** @var array<string, mixed> $result */
        $result = $response['result'] ?? [];

        if (! isset($result['tools']) || ! is_array($result['tools'])) {
            throw McpProtocolException::invalidResponse('Missing or invalid tools array');
        }

        $this->availableTools = $result['tools'];

        $durationMs = (microtime(true) - $startTime) * 1000;

        // Fire tools discovered event
        $this->fireEvent(new \Pagent\Events\Events\Mcp\McpToolsDiscoveredEvent(
            client: $this,
            tools: $this->availableTools,
            toolCount: count($this->availableTools),
            durationMs: $durationMs,
        ));

        return $this->availableTools;
    }

    /**
     * Get list of available tools (cached from last discovery).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableTools(): array
    {
        return $this->availableTools;
    }

    /**
     * Call a tool on the MCP server.
     *
     * @param  string  $toolName  Name of the tool to call
     * @param  array<string, mixed>  $arguments  Tool arguments
     * @return array<string, mixed> Tool result
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $this->ensureConnected();

        $startTime = microtime(true);

        // Fire tool calling event
        $this->fireEvent(new \Pagent\Events\Events\Mcp\McpToolCallingEvent(
            client: $this,
            toolName: $toolName,
            arguments: $arguments,
        ));

        try {
            $response = $this->sendRequest('tools/call', [
                'name' => $toolName,
                'arguments' => $arguments,
            ]);

            if (! isset($response['result'])) {
                throw McpProtocolException::invalidResponse('Missing result in tools/call response');
            }

            /** @var array<string, mixed> $result */
            $result = is_array($response['result']) ? $response['result'] : [];
            $durationMs = (microtime(true) - $startTime) * 1000;

            // Fire tool called event
            $this->fireEvent(new \Pagent\Events\Events\Mcp\McpToolCalledEvent(
                client: $this,
                toolName: $toolName,
                arguments: $arguments,
                result: $result,
                durationMs: $durationMs,
            ));

            return $result;
        } catch (\Throwable $e) {
            // Fire tool error event
            $this->fireEvent(new \Pagent\Events\Events\Mcp\McpToolErrorEvent(
                client: $this,
                toolName: $toolName,
                arguments: $arguments,
                error: $e,
            ));

            throw $e;
        }
    }

    /**
     * Get server capabilities.
     *
     * @return array<string, mixed>|null
     */
    public function getServerCapabilities(): ?array
    {
        return $this->serverCapabilities;
    }

    /**
     * Discover available resources from the server.
     *
     * @param  string|null  $cursor  Pagination cursor
     * @return array{resources: array<int, array<string, mixed>>, nextCursor?: string}
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function discoverResources(?string $cursor = null): array
    {
        $this->ensureConnected();

        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $response = $this->sendRequest('resources/list', $params);

        /** @var array<string, mixed> $result */
        $result = $response['result'] ?? [];

        if (! isset($result['resources']) || ! is_array($result['resources'])) {
            throw McpProtocolException::invalidResponse('Missing or invalid resources array');
        }

        $this->availableResources = $result['resources'];

        /** @var string|null $nextCursor */
        $nextCursor = $result['nextCursor'] ?? null;

        $returnValue = ['resources' => $this->availableResources];
        if ($nextCursor !== null) {
            $returnValue['nextCursor'] = $nextCursor;
        }

        return $returnValue;
    }

    /**
     * Get list of available resources (cached from last discovery).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableResources(): array
    {
        return $this->availableResources;
    }

    /**
     * Read a resource from the MCP server.
     *
     * @param  string  $uri  Resource URI
     * @return array<string, mixed> Resource content
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function readResource(string $uri): array
    {
        $this->ensureConnected();

        $response = $this->sendRequest('resources/read', [
            'uri' => $uri,
        ]);

        if (! isset($response['result'])) {
            throw McpProtocolException::invalidResponse('Missing result in resources/read response');
        }

        /** @var array<string, mixed> $result */
        $result = is_array($response['result']) ? $response['result'] : [];

        return $result;
    }

    /**
     * Discover available prompts from the server.
     *
     * @param  string|null  $cursor  Pagination cursor
     * @return array{prompts: array<int, array<string, mixed>>, nextCursor?: string}
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function discoverPrompts(?string $cursor = null): array
    {
        $this->ensureConnected();

        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $response = $this->sendRequest('prompts/list', $params);

        /** @var array<string, mixed> $result */
        $result = $response['result'] ?? [];

        if (! isset($result['prompts']) || ! is_array($result['prompts'])) {
            throw McpProtocolException::invalidResponse('Missing or invalid prompts array');
        }

        $this->availablePrompts = $result['prompts'];

        /** @var string|null $nextCursor */
        $nextCursor = $result['nextCursor'] ?? null;

        $returnValue = ['prompts' => $this->availablePrompts];
        if ($nextCursor !== null) {
            $returnValue['nextCursor'] = $nextCursor;
        }

        return $returnValue;
    }

    /**
     * Get list of available prompts (cached from last discovery).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailablePrompts(): array
    {
        return $this->availablePrompts;
    }

    /**
     * Get a prompt with arguments.
     *
     * @param  string  $name  Prompt name
     * @param  array<string, mixed>  $arguments  Prompt arguments
     * @return array<string, mixed> Prompt result with messages
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        $this->ensureConnected();

        $response = $this->sendRequest('prompts/get', [
            'name' => $name,
            // Cast to object to ensure JSON encodes as {} not []
            'arguments' => empty($arguments) ? (object) [] : $arguments,
        ]);

        if (! isset($response['result'])) {
            throw McpProtocolException::invalidResponse('Missing result in prompts/get response');
        }

        /** @var array<string, mixed> $result */
        $result = is_array($response['result']) ? $response['result'] : [];

        return $result;
    }

    /**
     * Set a progress callback for handling progress notifications.
     *
     * @param  (callable(string|int|null, int, int|null): void)|null  $callback  Callback function(progressToken, progress, total)
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Register an event listener.
     *
     * @param  string  $event  Event name or class name
     * @param  Closure(Event): void|EventListener  $listener  Listener to attach
     * @param  int  $priority  Priority (higher = executed first)
     * @return string Listener ID for later removal
     */
    public function on(string $event, Closure|EventListener $listener, int $priority = 0): string
    {
        return $this->eventDispatcher->on($event, $listener, $priority);
    }

    /**
     * Handle incoming notification from the server.
     *
     * @param  array<string, mixed>  $notification
     */
    public function handleNotification(array $notification): void
    {
        $method = $notification['method'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($notification['params'] ?? null) ? $notification['params'] : [];

        if ($method === 'notifications/progress' && $this->progressCallback !== null) {
            /** @var string|int|null $progressToken */
            $progressToken = $params['progressToken'] ?? null;
            /** @var int $progress */
            $progress = is_int($params['progress'] ?? null) ? $params['progress'] : 0;
            /** @var int|null $total */
            $total = isset($params['total']) && is_int($params['total']) ? $params['total'] : null;

            ($this->progressCallback)($progressToken, $progress, $total);
        }
    }

    /**
     * Send a JSON-RPC 2.0 request and wait for response.
     *
     * @param  string  $method  JSON-RPC method name
     * @param  array<string, mixed>  $params  Method parameters
     * @return array<string, mixed> JSON-RPC response
     *
     * @throws McpProtocolException
     */
    private function sendRequest(string $method, array $params): array
    {
        $request = [
            'jsonrpc' => '2.0',
            'id' => $this->requestId++,
            'method' => $method,
            // Cast to object to ensure JSON encodes as {} not []
            'params' => empty($params) ? (object) [] : $params,
        ];

        $response = $this->transport->sendRequest($request);

        // Check for JSON-RPC errors
        if (isset($response['error'])) {
            /** @var array<string, mixed> $error */
            $error = is_array($response['error']) ? $response['error'] : [];
            /** @var string $message */
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
            /** @var int $code */
            $code = is_int($error['code'] ?? null) ? $error['code'] : -1;
            throw McpProtocolException::serverError($message, $code);
        }

        return $response;
    }

    /**
     * Send a JSON-RPC 2.0 notification (no response expected).
     *
     * @param  string  $method  JSON-RPC method name
     * @param  array<string, mixed>  $params  Method parameters
     */
    private function sendNotification(string $method, array $params = []): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => $method,
            // Cast to object to ensure JSON encodes as {} not []
            'params' => empty($params) ? (object) [] : $params,
        ];

        $this->transport->sendNotification($notification);
    }

    /**
     * Ensure client is connected and initialized.
     *
     * @throws McpConnectionException
     */
    private function ensureConnected(): void
    {
        if (! $this->isConnected()) {
            throw McpConnectionException::notConnected();
        }
    }

    /**
     * Fire an event to both instance and global listeners.
     */
    private function fireEvent(Event $event): void
    {
        $this->eventDispatcher->dispatch($event);
        EventManager::instance()->dispatch($event);
    }
}
