<?php

declare(strict_types=1);

namespace Pagent\Guards;

use Pagent\Contracts\InputGuard;
use Pagent\Exceptions\ConfigurationException;

use function array_merge;
use function preg_match;

/**
 * Keyword/regex heuristic filter for prompt injection.
 *
 * Matches a small list of phrasings commonly seen in casual injection
 * attempts ("ignore previous instructions", "[SYSTEM]", ...). This is a
 * lightweight heuristic only — it is trivially bypassed by rephrasing and
 * is NOT a defense against a determined attacker. Treat it as a first-line
 * nuisance filter, not a security boundary.
 */
final class PromptInjectionGuard implements InputGuard
{
    private const DEFAULT_PATTERNS = [
        '/ignore\s+(previous|above|all)/i',
        '/forget\s+(everything|all|previous)/i',
        '/you\s+are\s+now/i',
        '/^\s*system:/im',
        '/\[SYSTEM\]/i',
        '/new\s+instructions:/i',
        '/disregard\s+(previous|above)/i',
    ];

    private array $suspiciousPatterns;

    public function __construct(
        ?array $patterns = null,
        array $additionalPatterns = [],
    ) {
        $this->suspiciousPatterns = array_merge($patterns ?? self::DEFAULT_PATTERNS, $additionalPatterns);

        foreach ($this->suspiciousPatterns as $pattern) {
            if (! self::isValidPattern($pattern)) {
                throw new ConfigurationException('Prompt injection patterns must be valid, non-empty regular expressions');
            }
        }
    }

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

    private static function isValidPattern(mixed $pattern): bool
    {
        if (! is_string($pattern) || $pattern === '') {
            return false;
        }

        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
