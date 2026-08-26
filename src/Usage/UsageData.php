<?php

declare(strict_types=1);

namespace Pagent\Usage;

/**
 * Data Transfer Object for LLM usage tracking.
 *
 * Represents a single usage record with token counts, cost, and metadata.
 */
final class UsageData
{
    public function __construct(
        public readonly string $agentName,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $totalTokens,
        public readonly ?float $cost,
        public readonly ?int $cachedInputTokens = null,
        public readonly ?int $cacheCreationTokens = null,
        public readonly ?string $sessionId = null,
        public readonly float $timestamp = 0.0,
    ) {}

    /**
     * Create UsageData from LLM response data.
     *
     * @param  string  $agentName  Name of the agent
     * @param  string  $provider  Provider name (anthropic, openai, etc.)
     * @param  string  $model  Model name
     * @param  array<string, mixed>  $usage  Usage array from provider response
     * @param  float|null  $cost  Calculated cost in USD, or null when pricing is unknown
     * @param  string|null  $sessionId  Optional session identifier
     */
    public static function fromResponse(
        string $agentName,
        string $provider,
        string $model,
        array $usage,
        ?float $cost,
        ?string $sessionId = null
    ): self {
        $usage = UsageNormalizer::normalize($usage) ?? [];

        /** @var int $inputTokens */
        $inputTokens = is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : 0;
        /** @var int $outputTokens */
        $outputTokens = is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : 0;
        /** @var int $totalTokens */
        $totalTokens = is_numeric($usage['total_tokens'] ?? null) ? (int) $usage['total_tokens'] : 0;
        /** @var int|null $cachedInputTokens */
        $cachedInputTokens = isset($usage['cache_read_input_tokens']) && is_numeric($usage['cache_read_input_tokens'])
            ? (int) $usage['cache_read_input_tokens']
            : null;
        /** @var int|null $cacheCreationTokens */
        $cacheCreationTokens = isset($usage['cache_creation_input_tokens']) && is_numeric($usage['cache_creation_input_tokens'])
            ? (int) $usage['cache_creation_input_tokens']
            : null;

        return new self(
            agentName: $agentName,
            provider: $provider,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cost: $cost,
            cachedInputTokens: $cachedInputTokens,
            cacheCreationTokens: $cacheCreationTokens,
            sessionId: $sessionId,
            timestamp: microtime(true),
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent_name' => $this->agentName,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cost' => $this->cost,
            'cached_input_tokens' => $this->cachedInputTokens,
            'cache_creation_tokens' => $this->cacheCreationTokens,
            'session_id' => $this->sessionId,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * Get formatted cost as string ("unknown" when pricing was unavailable).
     */
    public function getFormattedCost(): string
    {
        if ($this->cost === null) {
            return 'unknown';
        }

        return '$'.number_format($this->cost, 4);
    }

    /**
     * Get cache hit ratio if cache data is available.
     */
    public function getCacheHitRatio(): ?float
    {
        if ($this->cachedInputTokens === null) {
            return null;
        }

        $totalInputWithCache = $this->inputTokens + $this->cachedInputTokens;

        if ($totalInputWithCache === 0) {
            return null;
        }

        return $this->cachedInputTokens / $totalInputWithCache;
    }
}
