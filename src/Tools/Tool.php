<?php

declare(strict_types=1);

namespace Pagent\Tools;

use InvalidArgumentException;
use Pagent\Contracts\ToolInterface;
use Pagent\Tool\ToolSchemaSerializer;
use RuntimeException;

abstract class Tool implements ToolInterface
{
    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function execute(array $params): mixed;

    public function parameters(): array
    {
        return [];
    }

    public function getName(): string
    {
        return $this->name();
    }

    public function getDescription(): string
    {
        return $this->description();
    }

    public function getInputSchema(): array
    {
        return $this->parameters();
    }

    /**
     * @deprecated Use ToolSchemaSerializer::anthropic().
     */
    public function toAnthropicSchema(): array
    {
        return ToolSchemaSerializer::anthropic($this);
    }

    /**
     * @deprecated Use ToolSchemaSerializer::openAI().
     */
    public function toOpenAISchema(): array
    {
        return ToolSchemaSerializer::openAI($this);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function requiredString(array $params, string $name): string
    {
        if (! array_key_exists($name, $params)) {
            throw new RuntimeException(ucfirst($name).' parameter is required');
        }

        $value = $params[$name];

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$name} parameter must be a string");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function optionalString(array $params, string $name, string $default): string
    {
        if (! array_key_exists($name, $params)) {
            return $default;
        }

        $value = $params[$name];

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$name} parameter must be a string");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function requiredArray(array $params, string $name): array
    {
        if (! array_key_exists($name, $params)) {
            throw new RuntimeException(ucfirst($name).' parameter is required');
        }

        $value = $params[$name];

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$name} parameter must be an array");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function optionalInt(array $params, string $name, int $default): int
    {
        if (! array_key_exists($name, $params)) {
            return $default;
        }

        $value = $params[$name];

        if (! is_int($value)) {
            throw new InvalidArgumentException("{$name} parameter must be an integer");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function optionalBool(array $params, string $name, bool $default): bool
    {
        if (! array_key_exists($name, $params)) {
            return $default;
        }

        $value = $params[$name];

        if (! is_bool($value)) {
            throw new InvalidArgumentException("{$name} parameter must be a boolean");
        }

        return $value;
    }
}
