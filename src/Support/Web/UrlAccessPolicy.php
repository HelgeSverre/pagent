<?php

declare(strict_types=1);

namespace Pagent\Support\Web;

use Pagent\Exceptions\ConfigurationException;
use Pagent\Exceptions\RuntimeException;

/**
 * Pure allow/disallow policy used by WebFetch before every network hop.
 */
final readonly class UrlAccessPolicy
{
    /** @var list<string> */
    private array $allowList;

    /** @var list<string> */
    private array $disallowList;

    /**
     * @param  array<mixed>  $allowList
     * @param  array<mixed>  $disallowList
     */
    public function __construct(
        array $allowList = [],
        array $disallowList = [],
    ) {
        $normalizedAllowList = [];
        foreach ($allowList as $pattern) {
            if (! is_string($pattern)) {
                throw new ConfigurationException('WebFetch access-list patterns must be strings');
            }

            $normalizedAllowList[] = $pattern;
        }

        $normalizedDisallowList = [];
        foreach ($disallowList as $pattern) {
            if (! is_string($pattern)) {
                throw new ConfigurationException('WebFetch access-list patterns must be strings');
            }

            $normalizedDisallowList[] = $pattern;
        }

        $this->allowList = $normalizedAllowList;
        $this->disallowList = $normalizedDisallowList;
    }

    public function isAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        if ($this->allowList !== []) {
            foreach ($this->allowList as $pattern) {
                if ($this->matches($url, $host, $pattern)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->disallowList as $pattern) {
            if ($this->matches($url, $host, $pattern)) {
                return false;
            }
        }

        return true;
    }

    public function assertAllowed(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('Invalid URL');
        }

        if ($this->allowList !== []) {
            if ($this->isAllowed($url)) {
                return;
            }

            throw new RuntimeException('URL not in allow list');
        }

        foreach ($this->disallowList as $pattern) {
            if ($this->matches($url, $host, $pattern)) {
                throw new RuntimeException('URL is in disallow list');
            }
        }
    }

    private function matches(string $url, string $host, string $pattern): bool
    {
        $url = strtolower($url);
        $host = strtolower($host);
        $pattern = strtolower($pattern);

        if (preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/D', $pattern) === 1) {
            $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ? $host
                : gethostbyname($host);

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && $this->ipv4InRange($ip, $pattern);
        }

        if (str_contains($pattern, '/')) {
            return preg_match('/^https?:\/\/'.$this->wildcardRegex($pattern).'$/iD', $url) === 1;
        }

        if (str_contains($pattern, '*')) {
            return preg_match('/^'.$this->wildcardRegex($pattern).'$/iD', $host) === 1;
        }

        return $host === $pattern;
    }

    private function wildcardRegex(string $pattern): string
    {
        return implode('.*', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            explode('*', $pattern),
        ));
    }

    private function ipv4InRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskBits = (int) $mask;
        if ($ipLong === false || $subnetLong === false || $maskBits < 0 || $maskBits > 32) {
            return false;
        }

        $maskLong = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits));

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
