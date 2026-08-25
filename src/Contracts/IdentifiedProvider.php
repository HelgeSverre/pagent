<?php

declare(strict_types=1);

namespace Pagent\Contracts;

use Pagent\ProviderCapabilities;

/**
 * A provider with a stable, provider-defined identity and feature declaration.
 *
 * The identity is intentionally independent of the implementing class name so
 * decorators, adapters, and third-party providers remain first-class citizens.
 */
interface IdentifiedProvider extends Provider
{
    /**
     * Return the stable provider identifier used in events and responses.
     */
    public function providerId(): string;

    /**
     * Return the features this provider adapter can handle.
     */
    public function capabilities(): ProviderCapabilities;
}
