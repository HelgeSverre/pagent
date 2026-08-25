<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface Guard
{
    /**
     * Legacy, post-response guard entry point.
     *
     * New guards should implement InputGuard or OutputGuard so the agent can run
     * them in the appropriate lifecycle phase. This method remains part of the
     * contract to keep existing custom guards and guard closures working.
     */
    public function check(string $input, string $output): bool;

    public function getName(): string;

    public function getViolationMessage(): string;
}
