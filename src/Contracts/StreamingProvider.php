<?php

declare(strict_types=1);

namespace Pagent\Contracts;

use Pagent\Exceptions\ApiException;
use Pagent\Http\ConnectionException;
use Pagent\Streaming\StreamResponse;

/**
 * A provider that can produce an incremental response for a prompt.
 */
interface StreamingProvider extends Provider
{
    /**
     * Send a prompt and return an incremental stream of chunks.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiException when the provider API rejects the request
     * @throws ConnectionException when the request cannot reach the provider
     */
    public function streamPrompt(string $message, array $options = []): StreamResponse;
}
