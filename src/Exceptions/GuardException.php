<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

final class GuardException extends RuntimeException implements PagentException
{
    public function __construct(
        string $message,
        public readonly string $guardName,
        public readonly string $input,
        public readonly string $output,
        public readonly string $phase = 'legacy',
        /** True only when the Agent itself rejected content through a configured policy. */
        public readonly bool $policyViolation = false,
    ) {
        parent::__construct($message);
    }
}
