<?php

declare(strict_types=1);

use Pagent\Mcp\Exceptions\McpProtocolException;
use Pagent\Mcp\McpClient;
use Pagent\Mcp\Transports\StdioTransport;

/**
 * Comprehensive MCP Integration Tests
 *
 * Tests full MCP protocol integration including connection, tools, resources, and prompts.
 * Requires test-mcp-server.php to be available.
 */
beforeEach(function () {
    $this->serverScript = __DIR__.'/test-mcp-server.php';

    if (! file_exists($this->serverScript)) {
        $this->markTestSkipped('Test MCP server script not found');
    }
});

afterEach(function () {
    if (isset($this->client) && $this->client->isConnected()) {
        $this->client->disconnect();
    }
});

// ===== Connection Tests =====

test('establishes connection with proper initialization', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__,
        timeoutMs: 5000
    );

    $this->client = new McpClient($transport, 'test-client', '1.0.0');

    expect($this->client->isConnected())->toBeFalse();

    $this->client->connect();

    expect($this->client->isConnected())->toBeTrue();

    $capabilities = $this->client->getServerCapabilities();
    expect($capabilities)->toBeArray();
})->group('integration', 'mcp');

test('retrieves server capabilities after connection', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $capabilities = $this->client->getServerCapabilities();

    expect($capabilities)->toBeArray();
})->group('integration', 'mcp');

test('handles multiple connect calls idempotently', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);

    $this->client->connect();
    expect($this->client->isConnected())->toBeTrue();

    $this->client->connect();  // Second connect should be idempotent
    expect($this->client->isConnected())->toBeTrue();
})->group('integration', 'mcp');

test('disconnects cleanly', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    expect($this->client->isConnected())->toBeTrue();

    $this->client->disconnect();

    expect($this->client->isConnected())->toBeFalse();
})->group('integration', 'mcp');

// ===== Tool Discovery and Execution Tests =====

test('discovers all available tools', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $tools = $this->client->discoverTools();

    expect($tools)->toBeArray()
        ->and($tools)->toHaveCount(3)  // echo, add, get_time
        ->and($tools[0])->toHaveKey('name')
        ->and($tools[0])->toHaveKey('description')
        ->and($tools[0])->toHaveKey('inputSchema');
})->group('integration', 'mcp');

test('tool schemas include proper structure', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $tools = $this->client->discoverTools();
    $echoTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'echo'))[0];

    expect($echoTool['name'])->toBe('echo')
        ->and($echoTool['description'])->toBe('Echoes back the input text')
        ->and($echoTool['inputSchema'])->toBeArray()
        ->and($echoTool['inputSchema']['type'])->toBe('object')
        ->and($echoTool['inputSchema']['properties'])->toBeArray();
})->group('integration', 'mcp');

test('executes echo tool successfully', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $result = $this->client->callTool('echo', ['text' => 'Hello MCP!']);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('content')
        ->and($result['content'])->toBeArray()
        ->and($result['content'][0]['type'])->toBe('text')
        ->and($result['content'][0]['text'])->toBe('Hello MCP!');
})->group('integration', 'mcp');

test('executes add tool with correct calculation', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $result = $this->client->callTool('add', ['a' => 15, 'b' => 27]);

    expect($result)->toBeArray()
        ->and($result['content'][0]['text'])->toBe('42');
})->group('integration', 'mcp');

test('executes get_time tool without arguments', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $result = $this->client->callTool('get_time', []);

    expect($result)->toBeArray()
        ->and($result['content'][0]['type'])->toBe('text')
        ->and($result['content'][0]['text'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
})->group('integration', 'mcp');

test('handles tool execution with missing arguments', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    // Echo tool with no text argument should use default
    $result = $this->client->callTool('echo', []);

    expect($result['content'][0]['text'])->toBe('No text provided');
})->group('integration', 'mcp');

test('throws exception for non-existent tool', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    expect(fn () => $this->client->callTool('nonexistent_tool', []))
        ->toThrow(McpProtocolException::class);
})->group('integration', 'mcp');

// ===== Resource Tests =====

test('reads text resource successfully', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $content = $this->client->readResource('file:///test.txt');

    expect($content)->toBeArray()
        ->and($content)->toHaveKey('contents')
        ->and($content['contents'])->toBeArray()
        ->and($content['contents'][0]['uri'])->toBe('file:///test.txt')
        ->and($content['contents'][0]['mimeType'])->toBe('text/plain')
        ->and($content['contents'][0]['text'])->toBe('This is test content');
})->group('integration', 'mcp');

test('reads JSON resource successfully', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $content = $this->client->readResource('file:///data.json');

    expect($content['contents'][0]['mimeType'])->toBe('application/json')
        ->and($content['contents'][0]['text'])->toBe('{"key": "value"}');
})->group('integration', 'mcp');

test('throws exception for non-existent resource', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    expect(fn () => $this->client->readResource('file:///nonexistent.txt'))
        ->toThrow(McpProtocolException::class);
})->group('integration', 'mcp');

// ===== Prompt Tests =====

test('retrieves greeting prompt with arguments', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $prompt = $this->client->getPrompt('greeting', ['name' => 'Alice']);

    expect($prompt)->toBeArray()
        ->and($prompt)->toHaveKey('messages')
        ->and($prompt['messages'])->toBeArray()
        ->and($prompt['messages'][0]['role'])->toBe('user')
        ->and($prompt['messages'][0]['content']['text'])->toBe('Say hello to Alice');
})->group('integration', 'mcp');

test('retrieves summary prompt with text argument', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $prompt = $this->client->getPrompt('summary', ['text' => 'Long document here']);

    expect($prompt['messages'][0]['content']['text'])
        ->toBe('Summarize this: Long document here');
})->group('integration', 'mcp');

test('throws exception for non-existent prompt', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    expect(fn () => $this->client->getPrompt('nonexistent', []))
        ->toThrow(McpProtocolException::class);
})->group('integration', 'mcp');

// ===== Edge Cases and Error Handling =====

test('handles rapid consecutive tool calls', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $results = [];
    for ($i = 1; $i <= 5; $i++) {
        $result = $this->client->callTool('add', ['a' => $i, 'b' => $i]);
        $results[] = (int) $result['content'][0]['text'];
    }

    expect($results)->toBe([2, 4, 6, 8, 10]);
})->group('integration', 'mcp');

test('handles large text in echo tool', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $largeText = str_repeat('Lorem ipsum dolor sit amet. ', 100);
    $result = $this->client->callTool('echo', ['text' => $largeText]);

    expect($result['content'][0]['text'])->toBe($largeText);
})->group('integration', 'mcp');

test('maintains connection state across multiple operations', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    // Perform multiple operations
    $tools = $this->client->discoverTools();
    expect($tools)->toBeArray();

    $resources = $this->client->discoverResources();
    expect($resources)->toBeArray();

    $prompts = $this->client->discoverPrompts();
    expect($prompts)->toBeArray();

    $result = $this->client->callTool('echo', ['text' => 'test']);
    expect($result)->toBeArray();

    // Connection should still be active
    expect($this->client->isConnected())->toBeTrue();
})->group('integration', 'mcp');

test('handles tool calls with unicode characters', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $unicodeText = 'Hello 世界! 🚀';
    $result = $this->client->callTool('echo', ['text' => $unicodeText]);

    expect($result['content'][0]['text'])->toBe($unicodeText);
})->group('integration', 'mcp');

test('handles tool calls with special characters', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $specialText = 'Special chars: @#$%^&*()[]{}|\\";:<>?/~`';
    $result = $this->client->callTool('echo', ['text' => $specialText]);

    expect($result['content'][0]['text'])->toBe($specialText);
})->group('integration', 'mcp');

test('handles add tool with negative numbers', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $result = $this->client->callTool('add', ['a' => -10, 'b' => 5]);

    expect($result['content'][0]['text'])->toBe('-5');
})->group('integration', 'mcp');

test('handles add tool with decimal numbers', function () {
    $transport = new StdioTransport(
        command: "php {$this->serverScript}",
        cwd: __DIR__
    );

    $this->client = new McpClient($transport);
    $this->client->connect();

    $result = $this->client->callTool('add', ['a' => 1.5, 'b' => 2.5]);

    expect($result['content'][0]['text'])->toBe('4');
})->group('integration', 'mcp');
