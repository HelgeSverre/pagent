<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Contracts\ToolInterface;

abstract class Tool implements ToolInterface
{
    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function execute(array $params): mixed;

    public function parameters(): array
    {
        return [];
    }

    public function toAnthropicSchema(): array
    {
        $params = $this->parameters();

        $schema = [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_schema' => $params,
        ];

        return $schema;
    }

    public function toOpenAISchema(): array
    {
        $params = $this->parameters();

        $schema = [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $params,
            ],
        ];

        return $schema;
    }
}
