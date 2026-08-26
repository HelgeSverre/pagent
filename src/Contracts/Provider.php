<?php

declare(strict_types=1);

namespace Pagent\Contracts;

use Pagent\Exceptions\ApiException;
use Pagent\Exceptions\ConfigurationException;
use Pagent\Http\ConnectionException;
use Pagent\Response;

interface Provider
{
    /**
     * Send a prompt and return the completed response.
     *
     * The return type stays `object` for backwards compatibility; bundled
     * implementations return {@see Response} and may narrow their
     * declared return type to it.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiException when the provider API returns an error response
     * @throws ConfigurationException when the provider is misconfigured
     * @throws ConnectionException when the request cannot reach the provider
     */
    public function prompt(string $message, array $options = []): object;
}
