<?php

declare(strict_types=1);

namespace Pagent\Guards;

use Closure;
use Pagent\Contracts\Guard;

/**
 * Compatibility adapter for the original two-argument guard callback API.
 *
 * Legacy guards are evaluated after a provider response, because their phase
 * cannot be inferred safely from a callback that receives both input and
 * output. Use InputGuard or OutputGuard for phase-aware policies.
 */
final class LegacyGuardAdapter implements Guard
{
    /**
     * @param  Closure(string, string): bool  $check
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $check,
        private readonly ?string $violationMessage = null,
    ) {}

    public function check(string $input, string $output): bool
    {
        $check = $this->check;

        return (bool) $check($input, $output);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getViolationMessage(): string
    {
        return $this->violationMessage ?? sprintf('Guard %s failed', $this->name);
    }
}
