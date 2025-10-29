<?php

declare(strict_types=1);

use Tests\Integration\Observability\ObservabilityTestHelper;

/**
 * Setup tests for observability stack
 *
 * These tests verify that all observability services are running
 * and accessible before running other integration tests.
 */
beforeAll(function () {
    // Only check services if we're running observability tests
    if (! in_array('observability', $_SERVER['argv'] ?? [])) {
        return;
    }

    try {
        ObservabilityTestHelper::ensureServicesRunning();
    } catch (RuntimeException $e) {
        echo "\n\n⚠️  {$e->getMessage()}\n\n";
        throw $e;
    }
});

test('all observability services are accessible', function () {
    $services = ObservabilityTestHelper::getServiceEndpoints();

    foreach ($services as $name => $config) {
        expect(ObservabilityTestHelper::isServiceAvailable($config['url']))
            ->toBeTrue("Expected {$name} to be available at {$config['url']}");
    }
})->group('observability');

test('jaeger UI is accessible', function () {
    $url = ObservabilityTestHelper::getTestConfig('jaeger')['ui_url'];

    $response = ObservabilityTestHelper::sendRequest($url);

    expect($response['status'])->toBe(200);
    expect($response['body'])->toContain('Jaeger UI');
})->group('observability', 'jaeger');

test('phoenix UI is accessible', function () {
    $url = ObservabilityTestHelper::getTestConfig('phoenix')['base_url'];

    $response = ObservabilityTestHelper::sendRequest($url);

    expect($response['status'])->toBe(200);
    expect($response['body'])->toContain('Phoenix');
})->group('observability', 'phoenix');

test('langfuse health endpoint responds', function () {
    $config = ObservabilityTestHelper::getTestConfig('langfuse');
    $url = $config['base_url'].'/api/public/health';

    $response = ObservabilityTestHelper::sendRequest($url);

    expect($response['status'])->toBe(200);

    $data = json_decode($response['body'], true);
    expect($data)->toHaveKey('status');
    expect($data['status'])->toBe('OK');
})->group('observability', 'langfuse');

test('helicone UI is accessible', function () {
    $url = ObservabilityTestHelper::getTestConfig('helicone')['base_url'];

    $response = ObservabilityTestHelper::sendRequest($url);

    // Helicone redirects to /signin
    expect($response['status'])->toBeIn([200, 307]);
})->group('observability', 'helicone');

test('opik backend health endpoint responds', function () {
    $config = ObservabilityTestHelper::getTestConfig('opik');
    $url = $config['url'].'/health';

    // Opik may take longer to start
    $isAvailable = ObservabilityTestHelper::waitForService($url, 10, 2);

    expect($isAvailable)->toBeTrue('Opik backend should be available');
})->group('observability', 'opik');
