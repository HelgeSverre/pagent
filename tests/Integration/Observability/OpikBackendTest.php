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

describe('Opik Backend Integration', function () {
    it('verifies Opik backend container health check', function () {
        ObservabilityDockerHelpers::startService('opik');

        // Opik takes longer to start due to multiple dependencies (MySQL, ClickHouse, Redis, Zookeeper)
        ObservabilityDockerHelpers::waitForService('http://localhost:8080/health-check', timeout: 90);

        // Verify health endpoint responds correctly
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://localhost:8080/health-check', false, $context);
        expect($response)->not->toBeFalse('Expected Opik backend health endpoint to be accessible');
    })->group('docker', 'observability', 'opik');

    it('verifies Opik frontend is accessible', function () {
        ObservabilityDockerHelpers::startService('opik');

        // Wait for backend first
        ObservabilityDockerHelpers::waitForService('http://localhost:8080/health-check', timeout: 90);

        // Then wait for frontend
        ObservabilityDockerHelpers::waitForService('http://localhost:5173', timeout: 30);

        // Verify frontend is accessible
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://localhost:5173', false, $context);
        expect($response)->not->toBeFalse('Expected Opik frontend UI to be accessible');
    })->group('docker', 'observability', 'opik');

    it('can start Opik with all dependencies', function () {
        // This test verifies that Opik starts successfully with all its dependencies:
        // MySQL, ClickHouse, Redis, Zookeeper
        ObservabilityDockerHelpers::startService('opik');

        // Give it time to initialize all services
        sleep(10);

        // Verify Opik backend container is running
        $composeCmd = ObservabilityDockerHelpers::getDockerComposeCommand();
        $composeFile = __DIR__.'/../../../docker-compose.observability.yml';

        exec("{$composeCmd} -f {$composeFile} ps opik-backend 2>&1", $output, $returnCode);

        expect($returnCode)->toBe(0);
        $outputStr = implode("\n", $output);
        expect($outputStr)->toContain('pagent-opik-backend');
    })->group('docker', 'observability', 'opik');
})->skip('Opik integration tests require full stack startup and are not yet fully implemented');
