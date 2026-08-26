<?php

declare(strict_types=1);

namespace Pagent\Usage;

/**
 * Calculate LLM usage costs based on provider pricing models.
 *
 * The built-in pricing table is a point-in-time convenience (January 2025
 * pricing) and WILL drift out of date. Override or extend it via the
 * `pricing` config (constructor argument / UsageTracker `pricing` key) for
 * anything you rely on. Unknown provider/model combinations return null so
 * "unknown" is distinguishable from "free" ($0.00).
 *
 * Anthropic cache creation tokens (`cache_creation_input_tokens`) are priced
 * at 1.25x the input rate.
 */
final class PricingCalculator
{
    /**
     * Provider pricing models (per million tokens).
     *
     * Format: [provider][model] = [input_cost_per_mtok, output_cost_per_mtok, cached_input_cost_per_mtok]
     *
     * @var array<string, array<string, array{input: float, output: float, cached_input?: float}>>
     */
    private array $pricing;

    /**
     * @param  array<string, array<string, array{input: float, output: float, cached_input?: float}>>  $customPricing  Custom pricing models
     */
    public function __construct(array $customPricing = [])
    {
        $this->pricing = array_merge($this->getDefaultPricing(), $customPricing);
    }

    /**
     * Calculate cost for LLM usage.
     *
     * @param  string  $provider  Provider name (anthropic, openai, etc.)
     * @param  string  $model  Model name
     * @param  array<string, mixed>  $usage  Usage data from provider response
     * @return float|null Cost in USD, or null when pricing for the provider/model is unknown
     */
    public function calculate(string $provider, string $model, array $usage): ?float
    {
        $provider = strtolower($provider);
        $model = $this->normalizeModelName($model);
        $usage = UsageNormalizer::normalize($usage) ?? [];

        // Get pricing for this provider/model
        $modelPricing = $this->getPricingForModel($provider, $model);

        if ($modelPricing === null) {
            // Unknown model - cost cannot be determined
            return null;
        }

        /** @var int $inputTokens */
        $inputTokens = is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : 0;
        /** @var int $outputTokens */
        $outputTokens = is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : 0;
        /** @var int $cachedInputTokens */
        $cachedInputTokens = is_numeric($usage['cache_read_input_tokens'] ?? null) ? (int) $usage['cache_read_input_tokens'] : 0;
        /** @var int $cacheCreationTokens */
        $cacheCreationTokens = is_numeric($usage['cache_creation_input_tokens'] ?? null) ? (int) $usage['cache_creation_input_tokens'] : 0;

        // Calculate base cost (input + output)
        $inputCost = ($inputTokens / 1_000_000) * $modelPricing['input'];
        $outputCost = ($outputTokens / 1_000_000) * $modelPricing['output'];

        // Calculate cached input cost (if available and cheaper)
        $cachedCost = 0.0;
        if ($cachedInputTokens > 0 && isset($modelPricing['cached_input'])) {
            $cachedCost = ($cachedInputTokens / 1_000_000) * $modelPricing['cached_input'];
        }

        // Cache creation (cache writes) is billed at 1.25x the input rate
        $cacheCreationCost = ($cacheCreationTokens / 1_000_000) * $modelPricing['input'] * 1.25;

        return $inputCost + $outputCost + $cachedCost + $cacheCreationCost;
    }

    /**
     * Get pricing for a specific model.
     *
     * @return array{input: float, output: float, cached_input?: float}|null
     */
    private function getPricingForModel(string $provider, string $model): ?array
    {
        // Exact match
        if (isset($this->pricing[$provider][$model])) {
            return $this->pricing[$provider][$model];
        }

        // Fallback to default model for provider
        if (isset($this->pricing[$provider]['default'])) {
            return $this->pricing[$provider]['default'];
        }

        return null;
    }

    /**
     * Normalize model names to canonical form.
     */
    private function normalizeModelName(string $model): string
    {
        // Remove version suffixes and normalize
        $model = strtolower($model);

        // Map common variations to canonical names
        $mappings = [
            'claude-3-5-sonnet' => 'claude-3-5-sonnet-20241022',
            'claude-3-5-haiku' => 'claude-3-5-haiku-20241022',
            'claude-3-opus' => 'claude-3-opus-20240229',
            'claude-3-sonnet' => 'claude-3-sonnet-20240229',
            'claude-3-haiku' => 'claude-3-haiku-20240307',
            'gpt-4o' => 'gpt-4o-2024-11-20',
            'gpt-4o-mini' => 'gpt-4o-mini-2024-07-18',
            'gpt-4-turbo' => 'gpt-4-turbo-2024-04-09',
            'o1' => 'o1-2024-12-17',
            'o1-mini' => 'o1-mini-2024-09-12',
        ];

        return $mappings[$model] ?? $model;
    }

    /**
     * Get default pricing models (January 2025 pricing).
     *
     * @return array<string, array<string, array{input: float, output: float, cached_input?: float}>>
     */
    private function getDefaultPricing(): array
    {
        return [
            'anthropic' => [
                'claude-3-5-sonnet-20241022' => [
                    'input' => 3.00,  // $3 per MTok
                    'output' => 15.00, // $15 per MTok
                    'cached_input' => 0.30, // $0.30 per MTok (prompt caching)
                ],
                'claude-3-5-haiku-20241022' => [
                    'input' => 1.00,  // $1 per MTok
                    'output' => 5.00, // $5 per MTok
                    'cached_input' => 0.10, // $0.10 per MTok
                ],
                'claude-3-opus-20240229' => [
                    'input' => 15.00,  // $15 per MTok
                    'output' => 75.00, // $75 per MTok
                    'cached_input' => 1.50, // $1.50 per MTok
                ],
                'claude-3-sonnet-20240229' => [
                    'input' => 3.00,  // $3 per MTok
                    'output' => 15.00, // $15 per MTok
                    'cached_input' => 0.30, // $0.30 per MTok
                ],
                'claude-3-haiku-20240307' => [
                    'input' => 0.25,  // $0.25 per MTok
                    'output' => 1.25, // $1.25 per MTok
                    'cached_input' => 0.03, // $0.03 per MTok
                ],
            ],
            'openai' => [
                'gpt-4o-2024-11-20' => [
                    'input' => 2.50,  // $2.50 per MTok
                    'output' => 10.00, // $10 per MTok
                ],
                'gpt-4o-mini-2024-07-18' => [
                    'input' => 0.15,  // $0.15 per MTok
                    'output' => 0.60, // $0.60 per MTok
                ],
                'gpt-4-turbo-2024-04-09' => [
                    'input' => 10.00,  // $10 per MTok
                    'output' => 30.00, // $30 per MTok
                ],
                'o1-2024-12-17' => [
                    'input' => 15.00,  // $15 per MTok
                    'output' => 60.00, // $60 per MTok
                ],
                'o1-mini-2024-09-12' => [
                    'input' => 3.00,  // $3 per MTok
                    'output' => 12.00, // $12 per MTok
                ],
            ],
            // ollama/mock keep a 'default' entry: local/test models are
            // genuinely free regardless of model name, unlike paid providers
            // where a default would be a confidently wrong guess.
            'ollama' => [
                'default' => [
                    'input' => 0.0,  // Free for local models
                    'output' => 0.0,
                ],
            ],
            'mock' => [
                'default' => [
                    'input' => 0.0,  // Free for testing
                    'output' => 0.0,
                ],
            ],
        ];
    }
}
