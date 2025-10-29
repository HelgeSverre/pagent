<?php

declare(strict_types=1);

namespace Pagent\Observability;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Pagent\Observability\Exporters\ConsoleExporter;
use Pagent\Observability\Exporters\ExporterInterface;
use Pagent\Observability\Exporters\JaegerExporter;
use Pagent\Observability\Exporters\OTLPExporter;
use Pagent\Observability\Exporters\ZipkinExporter;

final class TelemetryManager
{
    private static ?self $instance = null;

    private bool $enabled = false;

    private ?TracerProviderInterface $tracerProvider = null;

    private ?TracerInterface $tracer = null;

    private array $config = [];

    private ?ExporterInterface $customExporter = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function initialize(array $config): self
    {
        $this->config = array_merge([
            'enabled' => true,
            'service_name' => 'pagent',
            'service_version' => '0.7.0',
            'exporter' => 'console',
            'sampling_rate' => 1.0,
        ], $config);

        $this->enabled = (bool) $this->config['enabled'];

        if ($this->enabled) {
            $this->setupTracerProvider();
        }

        return $this;
    }

    /**
     * Set a custom exporter (useful for testing).
     */
    public function setExporter(ExporterInterface $exporter): self
    {
        $this->customExporter = $exporter;

        if ($this->enabled) {
            $this->setupTracerProvider();
        }

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function startSpan(string $name, array $attributes = []): Span|NullSpan
    {
        if (! $this->enabled || $this->tracer === null || $name === '') {
            return new NullSpan;
        }

        /** @phpstan-ignore argument.type */
        $builder = $this->tracer->spanBuilder($name);

        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }

        $otelSpan = $builder->startSpan();

        Context::storage()->attach($otelSpan->storeInContext(Context::getCurrent()));

        return new Span($otelSpan);
    }

    public function startAgentSpan(string $operation, string $agentName, array $attributes = []): Span|NullSpan
    {
        $defaultAttributes = [
            'agent.name' => $agentName,
            'agent.operation' => $operation,
        ];

        return $this->startSpan(
            "agent.{$operation}",
            array_merge($defaultAttributes, $attributes)
        );
    }

    public function startLLMSpan(string $provider, string $model, array $attributes = []): Span|NullSpan
    {
        $defaultAttributes = [
            'gen_ai.system' => $provider,
            'gen_ai.request.model' => $model,
            'gen_ai.operation.name' => 'chat',
        ];

        return $this->startSpan(
            'llm.request',
            array_merge($defaultAttributes, $attributes)
        );
    }

    public function startToolSpan(string $toolName, array $arguments, array $attributes = []): Span|NullSpan
    {
        $defaultAttributes = [
            'tool.name' => $toolName,
            'tool.arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
        ];

        return $this->startSpan(
            'tool.execute',
            array_merge($defaultAttributes, $attributes)
        );
    }

    public function clearContext(): void
    {
        if ($this->enabled) {
            // Clear the current context by creating a new root context
            $scope = Context::storage()->scope();
            if ($scope !== null) {
                $scope->detach();
            }
        }
    }

    public function shutdown(): void
    {
        if ($this->tracerProvider instanceof TracerProvider) {
            $this->tracerProvider->shutdown();
        }

        $this->enabled = false;
        $this->tracerProvider = null;
        $this->tracer = null;
    }

    private function setupTracerProvider(): void
    {
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(
                \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                    ResourceAttributes::SERVICE_NAME => $this->config['service_name'],
                    ResourceAttributes::SERVICE_VERSION => $this->config['service_version'],
                ])
            )
        );

        $exporter = $this->createExporter();

        $this->tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($exporter))
            ->setResource($resource)
            ->build();

        $this->tracer = $this->tracerProvider->getTracer('pagent', $this->config['service_version']);
    }

    private function createExporter(): ExporterInterface
    {
        // Use custom exporter if set (for testing)
        if ($this->customExporter !== null) {
            return $this->customExporter;
        }

        $exporterType = $this->config['exporter'] ?? 'console';

        return match ($exporterType) {
            'console' => new ConsoleExporter($this->config['verbose'] ?? false),
            'otlp' => new OTLPExporter($this->config['otlp'] ?? []),
            'jaeger' => new JaegerExporter($this->config['jaeger'] ?? []),
            'zipkin' => new ZipkinExporter($this->config['zipkin'] ?? []),
            default => throw new \InvalidArgumentException("Unknown exporter: {$exporterType}"),
        };
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->shutdown();
            self::$instance = null;
        }
    }
}
