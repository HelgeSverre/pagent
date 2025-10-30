<?php

declare(strict_types=1);

namespace Pagent\Http;

interface HttpClientInterface
{
    /**
     * Perform an HTTP request and buffer the full JSON response.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|string|null  $json
     * @param  array<string, mixed>  $options
     */
    public function requestJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): HttpResponse;

    /**
     * Perform an HTTP request and return a buffered stream transport.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|string|null  $json
     * @param  array<string, mixed>  $options
     */
    public function streamJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): StreamTransport;
}
