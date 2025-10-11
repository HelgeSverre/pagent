<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

use RuntimeException;

final class GuardException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $guardName,
        public readonly string $input,
        public readonly string $output,
    ) {
        parent::__construct($message);
    }
}
