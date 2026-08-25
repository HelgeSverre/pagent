<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Contracts\Tool as ToolContract;
use Pagent\Contracts\ToolInterface;
use Pagent\Mcp\McpClient;
use Pagent\Mcp\McpToolAdapter;
use Pagent\Tool\Tool;
use Pagent\Tool\ToolSchemaSerializer;
use Pagent\Tools\FileRead;
use Tests\Unit\Mcp\Transports\FakeTransport;

test('closure, built-in, and MCP tools share the canonical contract', function (): void {
    $tools = [
        Tool::fromClosure('echo', 'Echo a value', fn (string $value): string => $value),
        new FileRead,
        new McpToolAdapter(
            new McpClient(new FakeTransport, 'test-client', '1.0.0'),
            'remote_search',
            'Search a remote index',
            [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
                'required' => ['query'],
            ],
        ),
    ];

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(ToolContract::class)
            ->and($tool)->toBeInstanceOf(ToolInterface::class)
            ->and($tool->getName())->toBeString()
            ->and($tool->getDescription())->toBeString()
            ->and($tool->getInputSchema())->toHaveKey('type', 'object');
    }
});

test('schema serializer maps the canonical contract to provider payloads', function (): void {
    $tool = new McpToolAdapter(
        new McpClient(new FakeTransport, 'test-client', '1.0.0'),
        'remote_search',
        'Search a remote index',
        [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ],
    );

    expect(ToolSchemaSerializer::anthropic($tool))->toBe([
        'name' => 'remote_search',
        'description' => 'Search a remote index',
        'input_schema' => $tool->getInputSchema(),
    ])->and(ToolSchemaSerializer::openAI($tool))->toBe([
        'type' => 'function',
        'function' => [
            'name' => 'remote_search',
            'description' => 'Search a remote index',
            'parameters' => $tool->getInputSchema(),
        ],
    ]);
});

test('an MCP adapter can be registered through the same Agent API as local tools', function (): void {
    $adapter = new McpToolAdapter(
        new McpClient(new FakeTransport, 'test-client', '1.0.0'),
        'remote_search',
        'Search a remote index',
        ['type' => 'object', 'properties' => []],
    );

    $agent = new Agent('mcp-agent');
    $agent->tool($adapter);

    expect($agent->getTools())->toHaveCount(1)
        ->and($agent->getTools()[0])->toBe($adapter)
        ->and($agent->getTools()[0])->toBeInstanceOf(ToolContract::class);
});
