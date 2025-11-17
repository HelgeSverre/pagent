<?php

declare(strict_types=1);

use Pagent\Observability\TelemetryManager;
use Tests\Integration\Observability\ObservabilityDockerHelpers;
use Tests\Integration\Observability\ObservabilityTestHelper;

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

describe('Phoenix Backend Integration', function () {
    it('exports traces to Phoenix via OTLP', function () {
        // Start Phoenix container
        ObservabilityDockerHelpers::startService('phoenix');
        ObservabilityDockerHelpers::waitForService('http://localhost:6006', timeout: 45);

        // Configure telemetry to export to Phoenix
        // Phoenix accepts OTLP on HTTP at port 6006
        telemetry_otlp('http://localhost:6006/v1/traces', [], 'pagent-phoenix-test');

        // Create and use agent
        $agent = agent('phoenix-test-agent')
            ->provider(mock(['Test' => 'Hello from Phoenix test!']))
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Test');

        // Verify response
        expect($response->content)->toBe('Hello from Phoenix test!');

        // Force shutdown to ensure spans are exported
        TelemetryManager::instance()->shutdown();

        // Wait for export and processing to complete
        sleep(5);

        // Get Phoenix project identifier
        $projectId = ObservabilityDockerHelpers::getPhoenixProjectIdentifier();
        expect($projectId)->not->toBeEmpty('Expected to find Phoenix project');

        // Query spans from Phoenix
        $spans = ObservabilityDockerHelpers::queryPhoenixSpans($projectId, limit: 100);
        expect($spans)->not->toBeEmpty('Expected to find spans in Phoenix');

        // Verify we have agent-related spans
        $hasAgentSpan = false;
        $hasLlmSpan = false;

        foreach ($spans as $span) {
            $spanName = $span['name'] ?? '';

            if (str_contains($spanName, 'agent')) {
                $hasAgentSpan = true;
            }

            if (str_contains($spanName, 'llm')) {
                $hasLlmSpan = true;
            }
        }

        expect($hasAgentSpan)->toBeTrue('Expected to find agent span in Phoenix');
        expect($hasLlmSpan)->toBeTrue('Expected to find LLM span in Phoenix');
    })->group('docker', 'observability', 'phoenix');

    it('exports LLM-specific attributes to Phoenix', function () {
        ObservabilityDockerHelpers::startService('phoenix');
        ObservabilityDockerHelpers::waitForService('http://localhost:6006', timeout: 45);

        telemetry_otlp('http://localhost:6006/v1/traces', [], 'pagent-phoenix-llm-test');

        // Create agent with specific LLM parameters
        $agent = agent('phoenix-llm-agent')
            ->provider(mock(['Test' => 'Response with LLM attributes']))
            ->model('test-model-phoenix')
            ->temperature(0.8)
            ->maxTokens(200)
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Test');
        expect($response->content)->toBe('Response with LLM attributes');

        TelemetryManager::instance()->shutdown();
        sleep(5);

        // Query spans
        $projectId = ObservabilityDockerHelpers::getPhoenixProjectIdentifier();
        $spans = ObservabilityDockerHelpers::queryPhoenixSpans($projectId, limit: 100);
        expect($spans)->not->toBeEmpty('Expected to find spans in Phoenix');

        // Look for LLM span with attributes
        $foundLlmSpanWithAttributes = false;
        foreach ($spans as $span) {
            if (isset($span['attributes']) && is_array($span['attributes'])) {
                $attributes = $span['attributes'];

                // Check for gen_ai attributes
                // Phoenix returns attributes as a flat associative array
                $hasGenAiSystem = isset($attributes['gen_ai.system']);
                $hasGenAiModel = isset($attributes['gen_ai.request.model']);

                if ($hasGenAiSystem && $hasGenAiModel) {
                    $foundLlmSpanWithAttributes = true;
                    break;
                }
            }
        }

        expect($foundLlmSpanWithAttributes)->toBeTrue('Expected to find LLM span with gen_ai attributes in Phoenix');
    })->group('docker', 'observability', 'phoenix');

    it('verifies Phoenix can list projects', function () {
        ObservabilityDockerHelpers::startService('phoenix');
        ObservabilityDockerHelpers::waitForService('http://localhost:6006', timeout: 45);

        // Query projects
        $projects = ObservabilityDockerHelpers::queryPhoenixProjects();
        expect($projects)->not->toBeEmpty('Expected Phoenix to have at least one project');

        // Verify project structure
        $firstProject = $projects[0];
        expect($firstProject)->toHaveKey('name');
        expect($firstProject['name'])->not->toBeEmpty();
    })->group('docker', 'observability', 'phoenix');

    it('phoenix api returns dataset information', function () {
        $config = ObservabilityTestHelper::getTestConfig('phoenix');
        $datasetsEndpoint = $config['base_url'].'/v1/datasets';

        $headers = [];
        if (! empty($config['api_key'])) {
            $headers['api-key'] = $config['api_key'];
        }

        $response = ObservabilityTestHelper::sendRequest(
            $datasetsEndpoint,
            'GET',
            [],
            $headers
        );

        // Should return 200 or 401 if auth required
        expect($response['status'])->toBeIn([200, 401]);
    })->group('observability', 'phoenix');
});
