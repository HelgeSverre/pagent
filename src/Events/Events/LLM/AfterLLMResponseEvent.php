<?php

declare(strict_types=1);

namespace Pagent\Events\Events\LLM;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired after receiving an HTTP response from the LLM provider.
 *
 * Allows listeners to:
 * - Log raw API responses
 * - Track token usage and costs
 * - Monitor response latency
 * - Detect rate limit headers
 */
final class AfterLLMResponseEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $responseData,
        public readonly float $durationMs,
    ) {
        parent::__construct();
    }
}
