<?php

declare(strict_types=1);

namespace Pagent\Events\Events\LLM;

use Pagent\Agent;
use Pagent\Events\Event;

/**
 * Fired before making an HTTP request to the LLM provider.
 *
 * Allows listeners to:
 * - Log raw API requests
 * - Monitor request frequency
 * - Track rate limiting
 * - Inject custom headers/parameters
 */
final class BeforeLLMRequestEvent extends Event
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $requestPayload,
    ) {
        parent::__construct();
    }
}
