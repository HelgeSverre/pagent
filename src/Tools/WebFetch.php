<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;

final class WebFetch extends Tool
{
    /**
     * @param  int  $timeout  Request timeout in seconds
     * @param  int  $maxSize  Maximum response size in bytes
     * @param  bool  $ssrfProtection  Enable SSRF protection (blocks private IPs)
     * @param  array<string>  $allowList  Whitelist of allowed patterns. If non-empty, only these are allowed.
     *                                    Supports: exact domains ('example.com'), wildcards ('*.example.com'),
     *                                    URL patterns ('example.com/api/*'), IPs/CIDRs ('192.168.1.0/24')
     * @param  array<string>  $disallowList  Blacklist of blocked patterns. Only used if allowList is empty.
     *                                       Same pattern support as allowList.
     *
     * @example Allow only company domains (whitelist mode):
     *   new WebFetch(allowList: ['*.company.com', 'partner.com'])
     * @example Block specific domains (blacklist mode):
     *   new WebFetch(disallowList: ['competitor.com', 'spam-site.com'])
     * @example Allow localhost for development (bypasses SSRF):
     *   new WebFetch(allowList: ['127.0.0.1', 'localhost', '*.test'])
     * @example Allow only specific API paths:
     *   new WebFetch(allowList: ['api.github.com/repos/*', 'api.example.com/public/*'])
     */
    public function __construct(
        private int $timeout = 30,
        private int $maxSize = 10 * 1024 * 1024, // 10MB
        private bool $ssrfProtection = true,
        private array $allowList = [],
        private array $disallowList = [],
    ) {}

    public function name(): string
    {
        return 'web_fetch';
    }

    public function description(): string
    {
        return 'Fetch content from a URL via HTTP GET request. Returns the response body.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL to fetch',
                ],
                'headers' => [
                    'type' => 'object',
                    'description' => 'Optional HTTP headers',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): mixed
    {
        $url = $params['url'] ?? throw new RuntimeException('URL parameter is required');
        $headers = $params['headers'] ?? [];

        // Parse URL
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'])) {
            throw new RuntimeException('Invalid URL');
        }

        // Check allow/disallow lists
        // Returns true if URL is in allowList (bypasses SSRF), false otherwise
        $bypassSSRF = $this->checkAllowDisallow($url, $parsed['host']);

        // SSRF Protection (Server-Side Request Forgery)
        // Skip if URL is in allowList
        if ($this->ssrfProtection && ! $bypassSSRF) {
            $this->checkSSRF($parsed['host']);
        }

        // Build headers
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "$key: $value";
        }

        // Set up context
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'header' => implode("\r\n", $headerLines),
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);

        // Fetch content - suppress all errors including SSL/network warnings
        set_error_handler(function () {});
        $content = file_get_contents($url, false, $context);
        $error = error_get_last();
        restore_error_handler();

        if ($content === false) {
            throw new RuntimeException('Failed to fetch URL: '.($error['message'] ?? 'Unknown error'));
        }

        // Check size
        if (strlen($content) > $this->maxSize) {
            throw new RuntimeException('Response too large');
        }

        return [
            'content' => $content,
            'size' => strlen($content),
            'url' => $url,
        ];
    }

    /**
     * Check allow/disallow lists and determine if SSRF protection should be bypassed.
     *
     * Logic:
     * - If allowList is non-empty: Only allowed patterns can proceed (whitelist mode)
     * - If allowList is empty: disallowList patterns are blocked (blacklist mode)
     * - Patterns in allowList bypass SSRF protection
     *
     * @param  string  $url  Full URL
     * @param  string  $host  Hostname
     * @return bool True if URL is in allowList (bypasses SSRF), false otherwise
     *
     * @throws RuntimeException If URL is not allowed
     */
    private function checkAllowDisallow(string $url, string $host): bool
    {
        // Whitelist mode: If allowList has entries, ONLY those are allowed
        if (! empty($this->allowList)) {
            foreach ($this->allowList as $pattern) {
                if ($this->matchesPattern($url, $host, $pattern)) {
                    // URL matches allow list - bypass SSRF protection
                    return true;
                }
            }

            // Not in allow list - block
            throw new RuntimeException('URL not in allow list');
        }

        // Blacklist mode: If disallowList has entries, block those patterns
        if (! empty($this->disallowList)) {
            foreach ($this->disallowList as $pattern) {
                if ($this->matchesPattern($url, $host, $pattern)) {
                    throw new RuntimeException('URL is in disallow list');
                }
            }
        }

        // No restrictions or passed checks - apply normal SSRF protection
        return false;
    }

    private function checkSSRF(string $host): void
    {
        // Resolve hostname to IP
        $ip = gethostbyname($host);

        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            // Couldn't resolve
            return;
        }

        // Block private IP ranges
        $blocked = [
            '127.0.0.0/8',      // Loopback
            '10.0.0.0/8',       // Private
            '172.16.0.0/12',    // Private
            '192.168.0.0/16',   // Private
            '169.254.0.0/16',   // Link-local
            '::1/128',          // IPv6 loopback
            'fc00::/7',         // IPv6 private
            'fe80::/10',        // IPv6 link-local
        ];

        foreach ($blocked as $cidr) {
            if ($this->ipInRange($ip, $cidr)) {
                throw new RuntimeException('SSRF protection: Cannot access private/local IP addresses');
            }
        }
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        if (str_contains($cidr, ':')) {
            // IPv6 - skip for now (simplified)
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Check if a URL or host matches a pattern.
     * Supports exact matches, wildcards (*), and URL path patterns.
     * Matching is case-insensitive.
     *
     * @param  string  $url  Full URL to match against
     * @param  string  $host  Hostname extracted from URL
     * @param  string  $pattern  Pattern to match (e.g., '*.example.com', 'example.com/api/*')
     * @return bool True if matches
     */
    private function matchesPattern(string $url, string $host, string $pattern): bool
    {
        // Normalize for case-insensitive comparison
        $url = strtolower($url);
        $host = strtolower($host);
        $pattern = strtolower($pattern);

        // Check if pattern is a CIDR block (for IP matching)
        if (str_contains($pattern, '/') && preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $pattern)) {
            // Try to resolve host to IP and check CIDR
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $this->ipInRange($ip, $pattern);
            }

            return false;
        }

        // Check if pattern contains path (has /)
        if (str_contains($pattern, '/')) {
            // URL pattern match - need to match against full URL
            // Replace * with .* and escape special regex chars except *
            $regex = preg_replace_callback('/[^*]+|\*/', function ($matches) {
                if ($matches[0] === '*') {
                    return '.*';
                }

                return preg_quote($matches[0], '/');
            }, $pattern);

            return preg_match('/^https?:\/\/'.$regex.'$/i', $url) === 1;
        }

        // Domain/host pattern match
        if (str_contains($pattern, '*')) {
            // Wildcard pattern - convert to regex
            // Split by * and quote each part, then join with .*
            $regex = preg_replace_callback('/[^*]+|\*/', function ($matches) {
                if ($matches[0] === '*') {
                    return '.*';
                }

                return preg_quote($matches[0], '/');
            }, $pattern);

            return preg_match('/^'.$regex.'$/i', $host) === 1;
        }

        // Exact match
        return $host === $pattern;
    }
}
