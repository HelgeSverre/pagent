<?php

declare(strict_types=1);

use Pagent\Usage\PricingCalculator;

test('calculates cost for Anthropic claude-3-5-sonnet', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,  // 1M tokens
        'output_tokens' => 500_000,   // 0.5M tokens
    ];

    $cost = $calculator->calculate('anthropic', 'claude-3-5-sonnet-20241022', $usage);

    // Expected: (1M / 1M) * $3 + (0.5M / 1M) * $15 = $3 + $7.5 = $10.5
    expect($cost)->toBe(10.5);
});

test('calculates cost for Anthropic with cached tokens', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 100_000,          // 0.1M tokens at $3/MTok
        'output_tokens' => 50_000,          // 0.05M tokens at $15/MTok
        'cache_read_input_tokens' => 500_000,  // 0.5M tokens at $0.30/MTok
    ];

    $cost = $calculator->calculate('anthropic', 'claude-3-5-sonnet-20241022', $usage);

    // Expected: (0.1M/1M)*$3 + (0.05M/1M)*$15 + (0.5M/1M)*$0.30
    //         = $0.30 + $0.75 + $0.15 = $1.20
    expect($cost)->toBe(1.2);
});

test('calculates cost for OpenAI gpt-4o', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,  // 1M tokens
        'output_tokens' => 500_000,   // 0.5M tokens
    ];

    $cost = $calculator->calculate('openai', 'gpt-4o-2024-11-20', $usage);

    // Expected: (1M/1M)*$2.50 + (0.5M/1M)*$10 = $2.50 + $5 = $7.50
    expect($cost)->toBe(7.5);
});

test('normalizes OpenAI and Ollama native token field names', function () {
    $calculator = new PricingCalculator;

    $cost = $calculator->calculate('openai', 'gpt-4o-2024-11-20', [
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 500_000,
        'total_tokens' => 1_500_000,
    ]);

    expect($cost)->toBe(7.5);
});

test('calculates cost for OpenAI gpt-4o-mini', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    $cost = $calculator->calculate('openai', 'gpt-4o-mini-2024-07-18', $usage);

    // Expected: (1M/1M)*$0.15 + (1M/1M)*$0.60 = $0.15 + $0.60 = $0.75
    expect($cost)->toBe(0.75);
});

test('normalizes model names', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    // Short name should map to full version
    $cost = $calculator->calculate('anthropic', 'claude-3-5-sonnet', $usage);

    // Should use claude-3-5-sonnet-20241022 pricing
    expect($cost)->toBe(18.0); // $3 + $15 = $18
});

test('returns null cost for unknown provider', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    $cost = $calculator->calculate('unknown-provider', 'unknown-model', $usage);

    expect($cost)->toBeNull();
});

test('returns null cost for unknown model on a known paid provider', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    // No silent $3/$15 default guess for unknown Anthropic models
    expect($calculator->calculate('anthropic', 'claude-99-fictional', $usage))->toBeNull()
        ->and($calculator->calculate('openai', 'gpt-99-fictional', $usage))->toBeNull();
});

test('prices anthropic cache creation tokens at 1.25x input rate', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_creation_input_tokens' => 1_000_000, // 1M at $3 * 1.25 = $3.75
    ];

    $cost = $calculator->calculate('anthropic', 'claude-3-5-sonnet-20241022', $usage);

    expect($cost)->toBe(3.75);
});

test('returns zero cost for Ollama models', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    $cost = $calculator->calculate('ollama', 'llama2', $usage);

    expect($cost)->toBe(0.0); // Ollama is free for local models
});

test('returns zero cost for mock provider', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    $cost = $calculator->calculate('mock', 'test-model', $usage);

    expect($cost)->toBe(0.0);
});

test('handles missing usage fields', function () {
    $calculator = new PricingCalculator;

    $usage = []; // Empty usage

    $cost = $calculator->calculate('anthropic', 'claude-3-5-sonnet-20241022', $usage);

    expect($cost)->toBe(0.0);
});

test('accepts custom pricing', function () {
    $customPricing = [
        'custom-provider' => [
            'custom-model' => [
                'input' => 5.0,
                'output' => 20.0,
            ],
        ],
    ];

    $calculator = new PricingCalculator($customPricing);

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    $cost = $calculator->calculate('custom-provider', 'custom-model', $usage);

    // Expected: (1M/1M)*$5 + (1M/1M)*$20 = $25
    expect($cost)->toBe(25.0);
});

test('custom pricing overrides default pricing', function () {
    $customPricing = [
        'anthropic' => [
            'claude-3-5-sonnet-20241022' => [
                'input' => 1.0,  // Override default $3
                'output' => 5.0,  // Override default $15
            ],
        ],
    ];

    $calculator = new PricingCalculator($customPricing);

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    $cost = $calculator->calculate('anthropic', 'claude-3-5-sonnet-20241022', $usage);

    // Should use custom pricing
    expect($cost)->toBe(6.0); // $1 + $5 = $6
});

test('provider names are case-insensitive', function () {
    $calculator = new PricingCalculator;

    $usage = [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ];

    $costLower = $calculator->calculate('anthropic', 'claude-3-5-sonnet-20241022', $usage);
    $costUpper = $calculator->calculate('ANTHROPIC', 'claude-3-5-sonnet-20241022', $usage);
    $costMixed = $calculator->calculate('Anthropic', 'claude-3-5-sonnet-20241022', $usage);

    expect($costLower)->toBe($costUpper)
        ->and($costLower)->toBe($costMixed);
});
