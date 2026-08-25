<?php

declare(strict_types=1);

namespace Pagent\Guards;

use Pagent\Contracts\OutputGuard;

use function array_merge;
use function preg_match;

final class ContentFilterGuard implements OutputGuard
{
    private array $blockedPatterns = [
        '/\b(fuck(ing)?|shit|damn|hell|bastard|bitch|asshole)\b/i',
        '/\b(kill|murder|violence|harm)\s+(myself|yourself|someone|people)\b/i',
        '/\b(hack|exploit|bypass|circumvent)\s+(security|system|protection)\b/i',
    ];

    public function __construct(
        private readonly array $customPatterns = [],
        private readonly bool $strictMode = false,
    ) {
        if (! empty($customPatterns)) {
            $this->blockedPatterns = array_merge($this->blockedPatterns, $customPatterns);
        }
    }

    public function check(string $input, string $output): bool
    {
        return $this->checkOutput($output);
    }

    public function checkOutput(string $output): bool
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return false;
            }
        }

        return true;
    }

    public function supportsIncrementalInspection(): bool
    {
        // Filter terms can cross chunk boundaries, so policy must run against
        // the quarantined complete response before anything is delivered.
        return false;
    }

    public function getName(): string
    {
        return 'content_filter';
    }

    public function getViolationMessage(): string
    {
        return 'Response contains inappropriate or harmful content and was blocked.';
    }
}
