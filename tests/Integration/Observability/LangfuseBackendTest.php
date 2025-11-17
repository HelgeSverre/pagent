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

describe('Langfuse Backend Integration', function () {
    it('verifies Langfuse container health check', function () {
        ObservabilityDockerHelpers::startService('langfuse');

        // Langfuse takes longer to start due to database migrations
        ObservabilityDockerHelpers::waitForService('http://localhost:3000/api/public/health', timeout: 60);

        // Verify health endpoint responds correctly
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://localhost:3000/api/public/health', false, $context);
        expect($response)->not->toBeFalse('Expected Langfuse health endpoint to be accessible');
    })->group('docker', 'observability', 'langfuse');

    it('verifies Langfuse UI is accessible', function () {
        ObservabilityDockerHelpers::startService('langfuse');
        ObservabilityDockerHelpers::waitForService('http://localhost:3000/api/public/health', timeout: 60);

        // Check that the UI homepage is accessible
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://localhost:3000', false, $context);
        expect($response)->not->toBeFalse('Expected Langfuse UI to be accessible');
    })->group('docker', 'observability', 'langfuse');

    it('can start Langfuse with database dependency', function () {
        // This test verifies that Langfuse starts successfully with its PostgreSQL dependency
        ObservabilityDockerHelpers::startService('langfuse');

        // Give it time to initialize
        sleep(5);

        // Verify both containers are running
        $composeCmd = ObservabilityDockerHelpers::getDockerComposeCommand();
        $composeFile = __DIR__.'/../../../docker-compose.observability.yml';

        exec("{$composeCmd} -f {$composeFile} ps langfuse 2>&1", $output, $returnCode);

        expect($returnCode)->toBe(0);
        $outputStr = implode("\n", $output);
        expect($outputStr)->toContain('pagent-langfuse');
    })->group('docker', 'observability', 'langfuse');
})->skip('Langfuse integration tests require API key configuration and are not yet fully implemented');
