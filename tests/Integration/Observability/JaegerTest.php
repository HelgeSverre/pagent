<?php

declare(strict_types=1);

use Tests\Integration\Observability\ObservabilityTestHelper;

/**
 * Jaeger integration tests
 *
 * Tests distributed tracing with Jaeger using OTLP protocol
 */

test('can send OTLP trace data to jaeger', function () {
    $config = ObservabilityTestHelper::getTestConfig('jaeger');
    $otlpEndpoint = $config['otlp_http'] . '/v1/traces';

    // Sample OTLP trace data
    $traceData = [
        'resourceSpans' => [
            [
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => 'pagent-test']],
                    ],
                ],
                'scopeSpans' => [
                    [
                        'scope' => ['name' => 'pagent-integration-test'],
                        'spans' => [
                            [
                                'traceId' => bin2hex(random_bytes(16)),
                                'spanId' => bin2hex(random_bytes(8)),
                                'name' => 'test-span',
                                'kind' => 1, // SPAN_KIND_INTERNAL
                                'startTimeUnixNano' => (string) (time() * 1000000000),
                                'endTimeUnixNano' => (string) ((time() + 1) * 1000000000),
                                'attributes' => [
                                    ['key' => 'test.type', 'value' => ['stringValue' => 'integration']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = ObservabilityTestHelper::sendRequest(
        $otlpEndpoint,
        'POST',
        $traceData,
        ['Content-Type' => 'application/json']
    );

    // OTLP endpoint should accept the trace
    expect($response['status'])->toBeIn([200, 202]);
})->group('observability', 'jaeger');

test('jaeger ui accepts service queries', function () {
    $config = ObservabilityTestHelper::getTestConfig('jaeger');
    $servicesEndpoint = $config['ui_url'] . '/api/services';

    $response = ObservabilityTestHelper::sendRequest($servicesEndpoint);

    expect($response['status'])->toBe(200);

    $data = json_decode($response['body'], true);
    expect($data)->toHaveKey('data');
    expect($data['data'])->toBeArray();
})->group('observability', 'jaeger');
