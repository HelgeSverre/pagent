<?php

declare(strict_types=1);

namespace Pagent\Contracts;

interface Metric
{
    public function getName(): string;

    public function calculate(string $input, string $output, mixed $expected = null): float;

    public function getDescription(): string;
}
