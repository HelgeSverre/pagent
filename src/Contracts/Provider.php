<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface Provider
{
    public function prompt(string $message, array $options = []): object;
}
