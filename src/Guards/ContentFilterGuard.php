<?php

declare(strict_types=1);

namespace Pagent\Guards;

use Pagent\Contracts\Guard;

final class ContentFilterGuard implements Guard
{
    private array $blockedPatterns = [
        '/\b(fuck(ing)?|shit|damn|hell|bastard|bitch|asshole)\b/i',
        '/\b(kill|murder|violence|harm)\s+(myself|yourself|someone|people)\b/i',
        '/\b(hack|exploit|bypass|circumvent)\s+(security|system|protection)\b/i',
    ];

    public function __construct(
        private readonly array $customPatterns = [],
        private readonly bool  $strictMode = false,
    ) {
        if ( ! empty($customPatterns)) {
            $this->blockedPatterns = array_merge($this->blockedPatterns, $customPatterns);
        }
    }

    public function check(string $input, string $output): bool
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return false;
            }
        }

        return true;
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
