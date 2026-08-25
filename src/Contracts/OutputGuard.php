<?php

declare(strict_types=1);

namespace Pagent\Contracts;

/**
 * A safety policy evaluated against provider output.
 */
interface OutputGuard extends Guard
{
    /**
     * Return false to reject provider output.
     */
    public function checkOutput(string $output): bool;

    /**
     * Whether this guard can safely inspect accumulated streamed output before
     * the response is complete. Agents must defer guards returning false until
     * the completed response is available.
     */
    public function supportsIncrementalInspection(): bool;
}
