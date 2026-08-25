<?php

declare(strict_types=1);

namespace Pagent\Guards;

use Pagent\Contracts\InputGuard;

use function preg_match;

final class PromptInjectionGuard implements InputGuard
{
    private array $suspiciousPatterns = [
        '/ignore\s+(previous|above|all)/i',
        '/forget\s+(everything|all|previous)/i',
        '/you\s+are\s+now/i',
        '/system:/i',
        '/\[SYSTEM\]/i',
        '/new\s+instructions:/i',
        '/disregard\s+(previous|above)/i',
    ];

    public function check(string $input, string $output): bool
    {
        return $this->checkInput($input);
    }

    public function checkInput(string $input): bool
    {
        foreach ($this->suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return false;
            }
        }

        return true;
    }

    public function getName(): string
    {
        return 'prompt_injection';
    }

    public function getViolationMessage(): string
    {
        return 'Potential prompt injection detected in user input.';
    }
}
