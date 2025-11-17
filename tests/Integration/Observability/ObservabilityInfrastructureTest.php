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

describe('Docker Compose Configuration', function () {
    it('can start services with profiles', function () {
        // Test that profile-based startup works
        ObservabilityDockerHelpers::startService('jaeger');

        // Verify container is running
        $composeCmd = ObservabilityDockerHelpers::getDockerComposeCommand();
        $composeFile = __DIR__.'/../../../docker-compose.observability.yml';

        exec("{$composeCmd} -f {$composeFile} ps jaeger 2>&1", $output, $returnCode);

        expect($returnCode)->toBe(0);
        $outputStr = implode("\n", $output);
        expect($outputStr)->toContain('pagent-jaeger');
    })->group('docker', 'observability');

    it('stops services cleanly', function () {
        ObservabilityDockerHelpers::startService('jaeger');
        ObservabilityDockerHelpers::waitForService('http://localhost:16686', timeout: 30);

        // Stop services
        ObservabilityDockerHelpers::stopObservabilityStack();

        // Verify container is stopped (ps should show no containers)
        $composeCmd = ObservabilityDockerHelpers::getDockerComposeCommand();
        $composeFile = __DIR__.'/../../../docker-compose.observability.yml';

        exec("{$composeCmd} -f {$composeFile} ps 2>&1", $output);
        $outputStr = implode("\n", $output);

        // Should not have pagent-jaeger in the output (or it should show as exited/removed)
        // This is a soft check - main goal is cleanup doesn't fail
        expect($outputStr)->not->toContain('Up', 'Expected no running containers after cleanup');
    })->group('docker', 'observability');
});
