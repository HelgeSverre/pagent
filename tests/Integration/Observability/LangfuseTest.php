<?php

declare(strict_types=1);

use Tests\Integration\Observability\ObservabilityTestHelper;

/**
 * Langfuse integration tests
 *
 * Tests LLM monitoring and prompt management with Langfuse
 */

test('langfuse public api requires authentication', function () {
    $config = ObservabilityTestHelper::getTestConfig('langfuse');
    $endpoint = $config['base_url'] . '/api/public/traces';

    // Attempt without credentials should fail
    $response = ObservabilityTestHelper::sendRequest($endpoint, 'GET');

    expect($response['status'])->toBe(401);
})->group('observability', 'langfuse');

test('can create trace with langfuse api when configured', function () {
    $config = ObservabilityTestHelper::getTestConfig('langfuse');

    if (empty($config['public_key']) || empty($config['secret_key'])) {
        $this->markTestSkipped('Langfuse API keys not configured. Set TEST_LANGFUSE_PUBLIC_KEY and TEST_LANGFUSE_SECRET_KEY');
    }

    $endpoint = $config['base_url'] . '/api/public/ingestion';

    $traceData = [
        'batch' => [
            [
                'id' => uniqid('trace_'),
                'type' => 'trace-create',
                'timestamp' => date('c'),
                'body' => [
                    'id' => uniqid('trace_'),
                    'name' => 'integration-test-trace',
                    'userId' => 'test-user',
                    'metadata' => [
                        'test' => true,
                        'framework' => 'pagent',
                    ],
                ],
            ],
        ],
    ];

    $credentials = base64_encode($config['public_key'] . ':' . $config['secret_key']);

    $response = ObservabilityTestHelper::sendRequest(
        $endpoint,
        'POST',
        $traceData,
        [
            'Authorization' => 'Basic ' . $credentials,
            'Content-Type' => 'application/json',
        ]
    );

    expect($response['status'])->toBeIn([200, 201, 207]);
})->group('observability', 'langfuse');

test('langfuse health endpoint is accessible', function () {
    $config = ObservabilityTestHelper::getTestConfig('langfuse');
    $endpoint = $config['base_url'] . '/api/public/health';

    $response = ObservabilityTestHelper::sendRequest($endpoint);

    expect($response['status'])->toBe(200);

    $data = json_decode($response['body'], true);
    expect($data)->toHaveKeys(['status', 'version']);
    expect($data['status'])->toBe('OK');
})->group('observability', 'langfuse');

test('langfuse returns project information when authenticated', function () {
    $config = ObservabilityTestHelper::getTestConfig('langfuse');

    if (empty($config['public_key']) || empty($config['secret_key'])) {
        $this->markTestSkipped('Langfuse API keys not configured');
    }

    $endpoint = $config['base_url'] . '/api/public/traces';

    $credentials = base64_encode($config['public_key'] . ':' . $config['secret_key']);

    $response = ObservabilityTestHelper::sendRequest(
        $endpoint,
        'GET',
        [],
        ['Authorization' => 'Basic ' . $credentials]
    );

    expect($response['status'])->toBe(200);
})->group('observability', 'langfuse');
