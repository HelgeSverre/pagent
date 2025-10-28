<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;

final class WebFetch extends Tool
{
    public function __construct(
        private int $timeout = 30,
        private int $maxSize = 10 * 1024 * 1024, // 10MB
        private bool $ssrfProtection = true,
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

        // SSRF Protection
        if ($this->ssrfProtection) {
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
}
