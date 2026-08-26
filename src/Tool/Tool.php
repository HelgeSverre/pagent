<?php

declare(strict_types=1);

namespace Pagent\Tool;

use Closure;
use Pagent\Contracts\ToolInterface as ToolContract;
use ReflectionFunction;
use ReflectionNamedType;

final readonly class Tool implements ToolContract
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

    public function getName(): string
    {
        return $this->name();
    }

    public function description(): string
    {
        return $this->description;
    }

    public function getDescription(): string
    {
        return $this->description();
    }

    public function execute(array $arguments): mixed
    {
        ToolValidator::validate($this, $arguments);

        if ($this->arguments !== []) {
            // Drop hallucinated extra keys so the named-argument spread does
            // not fatal with "Unknown named parameter" mid-conversation.
            $declared = array_flip(array_map(
                static fn (ToolArgument $arg): string => $arg->name,
                $this->arguments,
            ));
            $arguments = array_intersect_key($arguments, $declared);
        }

        return ($this->callable)(...$arguments);
    }

    public function getInputSchema(): array
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
            'type' => 'object',
            'properties' => $properties,
        ];

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @deprecated Use ToolSchemaSerializer::anthropic().
     *
     * @return array<string, mixed>
     */
    public function toAnthropicSchema(): array
    {
        return ToolSchemaSerializer::anthropic($this);
    }

    /**
     * @deprecated Use ToolSchemaSerializer::openAI().
     *
     * @return array<string, mixed>
     */
    public function toOpenAISchema(): array
    {
        return ToolSchemaSerializer::openAI($this);
    }
}
