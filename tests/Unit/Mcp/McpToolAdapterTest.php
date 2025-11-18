<?php

declare(strict_types=1);

use Pagent\Mcp\McpClient;
use Pagent\Mcp\McpToolAdapter;
use Tests\Unit\Mcp\Transports\FakeTransport;

beforeEach(function () {
    $this->transport = new FakeTransport;
    $this->client = new McpClient($this->transport, 'test-client', '1.0.0');
});

test('adapter implements Tool interface correctly', function () {
    $adapter = new McpToolAdapter(
        $this->client,
        'calculator',
        'Performs calculations',
        [
            'type' => 'object',
            'properties' => [
                'expression' => ['type' => 'string'],
            ],
        ]
    );

    expect($adapter->getName())->toBe('calculator')
        ->and($adapter->getDescription())->toBe('Performs calculations')
        ->and($adapter->getInputSchema())->toHaveKey('type', 'object')
        ->and($adapter->getInputSchema()['properties'])->toHaveKey('expression');
});

test('execute calls MCP tool and returns text content', function () {
    // Connect client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue tool call response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'content' => [
                ['type' => 'text', 'text' => '4'],
            ],
        ],
    ]);

    $adapter = new McpToolAdapter($this->client, 'calculator', 'Performs calculations', []);
    $result = $adapter->execute(['expression' => '2 + 2']);

    expect($result)->toBe('4');
});

test('execute returns data content when available', function () {
    // Connect client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue tool call response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'content' => [
                ['type' => 'resource', 'data' => ['users' => ['Alice', 'Bob']]],
            ],
        ],
    ]);

    $adapter = new McpToolAdapter($this->client, 'api_call', 'Makes API calls', []);
    $result = $adapter->execute(['endpoint' => '/users']);

    expect($result)->toBe(['users' => ['Alice', 'Bob']]);
});

test('execute returns multiple content items as array', function () {
    // Connect client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue tool call response
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'content' => [
                ['type' => 'text', 'text' => 'First output'],
                ['type' => 'text', 'text' => 'Second output'],
            ],
        ],
    ]);

    $adapter = new McpToolAdapter($this->client, 'multi_output', 'Returns multiple outputs', []);
    $result = $adapter->execute([]);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result[0]['text'])->toBe('First output')
        ->and($result[1]['text'])->toBe('Second output');
});

test('execute returns full result on unexpected structure', function () {
    // Connect client
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
        ],
    ]);
    $this->client->connect();

    // Queue tool call response with unexpected structure
    $this->transport->queueResponse([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'customField' => 'customValue',
        ],
    ]);

    $adapter = new McpToolAdapter($this->client, 'custom_tool', 'Custom tool', []);
    $result = $adapter->execute([]);

    expect($result)->toBe(['customField' => 'customValue']);
});

test('fromToolList creates adapters from MCP tool definitions', function () {
    $mcpTools = [
        [
            'name' => 'calculator',
            'description' => 'Performs calculations',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'expression' => ['type' => 'string'],
                ],
            ],
        ],
        [
            'name' => 'weather',
            'description' => 'Gets weather information',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
            ],
        ],
    ];

    $adapters = McpToolAdapter::fromToolList($this->client, $mcpTools);

    expect($adapters)->toHaveCount(2)
        ->and($adapters[0])->toBeInstanceOf(McpToolAdapter::class)
        ->and($adapters[0]->getName())->toBe('calculator')
        ->and($adapters[1])->toBeInstanceOf(McpToolAdapter::class)
        ->and($adapters[1]->getName())->toBe('weather');
});

test('fromToolList handles tools without description', function () {
    $mcpTools = [
        [
            'name' => 'simple_tool',
            // No description
            'inputSchema' => ['type' => 'object'],
        ],
    ];

    $adapters = McpToolAdapter::fromToolList($this->client, $mcpTools);

    expect($adapters)->toHaveCount(1)
        ->and($adapters[0]->getDescription())->toBe('');
});

test('fromToolList handles tools without inputSchema', function () {
    $mcpTools = [
        [
            'name' => 'no_input_tool',
            'description' => 'Tool with no input',
            // No inputSchema
        ],
    ];

    $adapters = McpToolAdapter::fromToolList($this->client, $mcpTools);

    expect($adapters)->toHaveCount(1)
        ->and($adapters[0]->getInputSchema())->toBe(['type' => 'object', 'properties' => []]);
});

test('adapter can be used as Pagent tool', function () {
    $adapter = new McpToolAdapter(
        $this->client,
        'test_tool',
        'Test tool',
        ['type' => 'object']
    );

    // Verify it implements the Tool interface
    expect($adapter)->toBeInstanceOf(\Pagent\Contracts\Tool::class);
});
