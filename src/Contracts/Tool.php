<?php

declare(strict_types=1);

namespace Pagent\Contracts;

/**
 * Interface for agent tools.
 *
 * Tools extend agent capabilities by providing executable functions
 * that can be called during agent interactions.
 */
interface Tool
{
    /**
     * Get the tool name.
     *
     * This should be a unique identifier for the tool.
     */
    public function getName(): string;

    /**
     * Get the tool description.
     *
     * This description helps the LLM understand when and how to use the tool.
     */
    public function getDescription(): string;

    /**
     * Get the input schema for the tool.
     *
     * Returns a JSON Schema definition describing the expected arguments.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param  array<string, mixed>  $arguments  Tool arguments matching the input schema
     * @return mixed Tool execution result
     */
    public function execute(array $arguments): mixed;
}
