<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Exceptions\ConfigurationException;
use Pagent\Exceptions\InvalidArgumentException;
use Pagent\Exceptions\RuntimeException;
use Pagent\Support\Web\UrlAccessPolicy;

final class WebFetch extends Tool
{
    private readonly UrlAccessPolicy $accessPolicy;

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
     * @example Allow localhost for trusted development environments:
     *   new WebFetch(ssrfProtection: false, allowList: ['127.0.0.1', 'localhost', '*.test'])
     * @example Allow only specific API paths:
     *   new WebFetch(allowList: ['api.github.com/repos/*', 'api.example.com/public/*'])
     *
     * Redirects are followed manually (up to 5 hops); allow/disallow lists and
     * SSRF protection are enforced on every hop, including redirects issued by
     * allowlisted hosts.
     */
    public function __construct(
        private int $timeout = 30,
        private int $maxSize = 10 * 1024 * 1024, // 10MB
        private bool $ssrfProtection = true,
        array $allowList = [],
        array $disallowList = [],
    ) {
        if ($timeout < 1) {
            throw new ConfigurationException('WebFetch timeout must be at least one second');
        }
        if ($maxSize < 1) {
            throw new ConfigurationException('WebFetch maxSize must be at least one byte');
        }

        $this->accessPolicy = new UrlAccessPolicy($allowList, $disallowList);
    }

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
        if (! array_key_exists('url', $params)) {
            throw new RuntimeException('URL parameter is required');
        }

        $url = $this->requiredString($params, 'url');
        $headers = $this->headers($params);

        // Redirects are followed manually so that allow/disallow lists and SSRF
        // protection are re-applied to EVERY hop. Automatic following would let
        // an allowed host 302 to an internal address (e.g. cloud metadata IPs).
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            // Parse URL
            $parsed = parse_url($currentUrl);
            if ($parsed === false || ! isset($parsed['host'], $parsed['scheme'])) {
                throw new RuntimeException('Invalid URL');
            }
            $scheme = strtolower($parsed['scheme']);
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException('Only HTTP and HTTPS URLs are supported');
            }
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                throw new RuntimeException('Credentials in URLs are not supported');
            }

            // Check allow/disallow lists (every hop)
            $this->accessPolicy->assertAllowed($currentUrl);

            // Resolve and validate every address, then pin cURL to one of the
            // validated addresses. This closes the DNS-rebinding gap between a
            // safety check and the actual connection.
            $pinnedIp = $this->ssrfProtection ? $this->checkSSRF($parsed['host']) : null;
            [$content, $responseHeaders] = $this->request($currentUrl, $parsed, $headers, $pinnedIp);

            $location = $this->redirectTarget($responseHeaders);
            if ($location !== null) {
                $redirectUrl = $this->resolveRedirect($parsed, $location);
                if (! $this->sameOrigin($currentUrl, $redirectUrl)) {
                    $headers = $this->withoutSensitiveHeaders($headers);
                }
                $currentUrl = $redirectUrl;

                continue;
            }

            return [
                'content' => $content,
                'size' => strlen($content),
                'url' => $currentUrl,
            ];
        }

        throw new RuntimeException("Too many redirects (max: {$maxRedirects})");
    }

    /**
     * @param  array{scheme: string, host: string, port?: int, path?: string, query?: string}  $parsed
     * @param  array<string, string>  $headers
     * @return array{string, list<string>}
     */
    private function request(string $url, array $parsed, array $headers, ?string $pinnedIp): array
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Failed to initialize HTTP client');
        }

        $content = '';
        $responseHeaders = [];
        $tooLarge = false;
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 10),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // A configured environment proxy could perform its own DNS lookup
            // and defeat the address pinned below.
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                }
                $responseHeaders[] = rtrim($line, "\r\n");

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$content, &$tooLarge): int {
                if (strlen($content) + strlen($chunk) > $this->maxSize) {
                    $tooLarge = true;

                    return 0;
                }

                $content .= $chunk;

                return strlen($chunk);
            },
        ];

        if ($pinnedIp !== null) {
            $host = trim($parsed['host'], '[]');
            $port = isset($parsed['port'])
                ? $parsed['port']
                : (strtolower($parsed['scheme']) === 'https' ? 443 : 80);
            $address = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$address}"];
        }

        try {
            if (! curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Failed to configure HTTP client');
            }

            $success = curl_exec($handle);
            if ($tooLarge) {
                throw new RuntimeException('Response too large');
            }
            if ($success === false) {
                throw new RuntimeException('Failed to fetch URL: '.curl_error($handle));
            }
        } finally {
            curl_close($handle);
        }

        return [$content, $responseHeaders];
    }

    /**
     * Return the redirect Location if the response is a 3xx redirect, null otherwise.
     *
     * @param  list<string>  $responseHeaders
     */
    private function redirectTarget(array $responseHeaders): ?string
    {
        if (! isset($responseHeaders[0])
            || preg_match('{^HTTP/\S+\s+(\d{3})}', $responseHeaders[0], $matches) !== 1) {
            return null;
        }

        $status = (int) $matches[1];
        if ($status < 300 || $status >= 400) {
            return null;
        }

        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));

                return $location === '' ? null : $location;
            }
        }

        return null;
    }

    /**
     * Resolve a (possibly relative) redirect Location against the current URL.
     *
     * @param  array{scheme?: string, host?: string, port?: int, path?: string, query?: string}  $parsed
     */
    private function resolveRedirect(array $parsed, string $location): string
    {
        // Absolute URL
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $scheme = $parsed['scheme'] ?? 'http';

        // Protocol-relative URL
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        $base = $scheme.'://'.($parsed['host'] ?? '').(isset($parsed['port']) ? ':'.$parsed['port'] : '');

        // Query-only redirects keep the current path.
        if (str_starts_with($location, '?')) {
            return $base.($parsed['path'] ?? '/').$location;
        }

        [$locationPath, $suffix] = array_pad(preg_split('/(?=[?#])/', $location, 2) ?: [], 2, '');
        $path = str_starts_with($locationPath, '/')
            ? $locationPath
            : rtrim(dirname($parsed['path'] ?? '/'), '/').'/'.$locationPath;

        return $base.$this->normalizePath($path).$suffix;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function headers(array $params): array
    {
        if (! array_key_exists('headers', $params)) {
            return [];
        }

        $headers = $params['headers'];

        if (! is_array($headers)) {
            throw new InvalidArgumentException('headers parameter must be an object with string header values');
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('headers parameter must use non-empty string header names');
            }
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1) {
                throw new InvalidArgumentException("Header '{$name}' has an invalid name");
            }

            if (! is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new InvalidArgumentException("Header '{$name}' value must be a string without newlines");
            }
        }

        /** @var array<string, string> $headers */
        return $headers;
    }

    /** Resolve all A/AAAA records, reject any non-public address, and return one address to pin. */
    private function checkSSRF(string $host): string
    {
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveAddresses($host);

        if ($addresses === []) {
            throw new RuntimeException("SSRF protection: Could not resolve host '{$host}'");
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new RuntimeException('SSRF protection: Cannot access private/local IP addresses');
            }
        }

        return $addresses[0];
    }

    /** @return list<string> */
    private function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function sameOrigin(string $first, string $second): bool
    {
        $a = parse_url($first);
        $b = parse_url($second);
        if ($a === false || $b === false) {
            return false;
        }

        $aScheme = strtolower((string) ($a['scheme'] ?? ''));
        $bScheme = strtolower((string) ($b['scheme'] ?? ''));
        $aPort = $a['port'] ?? ($aScheme === 'https' ? 443 : 80);
        $bPort = $b['port'] ?? ($bScheme === 'https' ? 443 : 80);

        return $aScheme === $bScheme
            && strtolower((string) ($a['host'] ?? '')) === strtolower((string) ($b['host'] ?? ''))
            && $aPort === $bPort;
    }

    /** @param array<string, string> $headers @return array<string, string> */
    private function withoutSensitiveHeaders(array $headers): array
    {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower($name), ['authorization', 'cookie', 'proxy-authorization'], true)) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    private function isPublicAddress(string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        // PHP's reserved-range flag does not cover every special IPv6 block.
        // Reject transition, documentation, benchmarking, multicast, and other
        // non-global ranges explicitly so alternate address forms cannot bypass
        // the simpler private/loopback checks.
        $reserved = [
            '::/128',
            '::1/128',
            '::ffff:0:0/96',
            '64:ff9b::/96',
            '100::/64',
            '2001::/23',
            '2001:db8::/32',
            '3fff::/20',
            'fc00::/7',
            'fe80::/10',
            'ff00::/8',
        ];

        foreach ($reserved as $cidr) {
            if ($this->ipInRange($address, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $ipBytes = inet_pton($ip);
        $subnetBytes = inet_pton($subnet);
        if ($ipBytes === false || $subnetBytes === false || strlen($ipBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $maskBits = (int) $mask;
        $maxBits = strlen($ipBytes) * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($maskBits, 8);
        if (substr($ipBytes, 0, $wholeBytes) !== substr($subnetBytes, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $maskBits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $byteMask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBytes[$wholeBytes]) & $byteMask) === (ord($subnetBytes[$wholeBytes]) & $byteMask);
    }
}
