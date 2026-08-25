<?php

declare(strict_types=1);

namespace Pagent\Guards;

use Pagent\Contracts\OutputGuard;

use function preg_match;

final class PIIGuard implements OutputGuard
{
    private array $patterns = [
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'credit_card' => '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',
        'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
        'phone' => '/\b(\+\d{1,2}\s?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/',
        'ip_address' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
    ];

    public function __construct(
        private readonly array $enabledChecks = ['ssn', 'credit_card', 'email', 'phone'],
    ) {}

    public function check(string $input, string $output): bool
    {
        return $this->checkOutput($output);
    }

    public function checkOutput(string $output): bool
    {
        foreach ($this->enabledChecks as $check) {
            if (isset($this->patterns[$check]) && preg_match($this->patterns[$check], $output)) {
                return false;
            }
        }

        return true;
    }

    public function supportsIncrementalInspection(): bool
    {
        // A PII token can span transport chunks. Releasing a prefix before the
        // complete pattern is known would leak part of the protected value.
        return false;
    }

    public function getName(): string
    {
        return 'pii_guard';
    }

    public function getViolationMessage(): string
    {
        return 'Response contains personally identifiable information (PII) and was blocked.';
    }
}
