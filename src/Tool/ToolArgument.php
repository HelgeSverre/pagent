<?php

declare(strict_types=1);

namespace Pagent\Tool;

final readonly class ToolArgument
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public ?string $description = null,
        public mixed $default = null,
    ) {}

    public function toJsonSchema(): array
    {
        $schema = ['type' => $this->phpTypeToJsonType($this->type)];

        if ($this->description !== null) {
            $schema['description'] = $this->description;
        }

        return $schema;
    }

    private function phpTypeToJsonType(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            'object', 'stdClass' => 'object',
            default => 'string',
        };
    }
}
