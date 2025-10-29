<?php

declare(strict_types=1);

use Tests\Integration\Observability\ObservabilityTestHelper;

/**
 * Phoenix (Arize) integration tests
 *
 * Tests LLM observability with Phoenix
 */
test('can send trace data to phoenix', function () {
    $config = ObservabilityTestHelper::getTestConfig('phoenix');
    $tracesEndpoint = $config['base_url'].'/v1/traces';

    // Sample trace data in OpenInference format
    $traceData = [
        'resourceSpans' => [
            [
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => 'pagent-test']],
                    ],
                ],
                'scopeSpans' => [
                    [
                        'scope' => ['name' => 'pagent-integration'],
                        'spans' => [
                            [
                                'traceId' => bin2hex(random_bytes(16)),
                                'spanId' => bin2hex(random_bytes(8)),
                                'name' => 'llm.completion',
                                'kind' => 1,
                                'startTimeUnixNano' => (string) (time() * 1000000000),
                                'endTimeUnixNano' => (string) ((time() + 2) * 1000000000),
                                'attributes' => [
                                    ['key' => 'llm.model_name', 'value' => ['stringValue' => 'claude-3-5-sonnet']],
                                    ['key' => 'llm.input_messages', 'value' => ['stringValue' => '[{"role":"user","content":"test"}]']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $headers = ['Content-Type' => 'application/json'];

    // Add API key if configured
    if (! empty($config['api_key'])) {
        $headers['api-key'] = $config['api_key'];
    }

    $response = ObservabilityTestHelper::sendRequest(
        $tracesEndpoint,
        'POST',
        $traceData,
        $headers
    );

    expect($response['status'])->toBeIn([200, 202]);
})->group('observability', 'phoenix');

test('phoenix api returns dataset information', function () {
    $config = ObservabilityTestHelper::getTestConfig('phoenix');
    $datasetsEndpoint = $config['base_url'].'/v1/datasets';

    $headers = [];
    if (! empty($config['api_key'])) {
        $headers['api-key'] = $config['api_key'];
    }

    $response = ObservabilityTestHelper::sendRequest(
        $datasetsEndpoint,
        'GET',
        [],
        $headers
    );

    // Should return 200 or 401 if auth required
    expect($response['status'])->toBeIn([200, 401]);
})->group('observability', 'phoenix');
