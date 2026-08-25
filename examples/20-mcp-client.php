<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * MCP (Model Context Protocol) Client Example
 *
 * This example demonstrates how to connect to an MCP server and use its tools.
 *
 * MCP allows Pagent to connect to external tool servers via a standardized protocol,
 * enabling integration with filesystem operations, databases, APIs, and more.
 *
 * Prerequisites:
 * - An MCP server executable (e.g., npx -y @modelcontextprotocol/server-filesystem /path/to/directory)
 * - PHP 8.4+ with proc_open support
 */

use Pagent\Mcp\McpClient;
use Pagent\Mcp\Transports\StdioTransport;

echo "[Pagent: MCP Client]\n";

// Example 1: Basic connection (requires an MCP server)
echo "\n[Example 1: Connecting to an MCP Server]\n";
echo "To run this example, you need an MCP server.\n";
echo "Install the filesystem server:\n";
echo "  npm install -g @modelcontextprotocol/server-filesystem\n\n";

// Uncomment to actually connect (requires MCP server installed):
/*
// 1. Create stdio transport to MCP server process
$transport = new StdioTransport(
    command: 'npx -y @modelcontextprotocol/server-filesystem /tmp',
    cwd: __DIR__,
    timeoutMs: 30000
);

// 2. Create MCP client
$client = new McpClient($transport, 'pagent-example', '0.7.0');

// 3. Connect and initialize
$client->connect();

echo "Connected to MCP server\n";
echo "Server capabilities: " . json_encode($client->getServerCapabilities(), JSON_PRETTY_PRINT) . "\n\n";

// 4. Discover available tools
$tools = $client->discoverTools();

echo "Available tools: " . count($tools) . "\n";
foreach ($tools as $tool) {
    echo "  - {$tool['name']}: {$tool['description']}\n";
}
echo "\n";

// 5. Call a tool directly
$result = $client->callTool('read_file', [
    'path' => '/tmp/test.txt',
]);

echo "Tool result:\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

// 6. Disconnect
$client->disconnect();
echo "Disconnected\n\n";
*/

// Example 2: HTTP/SSE Transport for Web-based MCP Servers
echo "\n[Example 2: HTTP/SSE Transport (context7, web servers)]\n";
echo "For web-based MCP servers, use HTTP/SSE transport:\n\n";

echo <<<'PHP'
use Pagent\Mcp\Transports\HttpSseTransport;

// Connect to HTTP-based MCP server (e.g., context7)
$transport = new HttpSseTransport(
    baseUrl: 'http://localhost:3000',
    headers: ['Authorization' => 'Bearer your-token'],
    timeoutMs: 10000
);

$client = new McpClient($transport);
$client->connect();

// Discover and use tools
$tools = $client->discoverTools();
$result = $client->callTool('get-library-docs', [
    'libraryId' => 'react@18.2.0',
    'query' => 'useState hook',
]);

$client->disconnect();

PHP;
echo "\n";

// Example 3: Using MCP tools with Pagent agents
echo "\n[Example 3: Integrating MCP Tools with Pagent Agents]\n";
echo "MCP tools can be seamlessly integrated with Pagent agents using McpToolAdapter.\n\n";

echo "Code example:\n";
echo <<<'PHP'
// Connect to MCP server (stdio or HTTP)
$client = new McpClient($transport);
$client->connect();

// Discover and adapt tools
$mcpTools = $client->discoverTools();
$tools = McpToolAdapter::fromToolList($client, $mcpTools);

// Create agent with MCP tools
$agent = agent('assistant')
    ->provider(anthropic())
    ->tools($tools);  // Array of Tool instances

// Agent can now use MCP server tools!
$response = $agent->prompt('List files in the current directory');

PHP;
echo "\n";

// Example 4: Transport Comparison
echo "\n[Example 4: Choosing the Right Transport]\n";
echo "Stdio Transport:\n";
echo "  - For local MCP server processes\n";
echo "  - Spawns server as subprocess\n";
echo "  - Uses stdin/stdout for communication\n";
echo "  - Example: filesystem, GitHub CLI tools\n\n";

echo "HTTP/SSE Transport:\n";
echo "  - For web-based MCP servers\n";
echo "  - HTTP POST for requests, SSE for responses\n";
echo "  - Supports authentication headers\n";
echo "  - Example: context7, cloud-based tools\n\n";

echo <<<'PHP'
// Stdio: Local process
$stdioTransport = new StdioTransport(
    command: 'npx @modelcontextprotocol/server-filesystem /tmp'
);

// HTTP/SSE: Remote server
$httpTransport = new HttpSseTransport(
    baseUrl: 'https://api.example.com/mcp',
    headers: ['Authorization' => 'Bearer token']
);

PHP;
echo "\n";

// Example 5: Available MCP Servers
echo "\n[Example 5: Available MCP Servers]\n";
echo "Some official MCP servers you can use:\n\n";

echo "1. Filesystem Server:\n";
echo "   npm install -g @modelcontextprotocol/server-filesystem\n";
echo "   Command: npx @modelcontextprotocol/server-filesystem /path/to/directory\n\n";

echo "2. GitHub Server:\n";
echo "   npm install -g @modelcontextprotocol/server-github\n";
echo "   Command: npx @modelcontextprotocol/server-github\n\n";

echo "3. Memory Server:\n";
echo "   npm install -g @modelcontextprotocol/server-memory\n";
echo "   Command: npx @modelcontextprotocol/server-memory\n\n";

echo "4. Brave Search Server:\n";
echo "   npm install -g @modelcontextprotocol/server-brave-search\n";
echo "   Command: npx @modelcontextprotocol/server-brave-search\n\n";

echo "See https://github.com/modelcontextprotocol/servers for more MCP servers\n\n";

// Example 6: Error handling
echo "\n[Example 6: Error Handling]\n";
echo <<<'PHP'
use Pagent\Mcp\Exceptions\McpConnectionException;
use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\Exceptions\McpTimeoutException;

try {
    $client->connect();
    $result = $client->callTool('some_tool', ['arg' => 'value']);
} catch (McpConnectionException $e) {
    // Handle connection errors
    echo "Connection error: " . $e->getMessage();
} catch (McpProtocolException $e) {
    // Handle protocol/server errors
    echo "Protocol error: " . $e->getMessage();
} catch (McpTimeoutException $e) {
    // Handle timeouts
    echo "Timeout: " . $e->getMessage();
} finally {
    $client->disconnect();
}

PHP;
echo "\n";

echo "MCP client capabilities:\n";
echo "  - Standardized protocol for tool integration\n";
echo "  - Automatic tool discovery\n";
echo "  - Seamless integration with Pagent agents\n";
echo "  - Support for any MCP-compliant server\n";
echo "  - Type-safe tool execution\n\n";

echo "For more information:\n";
echo "  - MCP Specification: https://spec.modelcontextprotocol.io\n";
echo "  - MCP Servers: https://github.com/modelcontextprotocol/servers\n";
echo "\nDone.\n";
