<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface Guard
{
    public function check(string $input, string $output): bool;

    public function getName(): string;

    public function getViolationMessage(): string;
}
