<?php

declare(strict_types=1);

namespace Pagent\Tools;

abstract class Tool
{
    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function execute(array $params): mixed;

    public function parameters(): array
    {
        return [];
    }
}
