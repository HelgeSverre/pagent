<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Agent;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired before sending a prompt to the LLM.
 *
 * Allows listeners to:
 * - Log prompts for debugging
 * - Modify prompt content
 * - Inject additional context
 * - Cancel requests (via stopPropagation)
 */
final class BeforePromptEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $message,
        public readonly array $options,
    ) {
        parent::__construct();
    }
}
