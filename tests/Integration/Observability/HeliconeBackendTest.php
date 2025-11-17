<?php

declare(strict_types=1);

use Pagent\Observability\TelemetryManager;
use Tests\Integration\Observability\ObservabilityDockerHelpers;

require_once __DIR__.'/../../../src/functions.php';

beforeEach(function () {
    // Check if Docker is available
    if (! ObservabilityDockerHelpers::isDockerAvailable()) {
        $this->markTestSkipped('Docker not available');
    }

    if (! ObservabilityDockerHelpers::isDockerComposeAvailable()) {
        $this->markTestSkipped('Docker Compose not available');
    }

    // Reset telemetry manager before each test
    TelemetryManager::reset();
    clearAgents();
});

afterEach(function () {
    // Clean up: stop containers
    ObservabilityDockerHelpers::stopObservabilityStack();

    // Reset telemetry
    TelemetryManager::reset();
    clearAgents();
});

describe('Helicone Backend Integration', function () {
    it('verifies Helicone UI is accessible', function () {
        ObservabilityDockerHelpers::startService('helicone');

        // Helicone takes some time to start with its PostgreSQL dependency
        ObservabilityDockerHelpers::waitForService('http://localhost:3001', timeout: 60);

        // Verify UI is accessible
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://localhost:3001', false, $context);
        expect($response)->not->toBeFalse('Expected Helicone UI to be accessible');
    })->group('docker', 'observability', 'helicone');

    it('verifies Helicone Jawn gateway is accessible', function () {
        ObservabilityDockerHelpers::startService('helicone');

        // Wait for main service first
        ObservabilityDockerHelpers::waitForService('http://localhost:3001', timeout: 60);

        // Check gateway port (this might not have a simple health endpoint)
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        // Try to connect to gateway port (may return error but should be listening)
        $result = @file_get_contents('http://localhost:8585', false, $context);

        // Gateway might reject our request but should be listening
        // Check if we got ANY response (even an error) or if http_response_header is set
        $isAccessible = $result !== false || isset($http_response_header);

        expect($isAccessible)->toBeTrue('Expected Helicone Jawn gateway to be listening');
    })->group('docker', 'observability', 'helicone');

    it('can start Helicone with database dependency', function () {
        // This test verifies that Helicone starts successfully with its PostgreSQL dependency
        ObservabilityDockerHelpers::startService('helicone');

        // Give it time to initialize
        sleep(5);

        // Verify both containers are running
        $composeCmd = ObservabilityDockerHelpers::getDockerComposeCommand();
        $composeFile = __DIR__.'/../../../docker-compose.observability.yml';

        exec("{$composeCmd} -f {$composeFile} ps helicone 2>&1", $output, $returnCode);

        expect($returnCode)->toBe(0);
        $outputStr = implode("\n", $output);
        expect($outputStr)->toContain('pagent-helicone');
    })->group('docker', 'observability', 'helicone');
})->skip('Helicone integration tests require API key configuration and are not yet fully implemented');
