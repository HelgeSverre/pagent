# MCP Server Implementation Specification

**Status:** Planning
**Target Version:** v0.15.0 - MCP Server Implementation
**Created:** 2025-10-29
**Estimated Effort:** 6-8 hours

## Overview

Implement an MCP (Model Context Protocol) server that exposes Pagent agents as tools that can be consumed by any MCP client (Claude Desktop, Cline, Zed, etc.). This is the **inverse** of the v0.7.0 MCP Consumer feature.

## Concept

**v0.7.0 - MCP Consumer (Already Implemented):**
- Pagent agents can USE tools from external MCP servers
- Pagent is the **client**
- Example: Pagent agent calls filesystem tools from an MCP server

**v0.15.0 - MCP Server (This Spec):**
- External tools can USE Pagent agents as tools
- Pagent is the **server**
- Example: Claude Desktop calls a "PHP code generator" Pagent agent

## Use Cases

### 1. IDE Integration
```
Claude Desktop → MCP Protocol → Pagent Server → Code Generation Agent
```
- Developer asks Claude Desktop to "write a Laravel controller"
- Claude Desktop sees "laravel_generator" tool from Pagent MCP server
- Calls tool → Pagent agent generates code → Returns to Claude Desktop

### 2. Multi-Language Workflows
```
Python Script → MCP Client → Pagent Server → PHP Expert Agent
```
- Python app needs PHP code generation
- Calls Pagent MCP server with "php_expert" tool
- Gets PHP code back

### 3. Agent Marketplace
```
Multiple Clients → Pagent Server → Specialized Agents
                                  ├─ Legal Agent
                                  ├─ Code Review Agent
                                  ├─ SQL Generator Agent
                                  └─ Content Writer Agent
```
- Single Pagent server exposes many specialized agents
- Multiple clients can consume them via standard MCP protocol

## Architecture

### MCP Protocol Overview

**Protocol:** JSON-RPC 2.0 over stdio or HTTP
**Transports:**
- `stdio` - Standard input/output (for local processes)
- `http` - HTTP server (for network access)

**Core Methods:**
1. `initialize` - Client/server handshake
2. `tools/list` - Server lists available tools
3. `tools/call` - Client calls a tool
4. `notifications/*` - Status updates

### Pagent Server Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    MCP Server                           │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐    │
│  │ stdio       │  │ HTTP        │  │ WebSocket   │    │
│  │ Transport   │  │ Transport   │  │ Transport   │    │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘    │
│         │                 │                 │            │
│         └─────────────────┴─────────────────┘            │
│                           │                              │
│                   ┌───────▼────────┐                     │
│                   │  MCP Handler   │                     │
│                   │  (JSON-RPC)    │                     │
│                   └───────┬────────┘                     │
│                           │                              │
│                   ┌───────▼────────┐                     │
│                   │ Agent Registry │                     │
│                   │   Resolver     │                     │
│                   └───────┬────────┘                     │
│                           │                              │
│         ┌─────────────────┼─────────────────┐            │
│         │                 │                 │            │
│   ┌─────▼─────┐    ┌─────▼─────┐    ┌─────▼─────┐      │
│   │ Agent 1   │    │ Agent 2   │    │ Agent 3   │      │
│   │ "sql_gen" │    │ "code_rev"│    │ "content" │      │
│   └───────────┘    └───────────┘    └───────────┘      │
└─────────────────────────────────────────────────────────┘
```

## API Design

### 1. Server Configuration

```php
use Pagent\MCP\Server;

// Create MCP server
$server = new Server();

// Register agents as MCP tools
$server->registerAgent(
    agent: agent('sql-generator')
        ->provider(anthropic())
        ->system('You are an expert SQL generator'),
    name: 'generate_sql',
    description: 'Generate SQL queries from natural language',
);

$server->registerAgent(
    agent: agent('code-reviewer')
        ->provider(anthropic())
        ->system('You are a code review expert'),
    name: 'review_code',
    description: 'Review code and provide suggestions',
);

// Start server (stdio transport)
$server->listen('stdio');

// Or start HTTP server
$server->listen('http', port: 8080);
```

### 2. Agent Registration Options

```php
// Simple registration
$server->registerAgent($agent, 'tool_name', 'description');

// With input schema
$server->registerAgent(
    agent: $agent,
    name: 'generate_sql',
    description: 'Generate SQL from natural language',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'description' => [
                'type' => 'string',
                'description' => 'Natural language description of the query',
            ],
            'database_type' => [
                'type' => 'string',
                'enum' => ['mysql', 'postgres', 'sqlite'],
                'description' => 'Target database type',
            ],
        ],
        'required' => ['description'],
    ],
);

// Register all agents from registry
$server->registerAllAgents();
```

### 3. CLI Usage

```bash
# Start MCP server (stdio)
php pagent-server.php

# Start MCP server (HTTP)
php pagent-server.php --transport=http --port=8080

# List registered agents
php pagent-server.php --list

# Test server
php pagent-server.php --test
```

### 4. Client Configuration

**For Claude Desktop (`claude_desktop_config.json`):**
```json
{
  "mcpServers": {
    "pagent": {
      "command": "php",
      "args": ["/path/to/pagent-server.php"],
      "env": {
        "ANTHROPIC_API_KEY": "sk-..."
      }
    }
  }
}
```

**For HTTP clients:**
```bash
curl -X POST http://localhost:8080/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
      "name": "generate_sql",
      "arguments": {
        "description": "Get all users who signed up in the last 30 days"
      }
    },
    "id": 1
  }'
```

## MCP Protocol Implementation

### 1. Transport Layer

**Stdio Transport:**
```php
class StdioTransport implements Transport
{
    public function listen(callable $handler): void
    {
        while ($line = fgets(STDIN)) {
            $request = json_decode($line, true);
            $response = $handler($request);
            fwrite(STDOUT, json_encode($response) . "\n");
        }
    }
}
```

**HTTP Transport:**
```php
class HttpTransport implements Transport
{
    public function __construct(private int $port = 8080) {}

    public function listen(callable $handler): void
    {
        $server = new \React\Http\Server(function ($request) use ($handler) {
            $body = (string) $request->getBody();
            $request = json_decode($body, true);
            $response = $handler($request);

            return new \React\Http\Message\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($response)
            );
        });

        $server->listen(new \React\Socket\Server("0.0.0.0:{$this->port}"));
    }
}
```

### 2. JSON-RPC Handler

```php
class McpHandler
{
    private array $agents = [];

    public function handle(array $request): array
    {
        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        return match ($method) {
            'initialize' => $this->initialize($params, $id),
            'tools/list' => $this->listTools($id),
            'tools/call' => $this->callTool($params, $id),
            default => $this->error('Method not found', $id),
        };
    }

    private function initialize(array $params, $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'serverInfo' => [
                    'name' => 'pagent-mcp-server',
                    'version' => '0.15.0',
                ],
                'capabilities' => [
                    'tools' => (object)[],
                ],
            ],
        ];
    }

    private function listTools($id): array
    {
        $tools = [];

        foreach ($this->agents as $name => $config) {
            $tools[] = [
                'name' => $name,
                'description' => $config['description'],
                'inputSchema' => $config['inputSchema'] ?? [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Input prompt for the agent',
                        ],
                    ],
                    'required' => ['prompt'],
                ],
            ];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools],
        ];
    }

    private function callTool(array $params, $id): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->agents[$name])) {
            return $this->error("Tool '{$name}' not found", $id);
        }

        $agent = $this->agents[$name]['agent'];

        // Extract prompt from arguments
        $prompt = $arguments['prompt'] ?? json_encode($arguments);

        try {
            $response = $agent->prompt($prompt);

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $response->content,
                        ],
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $id);
        }
    }

    private function error(string $message, $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32600,
                'message' => $message,
            ],
        ];
    }

    public function registerAgent(
        Agent $agent,
        string $name,
        string $description,
        array $inputSchema = []
    ): void {
        $this->agents[$name] = [
            'agent' => $agent,
            'description' => $description,
            'inputSchema' => $inputSchema,
        ];
    }
}
```

## Example Server Implementation

**pagent-server.php:**
```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Pagent\MCP\Server;

// Create server
$server = new Server();

// Register SQL generator agent
$server->registerAgent(
    agent: agent('sql-generator')
        ->provider(anthropic())
        ->model('claude-3-5-sonnet-20241022')
        ->system('You are an expert SQL generator. Generate clean, efficient SQL queries.'),
    name: 'generate_sql',
    description: 'Generate SQL queries from natural language descriptions',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'description' => [
                'type' => 'string',
                'description' => 'Natural language description of what you want to query',
            ],
            'database' => [
                'type' => 'string',
                'enum' => ['mysql', 'postgres', 'sqlite'],
                'description' => 'Database type',
                'default' => 'mysql',
            ],
        ],
        'required' => ['description'],
    ]
);

// Register code reviewer agent
$server->registerAgent(
    agent: agent('code-reviewer')
        ->provider(anthropic())
        ->model('claude-3-5-sonnet-20241022')
        ->system('You are a senior code reviewer. Provide constructive feedback.'),
    name: 'review_code',
    description: 'Review code and provide suggestions for improvement'
);

// Register content writer agent
$server->registerAgent(
    agent: agent('content-writer')
        ->provider(anthropic())
        ->model('claude-3-5-sonnet-20241022')
        ->system('You are a professional content writer. Write engaging, clear content.'),
    name: 'write_content',
    description: 'Generate high-quality written content'
);

// Parse CLI args
$transport = 'stdio';
$port = 8080;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--transport=')) {
        $transport = substr($arg, 12);
    } elseif (str_starts_with($arg, '--port=')) {
        $port = (int) substr($arg, 7);
    } elseif ($arg === '--list') {
        $server->listAgents();
        exit(0);
    } elseif ($arg === '--test') {
        $server->test();
        exit(0);
    }
}

// Start server
echo "Starting Pagent MCP Server ({$transport})...\n";

if ($transport === 'http') {
    echo "Listening on http://localhost:{$port}\n";
}

$server->listen($transport, $port);
```

## Authentication & Security

### 1. API Key Authentication (HTTP)

```php
class HttpTransport
{
    public function __construct(
        private int $port = 8080,
        private ?string $apiKey = null,
    ) {}

    public function listen(callable $handler): void
    {
        $server = new \React\Http\Server(function ($request) use ($handler) {
            // Check API key
            if ($this->apiKey) {
                $authHeader = $request->getHeaderLine('Authorization');

                if ($authHeader !== "Bearer {$this->apiKey}") {
                    return new \React\Http\Message\Response(
                        401,
                        ['Content-Type' => 'application/json'],
                        json_encode(['error' => 'Unauthorized'])
                    );
                }
            }

            // Handle request...
        });

        $server->listen(new \React\Socket\Server("0.0.0.0:{$this->port}"));
    }
}
```

### 2. Rate Limiting

```php
$server = new Server();

// Add rate limiting middleware
$server->middleware(new RateLimitMiddleware(
    maxRequests: 10,
    windowSeconds: 60
));

$server->registerAgent($agent, 'tool_name', 'description');
$server->listen('http', port: 8080);
```

### 3. CORS (for browser clients)

```php
class HttpTransport
{
    private function addCorsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
    }
}
```

## Implementation Plan

### Phase 1: Core Protocol (2 hours)
1. Create `MCP/Server.php` class
2. Create `MCP/McpHandler.php` for JSON-RPC
3. Implement `initialize`, `tools/list`, `tools/call` methods
4. Add agent registration API

### Phase 2: Transport Layer (2 hours)
1. Implement `MCP/Transports/StdioTransport.php`
2. Implement `MCP/Transports/HttpTransport.php`
3. Add transport factory/selection logic
4. Test both transports

### Phase 3: CLI & Server Script (1 hour)
1. Create `bin/pagent-server.php` script
2. Add CLI argument parsing
3. Add `--list`, `--test` commands
4. Make script executable

### Phase 4: Security & Middleware (1 hour)
1. Add API key authentication for HTTP
2. Add rate limiting support
3. Add CORS headers
4. Add request logging

### Phase 5: Documentation & Testing (2 hours)
1. Write integration tests
2. Create example server implementations
3. Document MCP server setup
4. Create Claude Desktop integration guide
5. Test with real MCP clients

## Configuration Examples

### Environment Variables

```bash
PAGENT_MCP_TRANSPORT=http
PAGENT_MCP_PORT=8080
PAGENT_MCP_API_KEY=secret-key-123
PAGENT_MCP_RATE_LIMIT=100
PAGENT_MCP_LOG_REQUESTS=true
```

### Config File

**pagent-mcp.php:**
```php
<?php

return [
    'transport' => env('PAGENT_MCP_TRANSPORT', 'stdio'),
    'port' => env('PAGENT_MCP_PORT', 8080),
    'api_key' => env('PAGENT_MCP_API_KEY'),
    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 100,
        'window_seconds' => 60,
    ],
    'logging' => [
        'enabled' => true,
        'file' => storage_path('logs/pagent-mcp.log'),
    ],
];
```

## Testing Strategy

### Unit Tests

```php
test('MCP server lists registered agents', function () {
    $server = new Server();

    $server->registerAgent(
        agent('test')->provider(mock()),
        'test_tool',
        'Test tool'
    );

    $handler = $server->getHandler();
    $response = $handler->handle([
        'jsonrpc' => '2.0',
        'method' => 'tools/list',
        'id' => 1,
    ]);

    expect($response['result']['tools'])
        ->toHaveCount(1)
        ->and($response['result']['tools'][0]['name'])
        ->toBe('test_tool');
});

test('MCP server calls agent tool', function () {
    $agent = agent('test')->provider(mock([
        'test' => 'response from agent',
    ]));

    $server = new Server();
    $server->registerAgent($agent, 'test_tool', 'Test');

    $handler = $server->getHandler();
    $response = $handler->handle([
        'jsonrpc' => '2.0',
        'method' => 'tools/call',
        'params' => [
            'name' => 'test_tool',
            'arguments' => ['prompt' => 'test'],
        ],
        'id' => 1,
    ]);

    expect($response['result']['content'][0]['text'])
        ->toBe('response from agent');
});
```

### Integration Tests

```php
test('stdio transport works end-to-end', function () {
    // Start server process
    $process = proc_open(
        'php bin/pagent-server.php',
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    // Send request
    fwrite($pipes[0], json_encode([
        'jsonrpc' => '2.0',
        'method' => 'tools/list',
        'id' => 1,
    ]) . "\n");

    // Read response
    $response = json_decode(fgets($pipes[1]), true);

    expect($response['result']['tools'])->toBeArray();

    // Cleanup
    proc_close($process);
});
```

## Files to Create/Modify

1. **src/MCP/Server.php** - Main server class
2. **src/MCP/McpHandler.php** - JSON-RPC handler
3. **src/MCP/Transports/StdioTransport.php** - Stdio transport
4. **src/MCP/Transports/HttpTransport.php** - HTTP transport
5. **src/MCP/Transports/Transport.php** - Transport interface
6. **bin/pagent-server.php** - CLI script
7. **tests/Unit/MCP/** - Unit tests
8. **tests/Integration/McpServerTest.php** - Integration tests
9. **README.md** - Add MCP server documentation
10. **ai-docs/FEATURES.md** - Document MCP server feature

## Dependencies

**Required:**
- `php >= 8.3`
- `ext-json`

**Optional (for HTTP transport):**
- `react/http` - Async HTTP server
- `react/socket` - Socket server
- `react/event-loop` - Event loop

**Install HTTP deps:**
```bash
composer require react/http react/socket
```

## Example Use Cases

### Use Case 1: SQL Generator for Claude Desktop

**Setup:**
```bash
# Configure Claude Desktop
{
  "mcpServers": {
    "pagent-sql": {
      "command": "php",
      "args": ["/path/to/sql-server.php"]
    }
  }
}
```

**Usage:**
```
User: "Generate SQL to get all active users"
Claude Desktop: [sees "generate_sql" tool]
Claude Desktop: [calls tool with description]
Pagent: SELECT * FROM users WHERE status = 'active'
Claude Desktop: "Here's the SQL query..."
```

### Use Case 2: Code Review API

**Server:**
```php
$server = new Server();
$server->registerAgent($reviewAgent, 'review_code', 'Review code');
$server->listen('http', port: 8080);
```

**Client (any language):**
```python
import requests

response = requests.post('http://localhost:8080/mcp', json={
    'jsonrpc': '2.0',
    'method': 'tools/call',
    'params': {
        'name': 'review_code',
        'arguments': {
            'prompt': 'Review this code: function foo() { return 1+1; }'
        }
    },
    'id': 1
})

result = response.json()['result']['content'][0]['text']
print(result)
```

## Success Metrics

- Successfully expose agents as MCP tools
- Work with Claude Desktop, Cline, Zed
- < 50ms overhead for MCP protocol
- Support both stdio and HTTP transports
- Authentication and rate limiting functional

## Future Enhancements (v2.0+)

- WebSocket transport
- Streaming responses
- Agent clustering (multiple servers)
- Built-in monitoring dashboard
- Auto-generated client SDKs
