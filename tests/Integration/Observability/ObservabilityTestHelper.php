<?php

declare(strict_types=1);

namespace Tests\Integration\Observability;

use RuntimeException;

/**
 * Helper class for observability integration tests
 */
final class ObservabilityTestHelper
{
    /**
     * Check if all observability services are running
     *
     * @throws RuntimeException if any service is not available
     */
    public static function ensureServicesRunning(): void
    {
        $services = self::getServiceEndpoints();

        foreach ($services as $name => $config) {
            if (! self::isServiceAvailable($config['url'], $config['timeout'] ?? 5)) {
                throw new RuntimeException(
                    sprintf(
                        '%s is not available at %s. Please run: just observability-up',
                        $name,
                        $config['url']
                    )
                );
            }
        }
    }

    /**
     * Get service endpoint configurations
     *
     * @return array<string, array{url: string, timeout?: int}>
     */
    public static function getServiceEndpoints(): array
    {
        return [
            'Jaeger' => [
                'url' => getenv('TEST_JAEGER_UI_URL') ?: 'http://localhost:16686',
                'timeout' => 5,
            ],
            'Phoenix' => [
                'url' => getenv('TEST_PHOENIX_BASE_URL') ?: 'http://localhost:6006',
                'timeout' => 5,
            ],
            'Langfuse' => [
                'url' => getenv('TEST_LANGFUSE_BASE_URL') ?: 'http://localhost:3000',
                'timeout' => 5,
            ],
            'Helicone' => [
                'url' => getenv('TEST_HELICONE_BASE_URL') ?: 'http://localhost:3001',
                'timeout' => 5,
            ],
            'Opik' => [
                'url' => (getenv('TEST_OPIK_URL') ?: 'http://localhost:8080').'/health-check',
                'timeout' => 5,
            ],
        ];
    }

    /**
     * Check if a service is available
     */
    public static function isServiceAvailable(string $url, int $timeout = 5): bool
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local testing

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Accept any 2xx or 3xx response (redirect to login is fine)
        return $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * Send HTTP request and return response
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function sendRequest(
        string $url,
        string $method = 'GET',
        array $data = [],
        array $headers = []
    ): array {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if (! empty($data)) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers['Content-Type'] = 'application/json';
        }

        if (! empty($headers)) {
            $curlHeaders = [];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "{$key}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'body' => $response,
        ];
    }

    /**
     * Wait for a service to be ready
     */
    public static function waitForService(string $url, int $maxAttempts = 30, int $sleepSeconds = 1): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (self::isServiceAvailable($url)) {
                return true;
            }
            sleep($sleepSeconds);
        }

        return false;
    }

    /**
     * Get test configuration from environment
     *
     * @return array<string, mixed>
     */
    public static function getTestConfig(string $service): array
    {
        $configs = [
            'jaeger' => [
                'otlp_http' => getenv('TEST_JAEGER_OTLP_HTTP') ?: 'http://localhost:4318',
                'otlp_grpc' => getenv('TEST_JAEGER_OTLP_GRPC') ?: 'http://localhost:4317',
                'ui_url' => getenv('TEST_JAEGER_UI_URL') ?: 'http://localhost:16686',
            ],
            'phoenix' => [
                'base_url' => getenv('TEST_PHOENIX_BASE_URL') ?: 'http://localhost:6006',
                'api_key' => getenv('TEST_PHOENIX_API_KEY') ?: null,
            ],
            'langfuse' => [
                'base_url' => getenv('TEST_LANGFUSE_BASE_URL') ?: 'http://localhost:3000',
                'public_key' => getenv('TEST_LANGFUSE_PUBLIC_KEY') ?: null,
                'secret_key' => getenv('TEST_LANGFUSE_SECRET_KEY') ?: null,
            ],
            'helicone' => [
                'base_url' => getenv('TEST_HELICONE_BASE_URL') ?: 'http://localhost:3001',
                'gateway_url' => getenv('TEST_HELICONE_GATEWAY_URL') ?: 'http://localhost:8585',
                'api_key' => getenv('TEST_HELICONE_API_KEY') ?: null,
            ],
            'opik' => [
                'url' => getenv('TEST_OPIK_URL') ?: 'http://localhost:8080',
                'api_key' => getenv('TEST_OPIK_API_KEY') ?: null,
                'workspace' => getenv('TEST_OPIK_WORKSPACE') ?: 'default',
            ],
        ];

        return $configs[$service] ?? [];
    }
}
