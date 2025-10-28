<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    public function execute(array $params): mixed;

    public function toAnthropicSchema(): array;

    public function toOpenAISchema(): array;
}
