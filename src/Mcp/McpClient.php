<?php

declare(strict_types=1);

namespace Pagent\Mcp;

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

    /** @var callable|null */
    private $progressCallback = null;

    private int $requestId = 1;

    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $clientName = 'pagent',
        private readonly string $clientVersion = '0.7.0',
    ) {}

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
        // Connect transport
        $this->transport->connect();

        // Send initialize request
        $response = $this->sendRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'experimental' => [],
                'sampling' => [],
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

        $result = $response['result'];

        // Store server capabilities
        $this->serverCapabilities = $result['capabilities'] ?? [];

        // Mark as initialized
        $this->initialized = true;

        // Send initialized notification
        $this->sendNotification('notifications/initialized');
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        $this->transport->disconnect();
        $this->initialized = false;
        $this->serverCapabilities = null;
        $this->availableTools = [];
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

        $response = $this->sendRequest('tools/list', []);

        if (! isset($response['result']['tools']) || ! is_array($response['result']['tools'])) {
            throw McpProtocolException::invalidResponse('Missing or invalid tools array');
        }

        $this->availableTools = $response['result']['tools'];

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

        $response = $this->sendRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);

        if (! isset($response['result'])) {
            throw McpProtocolException::invalidResponse('Missing result in tools/call response');
        }

        return $response['result'];
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

        if (! isset($response['result']['resources']) || ! is_array($response['result']['resources'])) {
            throw McpProtocolException::invalidResponse('Missing or invalid resources array');
        }

        $this->availableResources = $response['result']['resources'];

        return [
            'resources' => $this->availableResources,
            'nextCursor' => $response['result']['nextCursor'] ?? null,
        ];
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

        return $response['result'];
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

        if (! isset($response['result']['prompts']) || ! is_array($response['result']['prompts'])) {
            throw McpProtocolException::invalidResponse('Missing or invalid prompts array');
        }

        $this->availablePrompts = $response['result']['prompts'];

        return [
            'prompts' => $this->availablePrompts,
            'nextCursor' => $response['result']['nextCursor'] ?? null,
        ];
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
            'arguments' => $arguments,
        ]);

        if (! isset($response['result'])) {
            throw McpProtocolException::invalidResponse('Missing result in prompts/get response');
        }

        return $response['result'];
    }

    /**
     * Set a progress callback for handling progress notifications.
     *
     * @param  callable|null  $callback  Callback function(progressToken, progress, total)
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Handle incoming notification from the server.
     *
     * @param  array<string, mixed>  $notification
     */
    public function handleNotification(array $notification): void
    {
        $method = $notification['method'] ?? null;
        $params = $notification['params'] ?? [];

        if ($method === 'notifications/progress' && $this->progressCallback !== null) {
            ($this->progressCallback)(
                $params['progressToken'] ?? null,
                $params['progress'] ?? 0,
                $params['total'] ?? null
            );
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
            'params' => $params,
        ];

        $response = $this->transport->sendRequest($request);

        // Check for JSON-RPC errors
        if (isset($response['error'])) {
            $error = $response['error'];
            throw McpProtocolException::serverError(
                $error['message'] ?? 'Unknown error',
                $error['code'] ?? -1
            );
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
            'params' => $params,
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
}
