<?php

declare(strict_types=1);

namespace Pagent\Events\Events\Agent;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired after receiving a response from the LLM.
 *
 * Allows listeners to:
 * - Log responses
 * - Track token usage
 * - Analyze response quality
 * - Trigger post-processing workflows
 */
final class AfterPromptEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $message,
        public readonly string $response,
        public readonly array $options,
    ) {
        parent::__construct();
    }
}
