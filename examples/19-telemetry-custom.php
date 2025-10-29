<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Telemetry: Custom OTLP Configuration ===\n\n";
echo "This example demonstrates different telemetry configurations\n";
echo "for various deployment scenarios and observability backends.\n\n";

// Example 1: Basic OTLP with default settings
echo "=== Example 1: Default OTLP Configuration ===\n\n";

telemetry_otlp();

echo "Configuration:\n";
echo "  Exporter: OTLP\n";
echo "  Endpoint: http://localhost:4318/v1/traces (default)\n";
echo "  Service: pagent (default)\n\n";

agent('default-agent')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a helpful assistant.')
    ->telemetry(true);

$response1 = agent('default-agent')->prompt('Hello!');
echo "Response: {$response1->content}\n\n";

echo "Traces sent to default OTLP endpoint.\n\n";

// Example 2: Custom OTLP endpoint
echo "=== Example 2: Custom OTLP Endpoint ===\n\n";

telemetry_otlp(
    endpoint: 'http://otel-collector:4318/v1/traces',
    serviceName: 'my-app'
);

echo "Configuration:\n";
echo "  Exporter: OTLP\n";
echo "  Endpoint: http://otel-collector:4318/v1/traces\n";
echo "  Service: my-app\n\n";

agent('custom-agent')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a helpful assistant.')
    ->telemetry(true);

$response2 = agent('custom-agent')->prompt('What is PHP?');
echo "Response: {$response2->content}\n\n";

echo "Traces sent to custom OTLP collector.\n\n";

// Example 3: OTLP with authentication headers
echo "=== Example 3: OTLP with Authentication ===\n\n";

telemetry_otlp(
    endpoint: 'https://api.honeycomb.io/v1/traces',
    headers: [
        'x-honeycomb-team' => 'your-api-key-here',
        'x-honeycomb-dataset' => 'pagent-production',
    ],
    serviceName: 'pagent-production'
);

echo "Configuration:\n";
echo "  Exporter: OTLP\n";
echo "  Endpoint: https://api.honeycomb.io/v1/traces\n";
echo "  Headers: x-honeycomb-team, x-honeycomb-dataset\n";
echo "  Service: pagent-production\n\n";

echo "Note: Replace 'your-api-key-here' with actual API key\n\n";

// Example 4: Zipkin exporter
echo "=== Example 4: Zipkin Exporter ===\n\n";

telemetry_zipkin('http://localhost:9411/api/v2/spans', 'pagent-app');

echo "Configuration:\n";
echo "  Exporter: Zipkin\n";
echo "  Endpoint: http://localhost:9411/api/v2/spans\n";
echo "  Service: pagent-app\n\n";

echo "Start Zipkin with Docker:\n";
echo "  docker run -d -p 9411:9411 openzipkin/zipkin\n\n";

agent('zipkin-agent')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a helpful assistant.')
    ->telemetry(true);

$response3 = agent('zipkin-agent')->prompt('Tell me about distributed tracing');
echo "Response: {$response3->content}\n\n";

echo "View traces at: http://localhost:9411\n\n";

// Example 5: Advanced configuration with telemetry() function
echo "=== Example 5: Advanced Configuration ===\n\n";

telemetry([
    'enabled' => true,
    'service_name' => 'advanced-app',
    'service_version' => '2.0.0',
    'exporter' => 'otlp',
    'otlp' => [
        'endpoint' => 'http://localhost:4318/v1/traces',
        'headers' => [
            'Authorization' => 'Bearer token-123',
            'X-Custom-Header' => 'custom-value',
        ],
        'compression' => 'gzip',
        'timeout' => 10.0,
    ],
    'sampling_rate' => 1.0,  // 100% sampling
]);

echo "Configuration:\n";
echo "  Exporter: OTLP\n";
echo "  Service: advanced-app v2.0.0\n";
echo "  Compression: gzip\n";
echo "  Timeout: 10 seconds\n";
echo "  Sampling: 100%\n";
echo "  Custom headers: Authorization, X-Custom-Header\n\n";

agent('advanced-agent')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a helpful assistant.')
    ->telemetry(true);

$response4 = agent('advanced-agent')->prompt('Explain observability');
echo "Response: {$response4->content}\n\n";

// Example 6: Multiple exporters (simulation)
echo "=== Example 6: Multiple Exporters (Concept) ===\n\n";

echo "For multiple exporters, configure your OTLP collector to fan out:\n\n";

echo "OTLP Collector Config (otel-collector-config.yaml):\n";
echo "```yaml\n";
echo "receivers:\n";
echo "  otlp:\n";
echo "    protocols:\n";
echo "      http:\n";
echo "        endpoint: 0.0.0.0:4318\n";
echo "\n";
echo "exporters:\n";
echo "  jaeger:\n";
echo "    endpoint: jaeger:14250\n";
echo "  zipkin:\n";
echo "    endpoint: http://zipkin:9411/api/v2/spans\n";
echo "  prometheus:\n";
echo "    endpoint: prometheus:9090\n";
echo "\n";
echo "service:\n";
echo "  pipelines:\n";
echo "    traces:\n";
echo "      receivers: [otlp]\n";
echo "      exporters: [jaeger, zipkin]\n";
echo "```\n\n";

echo "Then configure Pagent to send to the collector:\n";
echo "```php\n";
echo "telemetry_otlp('http://otel-collector:4318/v1/traces');\n";
echo "```\n\n";

// Example 7: Environment-based configuration
echo "=== Example 7: Environment-Based Configuration ===\n\n";

echo "Production configuration example:\n\n";

echo "```php\n";
echo "\$environment = getenv('APP_ENV') ?: 'production';\n";
echo "\n";
echo "match (\$environment) {\n";
echo "    'local' => telemetry_console(verbose: true),\n";
echo "    'development' => telemetry_jaeger(\n";
echo "        'http://localhost:4318/v1/traces',\n";
echo "        'pagent-dev'\n";
echo "    ),\n";
echo "    'staging' => telemetry_otlp(\n";
echo "        'http://otel-collector-staging:4318/v1/traces',\n";
echo "        serviceName: 'pagent-staging'\n";
echo "    ),\n";
echo "    'production' => telemetry_otlp(\n";
echo "        'https://api.honeycomb.io/v1/traces',\n";
echo "        headers: ['x-honeycomb-team' => getenv('HONEYCOMB_API_KEY')],\n";
echo "        serviceName: 'pagent-production'\n";
echo "    ),\n";
echo "};\n";
echo "```\n\n";

// Example 8: Sampling configuration
echo "=== Example 8: Sampling Configuration ===\n\n";

echo "For high-traffic applications, use sampling:\n\n";

echo "```php\n";
echo "// Sample 10% of traces\n";
echo "telemetry([\n";
echo "    'enabled' => true,\n";
echo "    'exporter' => 'otlp',\n";
echo "    'sampling_rate' => 0.1,  // 10% sampling\n";
echo "    'otlp' => [\n";
echo "        'endpoint' => 'http://localhost:4318/v1/traces',\n";
echo "    ],\n";
echo "]);\n";
echo "```\n\n";

echo "Sampling reduces costs while maintaining visibility.\n\n";

echo "✅ Custom telemetry configuration examples completed!\n\n";
echo "Key points:\n";
echo "- telemetry_console() for local development\n";
echo "- telemetry_jaeger() for Jaeger backend\n";
echo "- telemetry_zipkin() for Zipkin backend\n";
echo "- telemetry_otlp() for custom OTLP collectors\n";
echo "- telemetry() for advanced configuration\n";
echo "- Use headers for authentication (Honeycomb, New Relic, etc.)\n";
echo "- OTLP collector can fan out to multiple backends\n";
echo "- Sampling reduces costs in high-traffic scenarios\n";
echo "- Configure based on environment (local/dev/staging/prod)\n";
