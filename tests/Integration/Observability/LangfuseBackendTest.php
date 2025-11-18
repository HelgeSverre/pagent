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

    it('exports traces to Langfuse via OTLP', function () {
        // Check if Langfuse API keys are configured (required for OTLP endpoint)
        $publicKey = getenv('TEST_LANGFUSE_PUBLIC_KEY');
        $secretKey = getenv('TEST_LANGFUSE_SECRET_KEY');

        if (empty($publicKey) || empty($secretKey)) {
            $this->markTestSkipped('Langfuse OTLP requires API keys. Set TEST_LANGFUSE_PUBLIC_KEY and TEST_LANGFUSE_SECRET_KEY. Note: Langfuse v3.22.0+ is required for local OTLP support.');
        }

        // Start Langfuse container
        ObservabilityDockerHelpers::startService('langfuse');
        ObservabilityDockerHelpers::waitForService('http://localhost:3000/api/public/health', timeout: 60);

        // Configure telemetry to export to Langfuse OTLP endpoint with Basic Auth
        $authString = base64_encode("{$publicKey}:{$secretKey}");
        telemetry_otlp(
            'http://localhost:3000/api/public/otel/v1/traces',
            ['Authorization' => "Basic {$authString}"],
            'pagent-langfuse-test'
        );

        // Create and use agent
        $agent = agent('langfuse-test-agent')
            ->provider(mock(['Test' => 'Hello from Langfuse test!']))
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('Test');

        // Verify response
        expect($response->content)->toBe('Hello from Langfuse test!');

        // Force shutdown to ensure spans are exported
        TelemetryManager::instance()->shutdown();

        // Wait for export to complete (Langfuse may need more time for processing)
        sleep(5);

        // Verify trace via Langfuse API
        $traces = ObservabilityDockerHelpers::queryLangfuseTraces();
        expect($traces)->not->toBeEmpty('Expected to find traces in Langfuse');

        // Verify we have at least one trace
        expect(count($traces))->toBeGreaterThan(0);
    })->group('docker', 'observability', 'langfuse', 'otlp');

    it('verifies LLM-specific attributes in Langfuse', function () {
        $publicKey = getenv('TEST_LANGFUSE_PUBLIC_KEY');
        $secretKey = getenv('TEST_LANGFUSE_SECRET_KEY');

        if (empty($publicKey) || empty($secretKey)) {
            $this->markTestSkipped('Langfuse OTLP requires API keys. Set TEST_LANGFUSE_PUBLIC_KEY and TEST_LANGFUSE_SECRET_KEY');
        }

        ObservabilityDockerHelpers::startService('langfuse');
        ObservabilityDockerHelpers::waitForService('http://localhost:3000/api/public/health', timeout: 60);

        $authString = base64_encode("{$publicKey}:{$secretKey}");
        telemetry_otlp(
            'http://localhost:3000/api/public/otel/v1/traces',
            ['Authorization' => "Basic {$authString}"],
            'pagent-langfuse-llm-test'
        );

        // Create agent with LLM provider
        $agent = agent('langfuse-llm-agent')
            ->provider(mock([
                'What is AI?' => 'AI is artificial intelligence.',
            ]))
            ->telemetry(true)
            ->build();

        $response = $agent->prompt('What is AI?');
        expect($response->content)->toBe('AI is artificial intelligence.');

        TelemetryManager::instance()->shutdown();
        sleep(5);

        // Query Langfuse for traces
        $traces = ObservabilityDockerHelpers::queryLangfuseTraces();
        expect($traces)->not->toBeEmpty('Expected to find LLM traces in Langfuse');
    })->group('docker', 'observability', 'langfuse', 'otlp');

    it('handles multiple agent operations with Langfuse', function () {
        $publicKey = getenv('TEST_LANGFUSE_PUBLIC_KEY');
        $secretKey = getenv('TEST_LANGFUSE_SECRET_KEY');

        if (empty($publicKey) || empty($secretKey)) {
            $this->markTestSkipped('Langfuse OTLP requires API keys. Set TEST_LANGFUSE_PUBLIC_KEY and TEST_LANGFUSE_SECRET_KEY');
        }

        ObservabilityDockerHelpers::startService('langfuse');
        ObservabilityDockerHelpers::waitForService('http://localhost:3000/api/public/health', timeout: 60);

        $authString = base64_encode("{$publicKey}:{$secretKey}");
        telemetry_otlp(
            'http://localhost:3000/api/public/otel/v1/traces',
            ['Authorization' => "Basic {$authString}"],
            'pagent-langfuse-multi-test'
        );

        // Create agent with multiple responses
        $agent = agent('langfuse-multi-op-agent')
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

        expect($response1->content)->toBe('First answer');
        expect($response2->content)->toBe('Second answer');
        expect($response3->content)->toBe('Third answer');

        TelemetryManager::instance()->shutdown();
        sleep(5);

        // Verify multiple traces/spans in Langfuse
        $traces = ObservabilityDockerHelpers::queryLangfuseTraces();
        expect($traces)->not->toBeEmpty('Expected to find multiple operation traces in Langfuse');
    })->group('docker', 'observability', 'langfuse', 'otlp');
});
