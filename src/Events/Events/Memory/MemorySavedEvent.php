<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Memory;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired after saving data to memory storage.
 *
 * Allows listeners to:
 * - Log successful saves
 * - Track storage growth
 * - Sync to external systems
 * - Trigger cleanup/archival
 */
final class MemorySavedEvent extends Event
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
