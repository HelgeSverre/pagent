<?php

declare(strict_types=1);

namespace Pagent\Guards;

use Pagent\Contracts\OutputGuard;
use Pagent\Exceptions\ConfigurationException;

use function preg_match;
use function preg_match_all;
use function preg_replace;
use function strlen;
use function strrev;

/**
 * Heuristic PII detection guard.
 *
 * Uses regex patterns (plus a Luhn check for credit cards) to catch common
 * PII shapes in output. This is best-effort heuristic detection, not
 * compliance-grade PII scanning — it will miss obfuscated or unusual
 * formats and may occasionally false-positive.
 */
final class PIIGuard implements OutputGuard
{
    private array $patterns = [
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'credit_card' => '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',
        'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
        // Requires at least one separator, parenthesis, or leading +country
        // code so bare 10-digit runs (timestamps, ids) don't match.
        'phone' => '/(?:\+\d{1,2}[\s.-]?)?\(\d{3}\)[\s.-]?\d{3}[\s.-]?\d{4}\b|(?:\+\d{1,2}[\s.-]?)?\b\d{3}[\s.-]\d{3}[\s.-]\d{4}\b|\+\d{1,2}[\s.-]?\d{10}\b/',
        'ip_address' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
    ];

    private readonly array $enabledChecks;

    public function __construct(array $enabledChecks = ['ssn', 'credit_card', 'email', 'phone', 'ip_address'])
    {
        foreach ($enabledChecks as $check) {
            if (! is_string($check) || ! isset($this->patterns[$check])) {
                throw new ConfigurationException('Unknown PII check: '.(is_scalar($check) ? (string) $check : get_debug_type($check)));
            }
        }

        $this->enabledChecks = $enabledChecks;
    }

    public function check(string $input, string $output): bool
    {
        return $this->checkOutput($output);
    }

    public function checkOutput(string $output): bool
    {
        foreach ($this->enabledChecks as $check) {
            if (! isset($this->patterns[$check])) {
                continue;
            }

            if ($check === 'credit_card') {
                if ($this->containsLuhnValidCard($output)) {
                    return false;
                }

                continue;
            }

            if ($check === 'ip_address') {
                if ($this->containsValidIpAddress($output)) {
                    return false;
                }

                continue;
            }

            if (preg_match($this->patterns[$check], $output)) {
                return false;
            }
        }

        return true;
    }

    private function containsLuhnValidCard(string $output): bool
    {
        if (! preg_match_all($this->patterns['credit_card'], $output, $matches)) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $digits = (string) preg_replace('/\D/', '', $candidate);

            if (self::passesLuhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private function containsValidIpAddress(string $output): bool
    {
        if (! preg_match_all($this->patterns['ip_address'], $output, $matches)) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $reversed = strrev($digits);

        for ($i = 0; $i < strlen($reversed); $i++) {
            $digit = (int) $reversed[$i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
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
