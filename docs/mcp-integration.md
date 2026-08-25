# MCP Integration

The Model Context Protocol (MCP) is a standardized protocol that allows AI agents to connect to external tool servers. Pagent includes a full MCP client implementation, enabling your agents to use tools provided by any MCP-compliant server.

## What is MCP?

MCP servers expose tools, resources, and prompts through a JSON-RPC 2.0 protocol. This allows:

- **Tool servers**: Filesystem access, database queries, API integrations
- **Standardization**: One protocol for many different tool providers
- **Isolation**: Tools run in separate processes for security
- **Discovery**: Agents can discover available tools at runtime

See the [MCP Specification](https://spec.modelcontextprotocol.io) for the full protocol details.

## Quick Start

```php
use Pagent\Mcp\McpClient;
use Pagent\Mcp\McpToolAdapter;
use Pagent\Mcp\Transports\StdioTransport;

// 1. Create transport to MCP server
$transport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-filesystem /tmp'
);

// 2. Create and connect client
$client = new McpClient($transport);
$client->connect();

// 3. Discover tools
$mcpTools = $client->discoverTools();

// 4. Convert to Pagent tools
$tools = McpToolAdapter::fromToolList($client, $mcpTools);

// 5. Use with agent
$agent = agent('assistant')
    ->provider(anthropic())
    ->tools($tools);

$response = $agent->prompt('List files in the current directory');

// 6. Cleanup
$client->disconnect();
```

## Transport Types

### StdioTransport

For MCP servers that run as local processes:

```php
use Pagent\Mcp\Transports\StdioTransport;

$transport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-filesystem /path/to/dir',
    cwd: __DIR__,           // Working directory (optional)
    env: [],                // Environment variables (optional)
    timeoutMs: 30000        // Timeout in milliseconds (default: 30000)
);
```

**Use cases:**

- Filesystem operations
- Local database access
- CLI tool wrappers
- Development and testing

### HttpSseTransport

For web-based MCP servers:

```php
use Pagent\Mcp\Transports\HttpSseTransport;

$transport = new HttpSseTransport(
    baseUrl: 'https://mcp.example.com',
    headers: [
        'Authorization' => 'Bearer your-api-key',
    ],
    timeoutMs: 10000
);
```

**Use cases:**

- Cloud-hosted MCP servers
- API integrations
- Multi-tenant tool services
- Context7 and similar services

## McpClient API

### Connecting

```php
$client = new McpClient(
    transport: $transport,
    clientName: 'my-app',        // Optional: client identifier
    clientVersion: '1.0.0'       // Optional: client version
);

// Connect and initialize
$client->connect();

// Check connection status
if ($client->isConnected()) {
    // Ready to use
}

// Get server capabilities
$capabilities = $client->getServerCapabilities();
```

### Discovering Tools

```php
// Discover all available tools
$tools = $client->discoverTools();

foreach ($tools as $tool) {
    echo $tool['name'] . ': ' . $tool['description'] . "\n";
}

// Get cached tools (after discovery)
$cachedTools = $client->getAvailableTools();
```

### Calling Tools Directly

```php
// Call a tool with arguments
$result = $client->callTool('read_file', [
    'path' => '/tmp/example.txt'
]);

// Result structure (varies by tool)
// [
//     'content' => [
//         ['type' => 'text', 'text' => 'file contents...']
//     ]
// ]
```

### Working with Resources

```php
// Discover available resources
$resources = $client->discoverResources();

// Read a specific resource
$content = $client->readResource('file:///path/to/resource');
```

### Working with Prompts

```php
// Discover available prompts
$prompts = $client->discoverPrompts();

// Get a prompt with arguments
$prompt = $client->getPrompt('code_review', [
    'language' => 'php',
    'code' => $codeToReview
]);
```

### Disconnecting

```php
$client->disconnect();
```

## Integrating with Agents

### Using McpToolAdapter

The `McpToolAdapter` converts MCP tools into Pagent's canonical, provider-neutral
`Pagent\Contracts\Tool` contract. The resulting adapters attach directly through
`Agent::tools()`; provider-specific JSON schema formatting happens only at the
provider boundary.

```php
use Pagent\Mcp\McpToolAdapter;

// Convert all discovered tools
$tools = McpToolAdapter::fromToolList($client, $client->discoverTools());

// Use with agent
$agent = agent('mcp-agent')
    ->provider(anthropic())
    ->tools($tools)
    ->system('You have access to filesystem tools.');

$response = $agent->prompt('Create a file called hello.txt with "Hello World"');
```

### Selective Tool Loading

```php
$mcpTools = $client->discoverTools();

// Filter to specific tools
$selectedTools = array_filter($mcpTools, fn($t) =>
    in_array($t['name'], ['read_file', 'list_directory'])
);

$tools = McpToolAdapter::fromToolList($client, $selectedTools);
```

## Event Handling

MCP operations emit events for monitoring:

```php
use Pagent\Events\Events\Mcp\McpConnectionEstablishedEvent;
use Pagent\Events\Events\Mcp\McpToolCalledEvent;
use Pagent\Events\Events\Mcp\McpToolErrorEvent;

// On the client instance
$client->on('mcp_connection_established', function($event) {
    echo "Connected! Server caps: " . json_encode($event->serverCapabilities);
});

$client->on('mcp_tool_called', function($event) {
    echo "Tool {$event->toolName} completed in {$event->durationMs}ms";
});

$client->on('mcp_tool_error', function($event) {
    log_error("MCP tool error: " . $event->error->getMessage());
});
```

### Available Events

| Event                           | When                       |
| ------------------------------- | -------------------------- |
| `McpConnectionInitiatingEvent`  | Before connection starts   |
| `McpConnectionEstablishedEvent` | Connection successful      |
| `McpConnectionFailedEvent`      | Connection failed          |
| `McpDisconnectingEvent`         | Before disconnect          |
| `McpDisconnectedEvent`          | After disconnect           |
| `McpToolsDiscoveringEvent`      | Before tool discovery      |
| `McpToolsDiscoveredEvent`       | Tools discovered           |
| `McpToolCallingEvent`           | Before tool execution      |
| `McpToolCalledEvent`            | Tool executed successfully |
| `McpToolErrorEvent`             | Tool execution failed      |

## Error Handling

```php
use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\Exceptions\McpTimeoutException;

try {
    $client->connect();
    $result = $client->callTool('some_tool', ['arg' => 'value']);
} catch (McpConnectionException $e) {
    // Connection failed (server not running, transport error)
    echo "Connection error: " . $e->getMessage();
} catch (McpProtocolException $e) {
    // Protocol error (invalid response, server error)
    echo "Protocol error: " . $e->getMessage();
} catch (McpTimeoutException $e) {
    // Operation timed out
    echo "Timeout: " . $e->getMessage();
} finally {
    if ($client->isConnected()) {
        $client->disconnect();
    }
}
```

## Popular MCP Servers

Install and use official MCP servers:

### Filesystem Server

```bash
npm install -g @modelcontextprotocol/server-filesystem
```

```php
$transport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-filesystem /allowed/directory'
);
```

**Tools provided:** `read_file`, `write_file`, `list_directory`, `create_directory`, etc.

### GitHub Server

```bash
npm install -g @modelcontextprotocol/server-github
```

```php
$transport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-github',
    env: ['GITHUB_TOKEN' => $token]
);
```

**Tools provided:** Repository operations, issue management, PR handling

### Memory Server

```bash
npm install -g @modelcontextprotocol/server-memory
```

```php
$transport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-memory'
);
```

**Tools provided:** Key-value storage for agent memory

### Brave Search Server

```bash
npm install -g @modelcontextprotocol/server-brave-search
```

```php
$transport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-brave-search',
    env: ['BRAVE_API_KEY' => $apiKey]
);
```

**Tools provided:** Web search capabilities

See [MCP Servers Repository](https://github.com/modelcontextprotocol/servers) for more options.

## Progress Tracking

For long-running tool operations:

```php
$client->setProgressCallback(function($token, $progress, $total) {
    if ($total !== null) {
        $percent = round(($progress / $total) * 100);
        echo "Progress: {$percent}%\n";
    } else {
        echo "Progress: {$progress}\n";
    }
});
```

## Best Practices

1. **Reuse connections**: Create one client per MCP server and reuse it
2. **Handle disconnects**: Always wrap operations in try/finally for cleanup
3. **Filter tools**: Only load the tools your agent actually needs
4. **Set timeouts**: Configure appropriate timeouts for your use case
5. **Monitor with events**: Use events for logging and metrics
6. **Validate inputs**: MCP servers may reject invalid arguments

## Example: Full Integration

```php
<?php

use Pagent\Mcp\McpClient;
use Pagent\Mcp\McpToolAdapter;
use Pagent\Mcp\Transports\StdioTransport;
use Pagent\Events\Events\Mcp\McpToolCalledEvent;

// Setup
$transport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-filesystem /home/user/documents'
);

$client = new McpClient($transport, 'my-app', '1.0.0');

// Add logging
$client->on('mcp_tool_called', function($event) {
    error_log("MCP: {$event->toolName} took {$event->durationMs}ms");
});

try {
    // Connect and discover
    $client->connect();
    $tools = McpToolAdapter::fromToolList($client, $client->discoverTools());

    // Create agent with MCP tools
    $agent = agent('file-assistant')
        ->provider(anthropic())
        ->tools($tools)
        ->system('You help users manage their documents. Use the available file tools.');

    // Use the agent
    $response = $agent->prompt('What files are in my documents folder?');
    echo $response->content;

} finally {
    $client->disconnect();
}
```

## See Also

- [MCP Specification](https://spec.modelcontextprotocol.io) - Official protocol documentation
- [MCP Servers](https://github.com/modelcontextprotocol/servers) - Official server implementations
- [Tools](../README.md#tools) - Built-in Pagent tools
- [Events](events.md) - Event system documentation
