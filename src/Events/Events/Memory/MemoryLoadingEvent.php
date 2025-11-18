<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Memory;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired before loading data from memory storage.
 *
 * Allows listeners to:
 * - Log memory access patterns
 * - Track cache hits/misses
 * - Monitor memory performance
 * - Inject default values
 */
final class MemoryLoadingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $key,
        public readonly ?string $namespace = null,
    ) {
        parent::__construct();
    }
}
