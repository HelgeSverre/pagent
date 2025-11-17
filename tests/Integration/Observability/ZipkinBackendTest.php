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

describe('Zipkin Backend Integration', function () {
    it('exports traces to Zipkin', function () {
        // Start Zipkin container
        ObservabilityDockerHelpers::startService('zipkin');
        ObservabilityDockerHelpers::waitForService('http://localhost:9411/health', timeout: 30);

        // Configure telemetry to export to Zipkin
        telemetry_zipkin('http://localhost:9411/api/v2/spans', 'pagent-test');

        // Create and use agent
        $agent = agent('zipkin-test-agent')
            ->provider(mock(['Test' => 'Hello from Zipkin test!']))
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Test');

        // Verify response
        expect($response->content)->toBe('Hello from Zipkin test!');

        // Force shutdown to ensure spans are exported
        TelemetryManager::instance()->shutdown();

        // Wait longer for Zipkin to process
        sleep(5);

        // Verify trace via Zipkin API
        $traces = ObservabilityDockerHelpers::queryZipkinTraces('pagent-test');

        expect($traces)->not->toBeEmpty('Expected to find traces in Zipkin');

        $firstTrace = $traces[0];
        expect($firstTrace)->toBeArray();
        expect($firstTrace)->not->toBeEmpty();

        // Verify we have spans with expected structure
        $firstSpan = $firstTrace[0];
        expect($firstSpan)->toHaveKey('name');
        expect($firstSpan)->toHaveKey('traceId');
        expect($firstSpan)->toHaveKey('id');

        // Check that we have agent-related spans
        $hasAgentSpan = false;
        foreach ($firstTrace as $span) {
            if (str_contains($span['name'], 'agent')) {
                $hasAgentSpan = true;
                break;
            }
        }
        expect($hasAgentSpan)->toBeTrue('Expected to find agent span in trace');
    })->group('docker', 'observability', 'zipkin');

    it('verifies Zipkin container health check', function () {
        ObservabilityDockerHelpers::startService('zipkin');

        // Wait for health check to pass
        ObservabilityDockerHelpers::waitForService('http://localhost:9411/health', timeout: 30);

        // Verify health endpoint responds correctly
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://localhost:9411/health', false, $context);
        expect($response)->not->toBeFalse('Expected Zipkin health endpoint to be accessible');
    })->group('docker', 'observability', 'zipkin');
});
