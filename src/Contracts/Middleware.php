<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface Middleware
{
    public function before(string $message, array $options): array;

    public function after(object $response): object;
}
