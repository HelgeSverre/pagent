#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Simple Test MCP Server
 *
 * A minimal MCP server implementation for integration testing.
 * Implements the MCP protocol via stdio (JSON-RPC 2.0).
 */

// Read JSON-RPC requests from stdin
$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');
$stderr = fopen('php://stderr', 'w');

// Make stdout unbuffered
stream_set_blocking($stdout, false);

$initialized = false;

while (! feof($stdin)) {
    $line = fgets($stdin);

    if ($line === false || trim($line) === '') {
        continue;
    }

    try {
        $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        if (! isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            continue;
        }

        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        $response = match ($method) {
            'initialize' => handleInitialize($id, $params),
            'tools/list' => handleToolsList($id),
            'tools/call' => handleToolCall($id, $params),
            'resources/list' => handleResourcesList($id),
            'resources/read' => handleResourceRead($id, $params),
            'prompts/list' => handlePromptsList($id),
            'prompts/get' => handlePromptGet($id, $params),
            'notifications/initialized' => null, // Notification, no response
            default => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32601,
                    'message' => "Method not found: {$method}",
                ],
            ],
        };

        if ($response !== null) {
            fwrite($stdout, json_encode($response, JSON_THROW_ON_ERROR)."\n");
            fflush($stdout);
        }

        if ($method === 'initialize') {
            $initialized = true;
        }
    } catch (JsonException $e) {
        // Invalid JSON, skip
        fwrite($stderr, "JSON error: {$e->getMessage()}\n");
    } catch (Throwable $e) {
        fwrite($stderr, "Error: {$e->getMessage()}\n");
    }
}

fclose($stdin);
fclose($stdout);
fclose($stderr);

function handleInitialize(int $id, array $params): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [],
            ],
            'serverInfo' => [
                'name' => 'test-mcp-server',
                'version' => '1.0.0',
            ],
        ],
    ];
}

function handleToolsList(int $id): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'tools' => [
                [
                    'name' => 'echo',
                    'description' => 'Echoes back the input text',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => [
                                'type' => 'string',
                                'description' => 'Text to echo back',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'add',
                    'description' => 'Adds two numbers',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'a' => [
                                'type' => 'number',
                                'description' => 'First number',
                            ],
                            'b' => [
                                'type' => 'number',
                                'description' => 'Second number',
                            ],
                        ],
                        'required' => ['a', 'b'],
                    ],
                ],
                [
                    'name' => 'get_time',
                    'description' => 'Gets the current server time',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ],
        ],
    ];
}

function handleToolCall(int $id, array $params): array
{
    $toolName = $params['name'] ?? null;
    $arguments = $params['arguments'] ?? [];

    if ($toolName === null) {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32602,
                'message' => 'Missing tool name',
            ],
        ];
    }

    $result = match ($toolName) {
        'echo' => [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $arguments['text'] ?? 'No text provided',
                ],
            ],
        ],
        'add' => [
            'content' => [
                [
                    'type' => 'text',
                    'text' => (string) (($arguments['a'] ?? 0) + ($arguments['b'] ?? 0)),
                ],
            ],
        ],
        'get_time' => [
            'content' => [
                [
                    'type' => 'text',
                    'text' => date('Y-m-d H:i:s'),
                ],
            ],
        ],
        default => null,
    };

    if ($result === null) {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32601,
                'message' => "Tool not found: {$toolName}",
            ],
        ];
    }

    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ];
}

function handleResourcesList(int $id): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'resources' => [
                [
                    'uri' => 'file:///test.txt',
                    'name' => 'Test File',
                    'description' => 'A test file resource',
                    'mimeType' => 'text/plain',
                ],
                [
                    'uri' => 'file:///data.json',
                    'name' => 'Data File',
                    'description' => 'A JSON data file',
                    'mimeType' => 'application/json',
                ],
            ],
        ],
    ];
}

function handleResourceRead(int $id, array $params): array
{
    $uri = $params['uri'] ?? null;

    if ($uri === null) {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32602,
                'message' => 'Missing resource URI',
            ],
        ];
    }

    $content = match ($uri) {
        'file:///test.txt' => [
            'contents' => [
                [
                    'uri' => 'file:///test.txt',
                    'mimeType' => 'text/plain',
                    'text' => 'This is test content',
                ],
            ],
        ],
        'file:///data.json' => [
            'contents' => [
                [
                    'uri' => 'file:///data.json',
                    'mimeType' => 'application/json',
                    'text' => '{"key": "value"}',
                ],
            ],
        ],
        default => null,
    };

    if ($content === null) {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32602,
                'message' => "Resource not found: {$uri}",
            ],
        ];
    }

    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $content,
    ];
}

function handlePromptsList(int $id): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'prompts' => [
                [
                    'name' => 'greeting',
                    'description' => 'Generate a greeting message',
                    'arguments' => [
                        [
                            'name' => 'name',
                            'description' => 'Name to greet',
                            'required' => true,
                        ],
                    ],
                ],
                [
                    'name' => 'summary',
                    'description' => 'Summarize content',
                    'arguments' => [
                        [
                            'name' => 'text',
                            'description' => 'Text to summarize',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function handlePromptGet(int $id, array $params): array
{
    $name = $params['name'] ?? null;
    $arguments = $params['arguments'] ?? [];

    if ($name === null) {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32602,
                'message' => 'Missing prompt name',
            ],
        ];
    }

    $result = match ($name) {
        'greeting' => [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => 'Say hello to '.($arguments['name'] ?? 'World'),
                    ],
                ],
            ],
        ],
        'summary' => [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => 'Summarize this: '.($arguments['text'] ?? ''),
                    ],
                ],
            ],
        ],
        default => null,
    };

    if ($result === null) {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32602,
                'message' => "Prompt not found: {$name}",
            ],
        ];
    }

    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ];
}
