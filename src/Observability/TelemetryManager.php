<?php

declare(strict_types=1);

namespace Pagent\Observability;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Pagent\Exceptions\InvalidArgumentException;
use Pagent\Observability\Exporters\ConsoleExporter;
use Pagent\Observability\Exporters\ExporterInterface;
use Pagent\Observability\Exporters\InMemoryExporter;
use Pagent\Observability\Exporters\JaegerExporter;
use Pagent\Observability\Exporters\OTLPExporter;
use Pagent\Observability\Exporters\ZipkinExporter;
use Psr\Log\LoggerInterface;
use Throwable;

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
            // Workflow/tool bodies may contain credentials or personal data.
            // Content export is therefore explicit rather than implied by tracing.
            'capture_content' => false,
        ], $config);

        $this->enabled = (bool) $this->config['enabled'];

        if (isset($this->config['logger']) && ! $this->config['logger'] instanceof LoggerInterface) {
            throw new InvalidArgumentException('Telemetry logger must implement Psr\\Log\\LoggerInterface');
        }

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

    /**
     * Return content for a span only when explicitly enabled. An optional
     * content_redactor callable can scrub the value before it leaves process.
     */
    public function contentForSpan(mixed $value): ?string
    {
        if (($this->config['capture_content'] ?? false) !== true) {
            return null;
        }

        $content = is_string($value)
            ? $value
            : (json_encode($value) ?: get_debug_type($value));
        $redactor = $this->config['content_redactor'] ?? null;
        if (is_callable($redactor)) {
            try {
                $redacted = $redactor($content);
                $content = is_string($redacted) ? $redacted : '[redacted]';
            } catch (Throwable) {
                $content = '[redacted]';
            }
        }

        return mb_substr($content, 0, 1000);
    }

    public function startSpan(string $name, array $attributes = []): Span|NullSpan
    {
        if (! $this->enabled || $this->tracer === null || $name === '') {
            return new NullSpan;
        }

        /** @phpstan-ignore argument.type */
        $builder = $this->tracer->spanBuilder($name);

        // Explicitly set parent from current context to ensure proper span hierarchy
        $builder->setParent(Context::getCurrent());

        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }

        $otelSpan = $builder->startSpan();

        // Activate the span in context for children and save scope for later detachment
        $scope = Context::storage()->attach($otelSpan->storeInContext(Context::getCurrent()));

        return new Span($otelSpan, $scope);
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
        $serialized = json_encode($arguments) ?: get_debug_type($arguments);
        $defaultAttributes = [
            'tool.name' => $toolName,
            'tool.arguments.size' => strlen($serialized),
        ];
        $content = $this->contentForSpan($arguments);
        if ($content !== null) {
            $defaultAttributes['tool.arguments'] = $content;
        }

        return $this->startSpan(
            'tool.execute',
            array_merge($defaultAttributes, $attributes)
        );
    }

    public function startGuardSpan(string $guardName, array $attributes = []): Span|NullSpan
    {
        $defaultAttributes = [
            'guard.name' => $guardName,
        ];

        return $this->startSpan(
            'guard.check',
            array_merge($defaultAttributes, $attributes)
        );
    }

    public function startMemorySpan(string $operation, string $key, ?string $namespace = null, array $attributes = []): Span|NullSpan
    {
        $defaultAttributes = [
            'memory.operation' => $operation,
            'memory.key' => $key,
        ];

        if ($namespace !== null) {
            $defaultAttributes['memory.namespace'] = $namespace;
        }

        return $this->startSpan(
            "memory.{$operation}",
            array_merge($defaultAttributes, $attributes)
        );
    }

    public function startStreamSpan(string $provider, string $model, array $attributes = []): Span|NullSpan
    {
        $defaultAttributes = [
            'gen_ai.system' => $provider,
            'gen_ai.request.model' => $model,
            'stream.enabled' => true,
        ];

        return $this->startSpan(
            'llm.stream',
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
                Attributes::create([
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
            'memory', 'inmemory' => $this->createInMemoryExporter(),
            'otlp' => new OTLPExporter($this->config['otlp'] ?? []),
            'jaeger' => new JaegerExporter($this->config['jaeger'] ?? []),
            'zipkin' => new ZipkinExporter(array_merge(
                $this->config['zipkin'] ?? [],
                ['service_name' => $this->config['service_name']]
            ), logger: $this->config['logger'] ?? null),
            default => throw new InvalidArgumentException("Unknown exporter: {$exporterType}"),
        };
    }

    private function createInMemoryExporter(): ExporterInterface
    {
        $exporter = $this->config['inmemory']['instance'] ?? null;
        if ($exporter === null) {
            return new InMemoryExporter;
        }
        if (! $exporter instanceof ExporterInterface) {
            throw new InvalidArgumentException('inmemory.instance must implement ExporterInterface');
        }

        return $exporter;
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->shutdown();
            self::$instance = null;
        }
    }
}
