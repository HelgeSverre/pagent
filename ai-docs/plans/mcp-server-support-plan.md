# MCP Server Support Implementation Plan

**Created:** 2025-10-29
**Target Version:** v0.7.0
**Estimated Effort:** 6-8 hours
**Priority:** High
**Status:** Planned

---

## Goal

Enable Pagent agents to connect to Model Context Protocol (MCP) servers as consumers/clients, automatically discovering and using MCP-provided tools. This integration allows Pagent to leverage the growing ecosystem of MCP servers (filesystem, database, API, etc.) without implementing each capability directly.

---

## Background

### What is MCP?

The Model Context Protocol (MCP) is an open standard introduced by Anthropic in November 2024 that standardizes how AI applications connect to data sources and tools. It uses JSON-RPC 2.0 over various transports (stdio, HTTP/SSE) and defines three core primitives:

- **Tools**: Executable functions that LLMs can call to retrieve information or perform actions
- **Resources**: Structured data that can be included in LLM prompt context
- **Prompts**: Instructions or templates for instructions

MCP deliberately reuses message-flow ideas from the Language Server Protocol (LSP), making it familiar to developers who have worked with VS Code extensions or similar tools.

### Why MCP for Pagent?

1. **Ecosystem Access**: Leverage pre-built MCP servers (Google Drive, Slack, GitHub, Postgres, Puppeteer, etc.)
2. **Standardization**: Use industry-standard protocol instead of custom integrations
3. **Community**: Tap into growing MCP server ecosystem (Python, TypeScript, now PHP)
4. **Future-Proof**: OpenAI, Google DeepMind have committed to MCP support
5. **Zero Maintenance**: Tools maintained by MCP server authors, not Pagent

### Current State

- Pagent has robust tool system (src/Contracts/ToolInterface.php, src/Tool/Tool.php)
- Tools support both class-based (src/Tools/\*.php) and closure-based definitions
- Automatic schema generation for Anthropic and OpenAI formats
- Automatic tool execution with recursive calling
- Tool validation with JSON schema

### Integration Strategy

Create an MCP client that:

1. Connects to MCP servers via stdio or HTTP transports
2. Performs capability negotiation and tool discovery
3. Maps MCP tool schemas to Pagent's ToolInterface
4. Executes MCP tools by proxying calls to the MCP server
5. Integrates seamlessly with existing tool system

---

## Scope

### In Scope

- **MCP Client Implementation**: Connect to MCP servers as a client/consumer
- **Transport Layers**: Support stdio (process-based) and HTTP/SSE transports
- **Tool Discovery**: Automatic discovery of tools from MCP servers
- **Tool Mapping**: Convert MCP tool schemas to Pagent ToolInterface
- **Tool Execution**: Proxy tool calls to MCP server and return results
- **Server Lifecycle**: Start, stop, restart MCP server processes
- **Error Handling**: Graceful handling of connection failures, timeouts, invalid responses
- **Fluent API**: `Agent::mcpServer()` method for easy configuration
- **Multiple Servers**: Support connecting to multiple MCP servers per agent
- **Configuration**: Support for stdio command/args and HTTP URL configurations

### Out of Scope (v0.7.0)

- **MCP Server Implementation**: Exposing Pagent as an MCP server (deferred to v1.0+)
- **Resources Primitive**: MCP resources support (focus on tools first)
- **Prompts Primitive**: MCP prompts support (focus on tools first)
- **Sampling**: MCP sampling/LLM access from server (not needed for consumer)
- **Advanced Transports**: WebSocket, custom transports (HTTP/SSE sufficient)
- **Server Registry**: Global MCP server registry (simple per-agent config)
- **Auto-Restart**: Automatic server restart on crash (manual restart only)
- **Authentication**: MCP server authentication (assume localhost/trusted servers)

---

## Implementation Phases

### Phase 1: Core MCP Client (Estimated: 2-3 hours)

- [ ] Create `src/Mcp/McpClient.php` interface
- [ ] Create `src/Mcp/McpServer.php` value object (server configuration)
- [ ] Implement JSON-RPC 2.0 message formatting
- [ ] Implement capability negotiation (initialize, initialized)
- [ ] Implement tool discovery (tools/list)
- [ ] Create `src/Mcp/McpTransport.php` interface
- [ ] Add error handling and timeout configuration

**Deliverables:**

- `src/Mcp/McpClient.php` - Core client logic
- `src/Mcp/McpServer.php` - Server configuration value object
- `src/Mcp/McpTransport.php` - Transport interface
- `src/Mcp/Exceptions/McpException.php` - Base exception
- `src/Mcp/Exceptions/McpConnectionException.php` - Connection errors
- `src/Mcp/Exceptions/McpTimeoutException.php` - Timeout errors

### Phase 2: Transport Implementations (Estimated: 2-3 hours)

- [ ] Implement `src/Mcp/Transports/StdioTransport.php` (subprocess via Symfony Process)
- [ ] Implement `src/Mcp/Transports/HttpTransport.php` (HTTP with SSE support)
- [ ] Handle process lifecycle (start, stop, restart)
- [ ] Implement request/response correlation (JSON-RPC id field)
- [ ] Add streaming response handling (SSE)
- [ ] Implement proper cleanup (kill processes on shutdown)

**Deliverables:**

- `src/Mcp/Transports/StdioTransport.php` - Process-based transport
- `src/Mcp/Transports/HttpTransport.php` - HTTP/SSE transport
- Process management with proper cleanup
- Request/response correlation logic
- SSE parsing for HTTP transport

### Phase 3: Tool Integration (Estimated: 1-2 hours)

- [ ] Create `src/Mcp/McpTool.php` implementing ToolInterface
- [ ] Map MCP tool schema to Anthropic/OpenAI formats
- [ ] Implement `execute()` to call tools/call on MCP server
- [ ] Handle tool parameters and result formatting
- [ ] Add to `Agent::mcpServer()` fluent method
- [ ] Support multiple MCP servers per agent

**Deliverables:**

- `src/Mcp/McpTool.php` - ToolInterface wrapper for MCP tools
- Schema mapping from MCP to Anthropic/OpenAI formats
- `Agent::mcpServer()` method implementation
- Automatic tool registration after server connection

### Phase 4: Testing & Documentation (Estimated: 1 hour)

- [ ] Unit tests for McpClient
- [ ] Unit tests for transports (with mock servers)
- [ ] Integration tests with real MCP servers (if available)
- [ ] Example: Connect to filesystem MCP server
- [ ] Example: Connect to multiple MCP servers
- [ ] Documentation in `docs/mcp-integration.md`
- [ ] Update README.md with MCP feature
- [ ] Update ROADMAP.md with completion status

**Deliverables:**

- `tests/Unit/Mcp/McpClientTest.php`
- `tests/Unit/Mcp/Transports/StdioTransportTest.php`
- `tests/Unit/Mcp/Transports/HttpTransportTest.php`
- `tests/Integration/McpServerTest.php` (marked as @group api)
- `examples/12-mcp-filesystem.php`
- `examples/13-mcp-multi-server.php`
- `docs/mcp-integration.md` (~400-600 lines)

---

## Technical Approach

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                         Pagent Agent                         │
│  ┌──────────────────────────────────────────────────────┐   │
│  │              Agent::mcpServer()                       │   │
│  └────────────────┬─────────────────────────────────────┘   │
│                   │                                          │
│  ┌────────────────▼─────────────────────────────────────┐   │
│  │              McpClient                                │   │
│  │  - initialize()                                       │   │
│  │  - discoverTools()                                    │   │
│  │  - callTool(name, params)                             │   │
│  └────────────────┬─────────────────────────────────────┘   │
│                   │                                          │
│  ┌────────────────▼─────────────────────────────────────┐   │
│  │         McpTransport Interface                        │   │
│  └────┬────────────────────────────────────────┬────────┘   │
│       │                                        │             │
│  ┌────▼──────────────────┐     ┌───────────────▼────────┐   │
│  │  StdioTransport       │     │  HttpTransport         │   │
│  │  (Symfony Process)    │     │  (PSR-7/PSR-18 + SSE) │   │
│  └───────────────────────┘     └────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                   │                           │
                   │                           │
┌──────────────────▼──────┐    ┌───────────────▼─────────────┐
│   MCP Server (stdio)    │    │   MCP Server (HTTP)         │
│   @mcp/server-filesystem│    │   Custom HTTP MCP server    │
│   (Node.js subprocess)  │    │   http://localhost:3000/mcp │
└─────────────────────────┘    └─────────────────────────────┘
```

### Key Components

#### 1. McpServer (Value Object)

Configuration for an MCP server connection.

```php
namespace Pagent\Mcp;

final readonly class McpServer
{
    public function __construct(
        public string $name,              // Unique identifier
        public string $transport,         // 'stdio' or 'http'
        public array $config,             // Transport-specific config
        public int $timeout = 30,         // Connection timeout (seconds)
        public bool $autoStart = true,    // Auto-start on first use
    ) {}
}
```

#### 2. McpClient (Core Client)

Manages connection to an MCP server and tool discovery.

```php
namespace Pagent\Mcp;

use Pagent\Mcp\Transports\McpTransport;

final class McpClient
{
    private McpTransport $transport;
    private array $capabilities = [];
    private array $tools = [];
    private bool $initialized = false;

    public function __construct(
        private readonly McpServer $server,
    ) {
        $this->transport = $this->createTransport();
    }

    /**
     * Initialize connection and negotiate capabilities
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Send initialize request
        $response = $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => $this->generateId(),
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => true,
                ],
                'clientInfo' => [
                    'name' => 'Pagent',
                    'version' => '0.7.0',
                ],
            ],
        ]);

        $this->capabilities = $response['result']['capabilities'] ?? [];

        // Send initialized notification
        $this->transport->send([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $this->initialized = true;
    }

    /**
     * Discover available tools from server
     *
     * @return array Array of tool schemas
     */
    public function discoverTools(): array
    {
        $this->ensureInitialized();

        $response = $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => $this->generateId(),
            'method' => 'tools/list',
        ]);

        $this->tools = $response['result']['tools'] ?? [];

        return $this->tools;
    }

    /**
     * Call a tool on the MCP server
     *
     * @param string $name Tool name
     * @param array $arguments Tool arguments
     * @return mixed Tool result
     */
    public function callTool(string $name, array $arguments): mixed
    {
        $this->ensureInitialized();

        $response = $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => $this->generateId(),
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);

        if (isset($response['error'])) {
            throw new McpException(
                "MCP tool call failed: {$response['error']['message']}",
                $response['error']['code']
            );
        }

        return $response['result']['content'] ?? null;
    }

    public function shutdown(): void
    {
        $this->transport->close();
        $this->initialized = false;
    }

    private function createTransport(): McpTransport
    {
        return match ($this->server->transport) {
            'stdio' => new Transports\StdioTransport($this->server),
            'http' => new Transports\HttpTransport($this->server),
            default => throw new \InvalidArgumentException(
                "Unsupported transport: {$this->server->transport}"
            ),
        };
    }

    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }
    }

    private function generateId(): string
    {
        return uniqid('mcp_', true);
    }
}
```

#### 3. McpTransport Interface

Abstraction for different transport mechanisms.

```php
namespace Pagent\Mcp\Transports;

interface McpTransport
{
    /**
     * Send JSON-RPC request and receive response
     *
     * @param array $message JSON-RPC message
     * @return array JSON-RPC response
     * @throws McpConnectionException
     * @throws McpTimeoutException
     */
    public function send(array $message): array;

    /**
     * Close the transport connection
     */
    public function close(): void;
}
```

#### 4. StdioTransport

Process-based transport using Symfony Process component.

```php
namespace Pagent\Mcp\Transports;

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpTimeoutException;
use Pagent\Mcp\McpServer;
use Symfony\Component\Process\Process;

final class StdioTransport implements McpTransport
{
    private ?Process $process = null;
    private array $pendingResponses = [];

    public function __construct(
        private readonly McpServer $server,
    ) {}

    public function send(array $message): array
    {
        $this->ensureProcessRunning();

        $messageJson = json_encode($message) . "\n";

        // Write to process stdin
        $this->process->getInput()->write($messageJson);

        // For requests (has 'id'), wait for response
        if (isset($message['id'])) {
            return $this->waitForResponse($message['id']);
        }

        // For notifications (no 'id'), return immediately
        return [];
    }

    public function close(): void
    {
        if ($this->process && $this->process->isRunning()) {
            $this->process->stop(3, SIGTERM);
        }

        $this->process = null;
    }

    private function ensureProcessRunning(): void
    {
        if ($this->process && $this->process->isRunning()) {
            return;
        }

        $command = $this->server->config['command'] ??
            throw new McpConnectionException('stdio transport requires "command" in config');

        $args = $this->server->config['args'] ?? [];

        $this->process = new Process(
            array_merge([$command], $args),
            cwd: $this->server->config['cwd'] ?? null,
            env: $this->server->config['env'] ?? null,
            timeout: $this->server->timeout,
        );

        $this->process->start();

        // Wait for process to be ready
        usleep(100000); // 100ms

        if (!$this->process->isRunning()) {
            throw new McpConnectionException(
                "Failed to start MCP server process: " . $this->process->getErrorOutput()
            );
        }
    }

    private function waitForResponse(string $id): array
    {
        $timeout = $this->server->timeout;
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeout) {
            // Read from process stdout
            $output = $this->process->getIncrementalOutput();

            if (empty($output)) {
                usleep(10000); // 10ms
                continue;
            }

            // Parse JSON-RPC response(s)
            $lines = explode("\n", trim($output));

            foreach ($lines as $line) {
                if (empty($line)) {
                    continue;
                }

                $response = json_decode($line, true);

                if (!isset($response['id'])) {
                    // Notification or error, skip
                    continue;
                }

                if ($response['id'] === $id) {
                    return $response;
                }

                // Store for different request
                $this->pendingResponses[$response['id']] = $response;
            }

            // Check if we have pending response
            if (isset($this->pendingResponses[$id])) {
                $response = $this->pendingResponses[$id];
                unset($this->pendingResponses[$id]);
                return $response;
            }
        }

        throw new McpTimeoutException(
            "Timeout waiting for MCP response (id: {$id})"
        );
    }
}
```

#### 5. HttpTransport

HTTP/SSE-based transport using PSR-7/PSR-18.

```php
namespace Pagent\Mcp\Transports;

use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpTimeoutException;
use Pagent\Mcp\McpServer;

final class HttpTransport implements McpTransport
{
    private array $pendingResponses = [];

    public function __construct(
        private readonly McpServer $server,
    ) {}

    public function send(array $message): array
    {
        $url = $this->server->config['url'] ??
            throw new McpConnectionException('http transport requires "url" in config');

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Add custom headers if provided
        if (isset($this->server->config['headers'])) {
            $headers = array_merge($headers, $this->server->config['headers']);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($message),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_TIMEOUT => $this->server->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new McpConnectionException(
                "HTTP request failed: {$error}"
            );
        }

        if ($httpCode !== 200) {
            throw new McpConnectionException(
                "HTTP request failed with status {$httpCode}"
            );
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new McpConnectionException(
                "Invalid JSON response: " . json_last_error_msg()
            );
        }

        return $decoded;
    }

    public function close(): void
    {
        // HTTP is stateless, nothing to close
    }

    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }

        return $formatted;
    }
}
```

#### 6. McpTool

Adapter that wraps MCP tools as Pagent ToolInterface.

```php
namespace Pagent\Mcp;

use Pagent\Contracts\ToolInterface;

final readonly class McpTool implements ToolInterface
{
    public function __construct(
        private McpClient $client,
        private array $schema,
    ) {}

    public function name(): string
    {
        return $this->schema['name'];
    }

    public function description(): string
    {
        return $this->schema['description'] ?? 'MCP tool';
    }

    public function execute(array $params): mixed
    {
        return $this->client->callTool($this->name(), $params);
    }

    public function toAnthropicSchema(): array
    {
        // MCP schema is already similar to Anthropic format
        return [
            'name' => $this->schema['name'],
            'description' => $this->schema['description'] ?? '',
            'input_schema' => $this->schema['inputSchema'] ?? [
                'type' => 'object',
                'properties' => [],
            ],
        ];
    }

    public function toOpenAISchema(): array
    {
        // Convert MCP schema to OpenAI format
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->schema['name'],
                'description' => $this->schema['description'] ?? '',
                'parameters' => $this->schema['inputSchema'] ?? [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
        ];
    }
}
```

#### 7. Agent Integration

Add MCP server support to Agent class.

```php
// In src/Agent.php

private array $mcpClients = [];

/**
 * Connect to an MCP server and import its tools
 *
 * @param string $name Server identifier
 * @param array $config Server configuration
 * @return self
 */
public function mcpServer(string $name, array $config): self
{
    // Create MCP server config
    $server = new Mcp\McpServer(
        name: $name,
        transport: $config['transport'],
        config: $config,
        timeout: $config['timeout'] ?? 30,
        autoStart: $config['auto_start'] ?? true,
    );

    // Create MCP client
    $client = new Mcp\McpClient($server);

    // Initialize and discover tools
    if ($server->autoStart) {
        $client->initialize();
        $tools = $client->discoverTools();

        // Add MCP tools to agent
        foreach ($tools as $toolSchema) {
            $this->tools[] = new Mcp\McpTool($client, $toolSchema);
        }
    }

    // Store client for later use
    $this->mcpClients[$name] = $client;

    return $this;
}

/**
 * Get MCP client by name
 */
public function getMcpClient(string $name): ?Mcp\McpClient
{
    return $this->mcpClients[$name] ?? null;
}

/**
 * Disconnect from all MCP servers (called in destructor or reset)
 */
public function disconnectMcpServers(): void
{
    foreach ($this->mcpClients as $client) {
        $client->shutdown();
    }

    $this->mcpClients = [];
}

// Update reset() method to disconnect MCP servers
public function reset(): self
{
    $this->messages = [];
    $this->tools = [];
    $this->guards = [];
    $this->middleware = [];
    $this->fallback = null;
    $this->memory = null;
    $this->sessionId = null;
    $this->contextManager = null;
    $this->disconnectMcpServers(); // Add this

    return $this;
}
```

---

## Testing Strategy

### Unit Tests

**McpClient Tests** (`tests/Unit/Mcp/McpClientTest.php`):

```php
it('initializes connection with MCP server', function () {
    $mockTransport = Mockery::mock(McpTransport::class);
    $mockTransport->shouldReceive('send')
        ->once()
        ->with(Mockery::on(fn($msg) => $msg['method'] === 'initialize'))
        ->andReturn([
            'jsonrpc' => '2.0',
            'id' => 'test-1',
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => true],
            ],
        ]);

    $mockTransport->shouldReceive('send')
        ->once()
        ->with(Mockery::on(fn($msg) => $msg['method'] === 'notifications/initialized'));

    $client = new McpClient(/* inject mock transport */);
    $client->initialize();

    expect($client->isInitialized())->toBeTrue();
});

it('discovers tools from MCP server', function () {
    // Similar mock setup
    $client = new McpClient(/* ... */);
    $client->initialize();

    $tools = $client->discoverTools();

    expect($tools)->toHaveCount(2);
    expect($tools[0]['name'])->toBe('read_file');
});

it('calls tool on MCP server', function () {
    $client = new McpClient(/* ... */);
    $client->initialize();

    $result = $client->callTool('read_file', ['path' => '/tmp/test.txt']);

    expect($result)->toBeString();
});
```

**Transport Tests** (`tests/Unit/Mcp/Transports/StdioTransportTest.php`):

```php
it('starts process for stdio transport', function () {
    $server = new McpServer(
        name: 'test',
        transport: 'stdio',
        config: [
            'command' => 'node',
            'args' => ['test-server.js'],
        ],
    );

    $transport = new StdioTransport($server);

    // Test that process starts
    expect($transport->isRunning())->toBeTrue();

    $transport->close();
});

it('sends and receives JSON-RPC messages', function () {
    // Mock or integration test with real process
});
```

### Integration Tests

**Real MCP Server** (`tests/Integration/McpServerTest.php`):

```php
/**
 * @group api
 */
it('connects to real filesystem MCP server', function () {
    $agent = agent('file-agent')
        ->provider('mock')
        ->mcpServer('filesystem', [
            'transport' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/tmp'],
        ]);

    $tools = $agent->getTools();

    expect($tools)->not->toBeEmpty();
    expect($tools[0]->name())->toContain('read');
})->skip(!function_exists('proc_open'), 'Requires proc_open for stdio');

it('uses MCP tools in agent conversation', function () {
    // Full integration test with real MCP server
    $response = agent('file-agent')
        ->mcpServer('filesystem', [/* ... */])
        ->prompt('Read the file /tmp/test.txt');

    expect($response->content)->toContain('file contents');
});
```

### Manual Testing

Create test scripts for:

1. **Filesystem MCP Server**: Read/write files via MCP
2. **Multiple Servers**: Connect to 2+ MCP servers simultaneously
3. **Error Handling**: Test timeout, connection failure, invalid tool calls
4. **Process Cleanup**: Ensure processes are killed on shutdown

---

## Risks & Mitigation

| Risk                             | Impact | Mitigation                                                   |
| -------------------------------- | ------ | ------------------------------------------------------------ |
| Process management complexity    | High   | Use battle-tested Symfony Process component                  |
| JSON-RPC implementation bugs     | Medium | Follow MCP spec strictly, add comprehensive tests            |
| Zombie processes on error        | High   | Register shutdown handler, proper cleanup in destructors     |
| Schema mapping incompatibilities | Medium | MCP schema is similar to Anthropic, minimal transformation   |
| Timeout/hanging on stdio         | Medium | Implement strict timeouts, non-blocking reads where possible |
| MCP spec changes                 | Low    | Pin to specific protocol version (2024-11-05), version later |
| PHP platform limitations         | Medium | stdio requires proc_open, HTTP more portable                 |
| Dependency on external processes | Medium | Graceful degradation, clear error messages                   |
| Multiple concurrent requests     | Low    | Use request/response ID correlation, queue if needed         |
| Large tool output (memory)       | Low    | Streaming not required for v0.7.0, can add later             |

---

## Dependencies

### Required

- **symfony/process** (^7.3) - Already in composer.json for Bash tool
- **ext-json** - Already required
- **ext-curl** - Already in composer.json

### Optional

- **psr/http-client** (^1.0) - Already in composer.json (for future PSR-18 refactor)
- **psr/http-factory** (^1.0) - Already in composer.json

### MCP Ecosystem

**Pre-built MCP Servers** (Node.js, installed via npm/npx):

- `@modelcontextprotocol/server-filesystem` - File operations
- `@modelcontextprotocol/server-github` - GitHub API
- `@modelcontextprotocol/server-postgres` - PostgreSQL queries
- `@modelcontextprotocol/server-puppeteer` - Web automation
- `@modelcontextprotocol/server-slack` - Slack integration
- `@modelcontextprotocol/server-google-drive` - Google Drive

**PHP MCP Servers** (future):

- Official PHP SDK: https://github.com/modelcontextprotocol/php-sdk
- Alternative: https://github.com/php-mcp/server

---

## Success Criteria

- [ ] Can connect to MCP server via stdio transport
- [ ] Can connect to MCP server via HTTP transport
- [ ] Can discover tools from MCP server
- [ ] Can execute MCP tools through Pagent agent
- [ ] MCP tools work seamlessly with existing tool calling
- [ ] Multiple MCP servers can be connected per agent
- [ ] Processes are properly cleaned up on shutdown
- [ ] Error handling is robust (timeout, connection failure, invalid responses)
- [ ] 20+ tests passing (unit + integration)
- [ ] Documentation is comprehensive with examples
- [ ] Examples demonstrate real MCP server usage
- [ ] Zero new PHPStan errors
- [ ] Feature flag or config to disable MCP if proc_open unavailable

---

## Timeline

| Phase                     | Duration  | Dependencies              |
| ------------------------- | --------- | ------------------------- |
| Phase 1: Core MCP Client  | 2-3 hours | None                      |
| Phase 2: Transports       | 2-3 hours | Phase 1                   |
| Phase 3: Tool Integration | 1-2 hours | Phase 1, Phase 2          |
| Phase 4: Testing & Docs   | 1 hour    | Phase 1, Phase 2, Phase 3 |
| **Total**                 | 6-8 hours |                           |

**Target Completion:** v0.7.0 release (Month 2-3)

---

## API Examples

### Example 1: Filesystem MCP Server

```php
use function Pagent\agent;

// Connect to filesystem MCP server (stdio transport)
$agent = agent('file-assistant')
    ->provider('anthropic', ['api_key' => env('ANTHROPIC_API_KEY')])
    ->system('You are a helpful file system assistant.')
    ->mcpServer('filesystem', [
        'transport' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/var/www'],
        'timeout' => 30,
    ]);

// MCP tools are now available to the agent
$response = $agent->prompt('List all PHP files in the src directory');
echo $response->content;

// Tool calling happens automatically
$response = $agent->prompt('Read the contents of composer.json');
echo $response->content;
```

### Example 2: Multiple MCP Servers

```php
// Connect to multiple MCP servers
$agent = agent('power-assistant')
    ->provider('openai', ['api_key' => env('OPENAI_API_KEY')])
    ->system('You are a powerful assistant with access to files, databases, and web.')
    ->mcpServer('filesystem', [
        'transport' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/app'],
    ])
    ->mcpServer('postgres', [
        'transport' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-postgres'],
        'env' => [
            'DATABASE_URL' => env('DATABASE_URL'),
        ],
    ])
    ->mcpServer('web', [
        'transport' => 'http',
        'url' => 'http://localhost:3000/mcp',
    ]);

// Agent now has tools from all three servers
$response = $agent->prompt(
    'Read the database schema from Postgres and save it to schema.md'
);
```

### Example 3: Error Handling

```php
try {
    $agent = agent('test')
        ->provider('mock')
        ->mcpServer('broken', [
            'transport' => 'stdio',
            'command' => '/nonexistent/server',
            'timeout' => 5,
        ]);
} catch (McpConnectionException $e) {
    echo "Failed to connect: {$e->getMessage()}\n";
    // Fallback: continue without MCP tools
}

// Or with graceful degradation
$agent = agent('resilient')
    ->provider('anthropic');

try {
    $agent->mcpServer('optional-tools', [/* ... */]);
} catch (McpException $e) {
    // Log error but continue
    error_log("MCP server unavailable: {$e->getMessage()}");
}

// Agent still works without MCP tools
$response = $agent->prompt('Hello');
```

### Example 4: Lazy Initialization

```php
// Don't auto-start server (lazy init)
$agent = agent('lazy')
    ->provider('anthropic')
    ->mcpServer('filesystem', [
        'transport' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-filesystem'],
        'auto_start' => false, // Don't start immediately
    ]);

// Server starts on first tool call
$response = $agent->prompt('Read file.txt'); // Starts server here
```

### Example 5: Manual Server Management

```php
$agent = agent('manual')
    ->provider('anthropic')
    ->mcpServer('fs', [/* ... */]);

// Get MCP client directly
$mcpClient = $agent->getMcpClient('fs');

// Manually discover tools
$tools = $mcpClient->discoverTools();
print_r($tools);

// Manually call tool
$result = $mcpClient->callTool('read_file', ['path' => 'test.txt']);
echo $result;

// Shutdown server
$mcpClient->shutdown();
```

---

## File Structure

After implementation, the file structure will be:

```
src/Mcp/
├── McpClient.php                     # Core client logic
├── McpServer.php                     # Server configuration value object
├── McpTool.php                       # ToolInterface adapter for MCP tools
├── Transports/
│   ├── McpTransport.php              # Transport interface
│   ├── StdioTransport.php            # Process-based transport
│   └── HttpTransport.php             # HTTP/SSE transport
└── Exceptions/
    ├── McpException.php              # Base exception
    ├── McpConnectionException.php    # Connection failures
    └── McpTimeoutException.php       # Timeout errors

tests/Unit/Mcp/
├── McpClientTest.php                 # Client unit tests
├── McpToolTest.php                   # Tool adapter tests
└── Transports/
    ├── StdioTransportTest.php        # Stdio transport tests
    └── HttpTransportTest.php         # HTTP transport tests

tests/Integration/
└── McpServerTest.php                 # Integration tests with real MCP servers

examples/
├── 12-mcp-filesystem.php             # Filesystem MCP server example
└── 13-mcp-multi-server.php           # Multiple MCP servers example

docs/
└── mcp-integration.md                # Comprehensive MCP documentation
```

---

## Migration Path

### Backward Compatibility

- **100% backward compatible** - MCP is opt-in via `Agent::mcpServer()`
- No changes to existing tool system or APIs
- MCP tools are just another ToolInterface implementation
- Existing agents work exactly as before

### Adoption Path

1. **Phase 1**: Use MCP for new agents (opt-in)
2. **Phase 2**: Gradually migrate existing tools to MCP servers (optional)
3. **Phase 3**: Eventually deprecate some built-in tools in favor of MCP (v1.0+)

### Example Migration

**Before (Built-in FileRead tool):**

```php
use Pagent\Tools\FileRead;

$agent = agent('bot')
    ->tool(new FileRead('/app'))
    ->prompt('Read config.json');
```

**After (MCP filesystem server):**

```php
$agent = agent('bot')
    ->mcpServer('filesystem', [
        'transport' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/app'],
    ])
    ->prompt('Read config.json');
```

Both work identically from the agent's perspective!

---

## Security Considerations

### Process Isolation

- MCP servers run in separate processes (stdio transport)
- Can't directly access Pagent memory or state
- Use process timeout to prevent runaway processes
- Kill processes on agent shutdown/reset

### Input Validation

- Validate MCP server configuration (command, args, URL)
- Sanitize tool parameters before sending to MCP server
- Validate JSON-RPC responses (schema validation)
- Reject responses that don't match expected format

### Resource Limits

- Enforce timeout on all MCP operations (default 30s)
- Limit number of concurrent MCP servers per agent (e.g., 10)
- Monitor process memory/CPU usage (future: cgroups)
- Kill zombie processes in destructor/shutdown handler

### Network Security (HTTP Transport)

- Support HTTPS for HTTP transport
- Allow custom headers (Authorization, API keys)
- Validate SSL certificates (can disable for localhost)
- Rate limiting on HTTP requests (future)

### Recommendations

1. **Trust Model**: Only connect to trusted MCP servers (localhost or internal network)
2. **Sandboxing**: Run MCP servers in containers/VMs for production
3. **Authentication**: Use API keys/tokens for HTTP transport
4. **Least Privilege**: MCP servers should have minimal permissions (e.g., read-only filesystem)
5. **Monitoring**: Log all MCP operations for audit trail

---

## Future Enhancements (Post v0.7.0)

### v0.8.0+

- [ ] **Resources Primitive**: Support MCP resources for RAG/context
- [ ] **Prompts Primitive**: Support MCP prompt templates
- [ ] **Sampling**: Allow MCP servers to request LLM access (complex)
- [ ] **Progress Notifications**: Show progress during long-running MCP operations
- [ ] **Caching**: Cache MCP tool results (semantic caching)
- [ ] **Retry Logic**: Automatic retry with exponential backoff
- [ ] **Connection Pooling**: Reuse MCP connections across agents

### v1.0.0+

- [ ] **MCP Server Implementation**: Expose Pagent agents as MCP servers
  - HTTP API endpoint that implements MCP protocol
  - Allow external clients (Claude Desktop, VS Code, etc.) to use Pagent agents
  - Full protocol compliance with tools, resources, prompts
- [ ] **MCP Server Registry**: Global registry of available MCP servers
- [ ] **Auto-Discovery**: Discover MCP servers on localhost (mDNS, etc.)
- [ ] **Authentication**: OAuth, JWT, API key support for MCP servers
- [ ] **Rate Limiting**: Per-server rate limits and quotas
- [ ] **Observability**: OpenTelemetry spans for MCP operations
- [ ] **WebSocket Transport**: Persistent connection alternative to stdio
- [ ] **MCP Inspector UI**: Debug MCP connections, view tool calls, inspect messages

---

## References

### MCP Documentation

- **MCP Specification**: https://modelcontextprotocol.io/
- **MCP GitHub**: https://github.com/modelcontextprotocol
- **Official PHP SDK**: https://github.com/modelcontextprotocol/php-sdk
- **Anthropic Announcement**: https://www.anthropic.com/news/model-context-protocol
- **Claude MCP Docs**: https://docs.claude.com/en/docs/mcp

### Pre-built MCP Servers

- **Filesystem**: https://github.com/modelcontextprotocol/servers/tree/main/src/filesystem
- **GitHub**: https://github.com/modelcontextprotocol/servers/tree/main/src/github
- **PostgreSQL**: https://github.com/modelcontextprotocol/servers/tree/main/src/postgres
- **Puppeteer**: https://github.com/modelcontextprotocol/servers/tree/main/src/puppeteer

### Related Technologies

- **JSON-RPC 2.0**: https://www.jsonrpc.org/specification
- **Language Server Protocol**: https://microsoft.github.io/language-server-protocol/
- **Server-Sent Events**: https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events

---

**Created:** 2025-10-29
**Last Updated:** 2025-10-29
**Status:** Planned for v0.7.0
