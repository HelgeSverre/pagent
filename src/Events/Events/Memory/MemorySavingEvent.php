<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Memory;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired before saving data to memory storage.
 *
 * Allows listeners to:
 * - Log memory writes
 * - Validate data before saving
 * - Compress or encrypt data
 * - Cancel saves (via stopPropagation)
 */
final class MemorySavingEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $key,
        public readonly mixed $value,
        public readonly ?string $namespace = null,
    ) {
        parent::__construct();
    }
}
