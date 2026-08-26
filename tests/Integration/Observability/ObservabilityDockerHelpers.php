<?php

declare(strict_types=1);

namespace Tests\Integration\Observability;

use RuntimeException;
use Throwable;

/**
 * Shared helper functions for Docker-based observability backend integration tests.
 */
class ObservabilityDockerHelpers
{
    private const COMPOSE_FILE = __DIR__.'/../../../docker-compose.observability.yml';

    private const PROFILES = ['jaeger', 'zipkin', 'phoenix', 'langfuse', 'opik', 'helicone'];

    /**
     * Check if Docker is available.
     */
    public static function isDockerAvailable(): bool
    {
        $result = shell_exec('docker --version 2>&1');

        return $result !== null && str_contains($result, 'Docker version');
    }

    /**
     * Check if docker-compose is available.
     */
    public static function isDockerComposeAvailable(): bool
    {
        $result = shell_exec('docker compose version 2>&1');
        if ($result !== null && str_contains($result, 'Docker Compose version')) {
            return true;
        }

        // Try legacy docker-compose command
        $result = shell_exec('docker-compose --version 2>&1');

        return $result !== null && str_contains($result, 'docker-compose version');
    }

    /**
     * Get the docker-compose command to use.
     */
    public static function getDockerComposeCommand(): string
    {
        $result = shell_exec('docker compose version 2>&1');
        if ($result !== null && str_contains($result, 'Docker Compose version')) {
            return 'docker compose';
        }

        return 'docker-compose';
    }

    /**
     * Start a specific service or profile using docker-compose.
     */
    public static function startService(string $service, int $timeout = 60): void
    {
        $composeCmd = self::getDockerComposeCommand();

        // Use profile if it's a known profile, otherwise start by service name
        if (in_array($service, self::PROFILES, true)) {
            $cmd = "{$composeCmd} -f ".self::COMPOSE_FILE." --profile {$service} up -d 2>&1";
        } else {
            $cmd = "{$composeCmd} -f ".self::COMPOSE_FILE." up -d {$service} 2>&1";
        }

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException("Failed to start {$service}: ".implode("\n", $output));
        }

        // Wait a bit for container to start
        sleep(2);
    }

    /**
     * Wait for a service to become healthy via HTTP.
     */
    public static function waitForService(string $url, int $timeout = 60): void
    {
        $start = time();
        $lastError = '';

        while (time() - $start < $timeout) {
            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 2,
                        'ignore_errors' => true,
                    ],
                ]);

                $response = @file_get_contents($url, false, $context);
                if ($response !== false || (isset($http_response_header[0]) && preg_match('/HTTP\/\d\.\d\s+[23]\d{2}/', $http_response_header[0]))) {
                    // Service is up (either got response or got a 2xx/3xx status)
                    return;
                }

                $lastError = 'Service not ready yet';
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }

            sleep(1);
        }

        throw new RuntimeException("Service at {$url} did not become available within {$timeout} seconds. Last error: {$lastError}");
    }

    /**
     * Stop all observability services.
     */
    public static function stopObservabilityStack(): void
    {
        $composeCmd = self::getDockerComposeCommand();

        exec("{$composeCmd} -f ".self::COMPOSE_FILE.' down -v 2>&1', $output, $returnCode);

        // Cleanup is best effort. The command output remains available to a
        // debugger through $output without contaminating the test runner.
    }

    /**
     * Query Jaeger for traces.
     */
    public static function queryJaegerTraces(string $service = 'pagent', int $limit = 10): array
    {
        $url = "http://localhost:16686/api/traces?service={$service}&limit={$limit}";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (! is_array($data) || ! isset($data['data'])) {
            return [];
        }

        return $data['data'];
    }

    /**
     * Query Zipkin for traces.
     */
    public static function queryZipkinTraces(string $service = 'pagent', int $limit = 10): array
    {
        $url = "http://localhost:9411/api/v2/traces?serviceName={$service}&limit={$limit}";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Query Phoenix projects list.
     */
    public static function queryPhoenixProjects(): array
    {
        $url = 'http://localhost:6006/v1/projects';

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (! is_array($data) || ! isset($data['data'])) {
            return [];
        }

        return $data['data'];
    }

    /**
     * Query Phoenix for spans in a specific project.
     */
    public static function queryPhoenixSpans(string $projectIdentifier = 'default', int $limit = 100): array
    {
        // Phoenix REST API endpoint for spans
        $url = "http://localhost:6006/v1/projects/{$projectIdentifier}/spans?limit={$limit}";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (! is_array($data) || ! isset($data['data'])) {
            return [];
        }

        return $data['data'];
    }

    /**
     * Get the first available Phoenix project identifier.
     */
    public static function getPhoenixProjectIdentifier(): string
    {
        $projects = self::queryPhoenixProjects();

        // Return first project name, or default to 'default'
        if (! empty($projects) && isset($projects[0]['name'])) {
            return $projects[0]['name'];
        }

        return 'default';
    }

    /**
     * Query Langfuse for traces.
     */
    public static function queryLangfuseTraces(int $limit = 10): array
    {
        // Langfuse REST API endpoint for traces
        $url = "http://localhost:3000/api/public/traces?limit={$limit}";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (! is_array($data) || ! isset($data['data'])) {
            return [];
        }

        return $data['data'];
    }

    /**
     * Query Opik for traces.
     */
    public static function queryOpikTraces(string $workspace = 'default', int $limit = 10): array
    {
        // Opik REST API endpoint for traces
        $url = "http://localhost:8080/api/traces?workspace={$workspace}&limit={$limit}";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if (! is_array($data)) {
            return [];
        }

        // Check if response is an error (Opik returns {"code": 404, "message": "..."})
        if (isset($data['code']) && isset($data['message'])) {
            return [];
        }

        return $data;
    }
}
