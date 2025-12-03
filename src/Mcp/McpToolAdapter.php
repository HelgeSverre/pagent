<?php

declare(strict_types=1);

namespace Pagent\Mcp;

use Pagent\Contracts\Tool;

/**
 * Adapter that wraps an MCP tool as a Pagent Tool.
 *
 * This allows MCP tools to be used seamlessly with Pagent agents.
 */
final class McpToolAdapter implements Tool
{
    /**
     * Create a new MCP tool adapter.
     *
     * @param  McpClient  $client  The MCP client to use
     * @param  string  $name  Tool name
     * @param  string  $description  Tool description
     * @param  array<string, mixed>  $inputSchema  JSON Schema for input parameters
     */
    public function __construct(
        private readonly McpClient $client,
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
    ) {}

    /**
     * Get the tool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the tool description.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get the input schema (JSON Schema format).
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    /**
     * Execute the MCP tool.
     *
     * @param  array<string, mixed>  $arguments  Tool arguments
     * @return mixed Tool result
     */
    public function execute(array $arguments = []): mixed
    {
        $result = $this->client->callTool($this->name, $arguments);

        // MCP tools/call returns a result object with content array
        // Extract the actual content from the response
        if (isset($result['content']) && is_array($result['content'])) {
            // If single content item, return its text/data directly
            if (count($result['content']) === 1) {
                $content = $result['content'][0];

                if (isset($content['text'])) {
                    return $content['text'];
                }

                if (isset($content['data'])) {
                    return $content['data'];
                }
            }

            // Multiple content items, return them as-is
            return $result['content'];
        }

        // Fallback: return the full result if structure is unexpected
        return $result;
    }

    /**
     * Create MCP tool adapters from a list of MCP tool definitions.
     *
     * @param  McpClient  $client  The MCP client
     * @param  array<int, array<string, mixed>>  $mcpTools  Tool definitions from tools/list
     * @return array<int, McpToolAdapter> Array of tool adapters
     */
    public static function fromToolList(McpClient $client, array $mcpTools): array
    {
        $adapters = [];

        foreach ($mcpTools as $tool) {
            /** @var string $name */
            $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
            /** @var string $description */
            $description = is_string($tool['description'] ?? null) ? $tool['description'] : '';
            /** @var array<string, mixed> $inputSchema */
            $inputSchema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : ['type' => 'object', 'properties' => []];

            $adapters[] = new self($client, $name, $description, $inputSchema);
        }

        return $adapters;
    }
}
