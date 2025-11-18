<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Memory;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired after loading data from memory storage.
 *
 * Allows listeners to:
 * - Log successful loads
 * - Track memory usage
 * - Process loaded data
 * - Update access timestamps
 */
final class MemoryLoadedEvent extends Event
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
