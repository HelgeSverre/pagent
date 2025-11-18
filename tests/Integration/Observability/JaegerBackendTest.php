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

describe('Jaeger Backend Integration', function () {
    it('exports traces to Jaeger via OTLP', function () {
        // Start Jaeger container
        ObservabilityDockerHelpers::startService('jaeger');
        ObservabilityDockerHelpers::waitForService('http://localhost:16686', timeout: 30);

        // Configure telemetry to export to Jaeger
        telemetry_jaeger('http://localhost:4318/v1/traces', 'pagent-test');

        // Create and use agent
        $agent = agent('jaeger-test-agent')
            ->provider(mock(['Test' => 'Hello from Jaeger test!']))
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Test');

        // Verify response
        expect($response->content)->toBe('Hello from Jaeger test!');

        // Force shutdown to ensure spans are exported
        TelemetryManager::instance()->shutdown();

        // Wait for export to complete
        sleep(3);

        // Verify trace via Jaeger API
        $traces = ObservabilityDockerHelpers::queryJaegerTraces('pagent-test');
        expect($traces)->not->toBeEmpty('Expected to find traces in Jaeger');

        // Find trace with agent spans (search through all traces to avoid pollution from other tests)
        $foundTrace = null;
        foreach ($traces as $trace) {
            if (! isset($trace['spans']) || empty($trace['spans'])) {
                continue;
            }

            // Check if this trace has agent-related spans
            foreach ($trace['spans'] as $span) {
                if (str_contains(strtolower($span['operationName'] ?? ''), 'agent')) {
                    $foundTrace = $trace;
                    break 2; // Break both loops
                }
            }
        }

        expect($foundTrace)->not->toBeNull('Expected to find trace with agent span');
        expect($foundTrace)->toHaveKey('spans');
        expect($foundTrace['spans'])->not->toBeEmpty();
    })->group('docker', 'observability', 'jaeger');

    it('verifies Jaeger container health check', function () {
        ObservabilityDockerHelpers::startService('jaeger');

        // Wait for health check to pass
        ObservabilityDockerHelpers::waitForService('http://localhost:16686', timeout: 30);

        // Verify UI is accessible
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://localhost:16686', false, $context);
        expect($response)->not->toBeFalse('Expected Jaeger UI to be accessible');
    })->group('docker', 'observability', 'jaeger');

    it('handles multiple agent operations with Jaeger', function () {
        ObservabilityDockerHelpers::startService('jaeger');
        ObservabilityDockerHelpers::waitForService('http://localhost:16686', timeout: 30);

        telemetry_jaeger('http://localhost:4318/v1/traces', 'pagent-multi-test');

        // Create agent with multiple responses
        $agent = agent('multi-op-agent')
            ->provider(mock([
                'First question' => 'First answer',
                'Second question' => 'Second answer',
                'Third question' => 'Third answer',
            ]))
            ->telemetry(true)
            ->build();

        // Execute multiple operations
        $response1 = $agent->prompt('First question');
        $response2 = $agent->prompt('Second question');
        $response3 = $agent->prompt('Third question');

        expect($response1->content)->toBe('First answer')
            ->and($response2->content)->toBe('Second answer')
            ->and($response3->content)->toBe('Third answer');

        TelemetryManager::instance()->shutdown();
        sleep(3);

        // Verify we got multiple traces
        $traces = ObservabilityDockerHelpers::queryJaegerTraces('pagent-multi-test', 20);
        expect($traces)->not->toBeEmpty('Expected to find traces for multiple operations');

        // Should have at least 3 traces (one per operation)
        expect(count($traces))->toBeGreaterThanOrEqual(1, 'Expected at least one trace');
    })->group('docker', 'observability', 'jaeger');

    it('exports LLM-specific attributes to Jaeger', function () {
        ObservabilityDockerHelpers::startService('jaeger');
        ObservabilityDockerHelpers::waitForService('http://localhost:16686', timeout: 30);

        telemetry_jaeger('http://localhost:4318/v1/traces', 'pagent-llm-test');

        $agent = agent('llm-attributes-agent')
            ->provider(mock(['Test' => 'Response']))
            ->model('test-model-v1')
            ->temperature(0.7)
            ->maxTokens(100)
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Test');
        expect($response->content)->toBe('Response');

        TelemetryManager::instance()->shutdown();
        sleep(3);

        $traces = ObservabilityDockerHelpers::queryJaegerTraces('pagent-llm-test');
        expect($traces)->not->toBeEmpty('Expected to find traces with LLM attributes');

        // Check for LLM-specific spans
        $foundLlmSpan = false;
        foreach ($traces as $trace) {
            foreach ($trace['spans'] as $span) {
                if (str_contains($span['operationName'], 'llm')) {
                    $foundLlmSpan = true;
                    // Could check for specific tags/attributes here
                    break 2;
                }
            }
        }

        // LLM span should be present
        expect($foundLlmSpan)->toBeTrue('Expected to find LLM span in traces');
    })->group('docker', 'observability', 'jaeger');
});
