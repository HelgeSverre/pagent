<?php

declare(strict_types=1);

namespace Pagent\Tool;

use Closure;
use Pagent\Contracts\ToolInterface;
use ReflectionFunction;
use ReflectionNamedType;

final readonly class Tool implements ToolInterface
{
    /**
     * @param  ToolArgument[]  $arguments
     */
    public function __construct(
        public string $name,
        public string $description,
        public Closure $callable,
        public array $arguments = [],
    ) {}

    public static function fromClosure(string $name, string $description, Closure $closure): self
    {
        $reflector = new ReflectionFunction($closure);
        $arguments = [];

        foreach ($reflector->getParameters() as $param) {
            $type = $param->getType();
            $typeName = 'string';

            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();
            }

            $arguments[] = new ToolArgument(
                name: $param->getName(),
                type: $typeName,
                nullable: $param->allowsNull() || $param->isDefaultValueAvailable(),
                description: null,
                default: $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            );
        }

        return new self($name, $description, $closure, $arguments);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function execute(array $arguments): mixed
    {
        ToolValidator::validate($this, $arguments);

        return ($this->callable)(...$arguments);
    }

    public function toAnthropicSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->arguments as $arg) {
            $properties[$arg->name] = $arg->toJsonSchema();

            if (! $arg->nullable && $arg->default === null) {
                $required[] = $arg->name;
            }
        }

        $schema = [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties,
            ],
        ];

        if (! empty($required)) {
            $schema['input_schema']['required'] = $required;
        }

        return $schema;
    }

    public function toOpenAISchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->arguments as $arg) {
            $properties[$arg->name] = $arg->toJsonSchema();

            if (! $arg->nullable && $arg->default === null) {
                $required[] = $arg->name;
            }
        }

        $schema = [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                ],
            ],
        ];

        if (! empty($required)) {
            $schema['function']['parameters']['required'] = $required;
        }

        return $schema;
    }
}
