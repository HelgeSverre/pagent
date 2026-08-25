<?php

declare(strict_types=1);

namespace Pagent\Contracts;

/**
 * A safety policy evaluated before a provider request or tool execution.
 */
interface InputGuard extends Guard
{
    /**
     * Return false to reject user input before it is sent outside the agent.
     */
    public function checkInput(string $input): bool;
}
