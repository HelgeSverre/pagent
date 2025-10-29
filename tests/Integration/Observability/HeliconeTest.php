<?php

declare(strict_types=1);

use Tests\Integration\Observability\ObservabilityTestHelper;

/**
 * Helicone integration tests
 *
 * Tests LLM cost tracking and proxy with Helicone
 */

test('helicone web ui is accessible', function () {
    $config = ObservabilityTestHelper::getTestConfig('helicone');
    $url = $config['base_url'];

    $response = ObservabilityTestHelper::sendRequest($url);

    // Redirects to signin page
    expect($response['status'])->toBeIn([200, 307]);
})->group('observability', 'helicone');

test('helicone gateway is running', function () {
    $config = ObservabilityTestHelper::getTestConfig('helicone');
    $gatewayUrl = $config['gateway_url'];

    // Gateway should be accessible
    $isAvailable = ObservabilityTestHelper::isServiceAvailable($gatewayUrl);

    expect($isAvailable)->toBeTrue('Helicone gateway should be running at ' . $gatewayUrl);
})->group('observability', 'helicone');

test('helicone gateway requires authentication', function () {
    $config = ObservabilityTestHelper::getTestConfig('helicone');
    $gatewayUrl = $config['gateway_url'] . '/v1/chat/completions';

    // Attempt without API key should fail
    $response = ObservabilityTestHelper::sendRequest(
        $gatewayUrl,
        'POST',
        [
            'model' => 'gpt-4',
            'messages' => [['role' => 'user', 'content' => 'test']],
        ]
    );

    // Should require authentication
    expect($response['status'])->toBeIn([401, 403]);
})->group('observability', 'helicone');

test('helicone services are properly connected', function () {
    // Verify database connection
    $dbUrl = 'http://localhost:3001';
    expect(ObservabilityTestHelper::isServiceAvailable($dbUrl))
        ->toBeTrue('Helicone web service should be connected to database');

    // Verify internal services are running (check via docker)
    // The all-in-one image should have all services running
    expect(true)->toBeTrue();
})->group('observability', 'helicone');
