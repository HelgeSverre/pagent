<?php

declare(strict_types=1);

use Tests\Integration\Observability\ObservabilityTestHelper;

/**
 * Opik (Comet) integration tests
 *
 * Tests LLM experiment tracking with Opik
 */
test('opik health endpoint responds', function () {
    $config = ObservabilityTestHelper::getTestConfig('opik');
    $endpoint = $config['url'].'/health-check';

    // Wait for Opik to be ready (it can be slow to start)
    $isReady = ObservabilityTestHelper::waitForService($endpoint, 20, 2);

    expect($isReady)->toBeTrue('Opik should be available');

    $response = ObservabilityTestHelper::sendRequest($endpoint);

    expect($response['status'])->toBe(200);
})->group('observability', 'opik');

test('opik api endpoints are accessible', function () {
    $config = ObservabilityTestHelper::getTestConfig('opik');
    $endpoint = $config['url'].'/v1/private/projects';

    $response = ObservabilityTestHelper::sendRequest($endpoint, 'GET');

    // Local setup may not require authentication (returns 200) or may require it (401/403)
    expect($response['status'])->toBeIn([200, 401, 403, 404]);
})->group('observability', 'opik');

test('can query opik api with authentication when configured', function () {
    $config = ObservabilityTestHelper::getTestConfig('opik');

    if (empty($config['api_key'])) {
        $this->markTestSkipped('Opik API key not configured. Set TEST_OPIK_API_KEY');
    }

    $endpoint = $config['url'].'/v1/private/projects';

    $response = ObservabilityTestHelper::sendRequest(
        $endpoint,
        'GET',
        [],
        [
            'Authorization' => 'Bearer '.$config['api_key'],
            'Comet-Workspace' => $config['workspace'],
        ]
    );

    expect($response['status'])->toBeIn([200, 404]); // 404 if no projects yet
})->group('observability', 'opik');

test('opik backend has required services running', function () {
    $config = ObservabilityTestHelper::getTestConfig('opik');

    // Check main API
    expect(ObservabilityTestHelper::isServiceAvailable($config['url'].'/health-check'))
        ->toBeTrue('Opik backend API should be available');

    // Check if frontend is accessible (via docker)
    $frontendUrl = str_replace('8080', '5173', $config['url']);
    expect(ObservabilityTestHelper::isServiceAvailable($frontendUrl))
        ->toBeTrue('Opik frontend should be available');
})->group('observability', 'opik');
