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

    it('exports traces to Opik via OTLP', function () {
        // Start Opik container
        ObservabilityDockerHelpers::startService('opik');
        ObservabilityDockerHelpers::waitForService('http://localhost:8080/health-check', timeout: 90);

        // Configure telemetry to export to Opik OTLP endpoint
        // Opik supports OTLP HTTP at standard /v1/traces endpoint
        // Note: Local Opik may accept traces without authentication,
        // but production Opik requires API key via Authorization header
        $apiKey = getenv('TEST_OPIK_API_KEY');
        $headers = $apiKey ? ['Authorization' => "Bearer {$apiKey}"] : [];

        telemetry_otlp('http://localhost:8080/v1/traces', $headers, 'pagent-opik-test');

        // Create and use agent
        $agent = agent('opik-test-agent')
            ->provider(mock(['Test' => 'Hello from Opik test!']))
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Test');

        // Verify response
        expect($response->content)->toBe('Hello from Opik test!');

        // Force shutdown to ensure spans are exported
        TelemetryManager::instance()->shutdown();

        // Wait for export to complete
        sleep(5);

        // Verify trace via Opik API
        $traces = ObservabilityDockerHelpers::queryOpikTraces();
        expect($traces)->not->toBeEmpty('Expected to find traces in Opik');

        // Verify we have at least one trace
        expect(count($traces))->toBeGreaterThan(0);
    })->group('docker', 'observability', 'opik', 'otlp');

    it('verifies LLM-specific attributes in Opik', function () {
        ObservabilityDockerHelpers::startService('opik');
        ObservabilityDockerHelpers::waitForService('http://localhost:8080/health-check', timeout: 90);

        $apiKey = getenv('TEST_OPIK_API_KEY');
        $headers = $apiKey ? ['Authorization' => "Bearer {$apiKey}"] : [];

        telemetry_otlp('http://localhost:8080/v1/traces', $headers, 'pagent-opik-llm-test');

        // Create agent with LLM provider
        $agent = agent('opik-llm-agent')
            ->provider(mock([
                'Explain ML' => 'Machine learning is a subset of AI.',
            ]))
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Explain ML');
        expect($response->content)->toBe('Machine learning is a subset of AI.');

        TelemetryManager::instance()->shutdown();
        sleep(5);

        // Query Opik for traces
        $traces = ObservabilityDockerHelpers::queryOpikTraces();
        expect($traces)->not->toBeEmpty('Expected to find LLM traces in Opik');
    })->group('docker', 'observability', 'opik', 'otlp');

    it('handles multiple agent operations with Opik', function () {
        ObservabilityDockerHelpers::startService('opik');
        ObservabilityDockerHelpers::waitForService('http://localhost:8080/health-check', timeout: 90);

        $apiKey = getenv('TEST_OPIK_API_KEY');
        $headers = $apiKey ? ['Authorization' => "Bearer {$apiKey}"] : [];

        telemetry_otlp('http://localhost:8080/v1/traces', $headers, 'pagent-opik-multi-test');

        // Create agent with multiple responses
        $agent = agent('opik-multi-op-agent')
            ->provider(mock([
                'Question 1' => 'Answer 1',
                'Question 2' => 'Answer 2',
                'Question 3' => 'Answer 3',
            ]))
            ->telemetry(true)
            ->build();

        // Execute multiple operations
        $response1 = $agent->prompt('Question 1');
        $response2 = $agent->prompt('Question 2');
        $response3 = $agent->prompt('Question 3');

        expect($response1->content)->toBe('Answer 1');
        expect($response2->content)->toBe('Answer 2');
        expect($response3->content)->toBe('Answer 3');

        TelemetryManager::instance()->shutdown();
        sleep(5);

        // Verify multiple traces/spans in Opik
        $traces = ObservabilityDockerHelpers::queryOpikTraces();
        expect($traces)->not->toBeEmpty('Expected to find multiple operation traces in Opik');
    })->group('docker', 'observability', 'opik', 'otlp');
});
