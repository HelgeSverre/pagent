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
     * Start a lazy HTTP request and return an incremental stream transport.
     *
     * The request begins when status, headers, or chunks are consumed. Calling
     * StreamTransport::resource() or getContent() materializes the full body
     * for backwards compatibility.
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
