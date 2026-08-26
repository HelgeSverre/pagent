<?php

declare(strict_types=1);

namespace Pagent;

use Pagent\Usage\UsageNormalizer;

/**
 * Typed provider response.
 *
 * Property names deliberately keep the historical stdClass field names
 * (snake_case) so existing duck-typed callers, `(array)` casts and
 * `property_exists()` checks keep working unchanged.
 */
final class Response
{
    /**
     * @param  array<string, mixed>|null  $usage
     * @param  array<int, array<string, mixed>>  $tool_calls
     * @param  array<int, mixed>|null  $raw_content  Provider-native content blocks (Anthropic)
     * @param  array<string, mixed>  $raw  Full decoded provider payload for provider-specific extras
     */
    public function __construct(
        public string $content = '',
        public string $model = 'unknown',
        public int $tokens = 0,
        public string $provider = 'unknown',
        public ?array $usage = null,
        public ?string $finish_reason = null,
        public ?string $stop_reason = null,
        public array $tool_calls = [],
        public ?array $raw_content = null,
        public ?string $guard_triggered = null,
        public array $raw = [],
    ) {
        $this->usage = UsageNormalizer::normalize($usage);
    }

    /**
     * Unified finish reason regardless of provider wire format.
     */
    public function finishReason(): ?string
    {
        return $this->stop_reason ?? $this->finish_reason;
    }

    public function hasToolCalls(): bool
    {
        return $this->tool_calls !== [];
    }
}
