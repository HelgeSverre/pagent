<?php

declare(strict_types=1);

use Pagent\Events\Events\Guard\GuardCheckingEvent;
use Pagent\Events\Events\Guard\GuardFallbackEvent;
use Pagent\Events\Events\Guard\GuardPassedEvent;
use Pagent\Events\Events\Guard\GuardViolatedEvent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;
use Pagent\Events\Events\Memory\MemoryLoadedEvent;
use Pagent\Events\Events\Memory\MemoryLoadingEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Events\Events\Stream\StreamStartedEvent;
use Pagent\Events\Events\Tool\ToolErrorEvent;
use Pagent\Events\Events\Tool\ToolExecutedEvent;
use Pagent\Events\Events\Tool\ToolExecutingEvent;
use Pagent\Observability\Exporters\InMemoryExporter;
use Pagent\Observability\TelemetryEventBridge;
use Pagent\Observability\TelemetryManager;

beforeEach(function () {
    // Reset telemetry state
    TelemetryManager::instance()->clearContext();

    // Use InMemoryExporter for testing
    $this->exporter = new InMemoryExporter;
    TelemetryManager::instance()->initialize(['enabled' => true])
        ->setExporter($this->exporter);
});

afterEach(function () {
    TelemetryManager::instance()->shutdown();
});

test('bridge listens to LLM events when trace_llm is enabled', function () {
    $bridge = new TelemetryEventBridge(['trace_llm' => true]);

    $events = $bridge->listensTo();

    expect($events)->toContain('before_llm_request')
        ->and($events)->toContain('after_llm_response');
});

test('bridge does not listen to LLM events when trace_llm is disabled', function () {
    $bridge = new TelemetryEventBridge(['trace_llm' => false]);

    $events = $bridge->listensTo();

    expect($events)->not->toContain('before_llm_request')
        ->and($events)->not->toContain('after_llm_response');
});

test('bridge creates LLM span from before/after events', function () {
    $bridge = new TelemetryEventBridge;

    // Create an actual Agent instance by using the internal Agent constructor
    // We can't use Registry::get() because AgentBuilder hasn't registered it yet
    $agentInstance = new \Pagent\Agent('test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    // Fire before event
    $beforeEvent = new BeforeLLMRequestEvent(
        $agentInstance,
        'anthropic',
        'claude-3-opus',
        ['temperature' => 0.7, 'max_tokens' => 1000]
    );
    $bridge->handle($beforeEvent);

    // Verify span is stored
    expect($bridge->getActiveSpanCount())->toBe(1);

    // Fire after event with response data
    $afterEvent = new AfterLLMResponseEvent(
        $agentInstance,
        'anthropic',
        'claude-3-opus',
        [
            'model' => 'claude-3-opus-20240229',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 25,
                'total_tokens' => 35,
            ],
        ],
        123.45
    );
    $bridge->handle($afterEvent);

    // Verify span is completed and removed
    expect($bridge->getActiveSpanCount())->toBe(0);

    // Verify span was exported with correct attributes
    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $span = $spans[0];
    expect($span->getName())->toBe('llm.request');

    $attributes = $span->getAttributes()->toArray();
    expect($attributes)->toHaveKey('gen_ai.system', 'anthropic')
        ->and($attributes)->toHaveKey('gen_ai.request.model', 'claude-3-opus')
        ->and($attributes)->toHaveKey('gen_ai.request.temperature', 0.7)
        ->and($attributes)->toHaveKey('gen_ai.request.max_tokens', 1000)
        ->and($attributes)->toHaveKey('gen_ai.response.model', 'claude-3-opus-20240229')
        ->and($attributes)->toHaveKey('gen_ai.usage.input_tokens', 10)
        ->and($attributes)->toHaveKey('gen_ai.usage.output_tokens', 25)
        ->and($attributes)->toHaveKey('gen_ai.usage.total_tokens', 35)
        ->and($attributes)->toHaveKey('llm.duration_ms', 123.45);
});

test('bridge respects telemetry enabled flag', function () {
    $bridge = new TelemetryEventBridge(['enabled' => false]);
    $agentInstance = new \Pagent\Agent('test-agent-2');
    $agentInstance->provider(mock())->telemetry(true);

    $beforeEvent = new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []);
    $bridge->handle($beforeEvent);

    // No span should be created when bridge is disabled
    expect($bridge->getActiveSpanCount())->toBe(0);
});

test('bridge respects TelemetryManager enabled state', function () {
    // Disable telemetry manager
    TelemetryManager::instance()->initialize(['enabled' => false]);

    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('test-agent-3');
    $agentInstance->provider(mock())->telemetry(true);

    $beforeEvent = new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []);
    $bridge->handle($beforeEvent);

    // No span should be created when TelemetryManager is disabled
    expect($bridge->getActiveSpanCount())->toBe(0);
});

test('bridge handles missing after event gracefully', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('test-agent-4');
    $agentInstance->provider(mock())->telemetry(true);

    // Fire before event
    $beforeEvent = new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []);
    $bridge->handle($beforeEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    // Clear spans without firing after event
    $bridge->clearActiveSpans();

    expect($bridge->getActiveSpanCount())->toBe(0);
});

test('bridge handles anthropic cache tokens', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('test-agent-5');
    $agentInstance->provider(mock())->telemetry(true);

    $beforeEvent = new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []);
    $bridge->handle($beforeEvent);

    $afterEvent = new AfterLLMResponseEvent(
        $agentInstance,
        'anthropic',
        'claude-3-opus',
        [
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cache_read_input_tokens' => 500,
                'cache_creation_input_tokens' => 600,
            ],
        ],
        100.0
    );
    $bridge->handle($afterEvent);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('gen_ai.usage.cache_read_input_tokens', 500)
        ->and($attributes)->toHaveKey('gen_ai.usage.cache_creation_input_tokens', 600);
});

test('telemetry_bridge helper function creates and registers bridge', function () {
    $bridge = telemetry_bridge();

    expect($bridge)->toBeInstanceOf(TelemetryEventBridge::class);

    // Verify it's listening to events
    expect($bridge->listensTo())->toContain('before_llm_request')
        ->and($bridge->listensTo())->toContain('after_llm_response');
});

test('bridge listens to Tool events when trace_tools is enabled', function () {
    $bridge = new TelemetryEventBridge(['trace_tools' => true]);

    $events = $bridge->listensTo();

    expect($events)->toContain('tool_executing')
        ->and($events)->toContain('tool_executed')
        ->and($events)->toContain('tool_error');
});

test('bridge does not listen to Tool events when trace_tools is disabled', function () {
    $bridge = new TelemetryEventBridge(['trace_tools' => false]);

    $events = $bridge->listensTo();

    expect($events)->not->toContain('tool_executing')
        ->and($events)->not->toContain('tool_executed')
        ->and($events)->not->toContain('tool_error');
});

test('bridge creates Tool span from executing/executed events', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('tool-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    // Fire tool executing event
    $executingEvent = new ToolExecutingEvent(
        $agentInstance,
        'test_tool',
        ['param1' => 'value1']
    );
    $bridge->handle($executingEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    // Fire tool executed event
    $executedEvent = new ToolExecutedEvent(
        $agentInstance,
        'test_tool',
        ['param1' => 'value1'],
        'tool result',
        50.5
    );
    $bridge->handle($executedEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('tool.name', 'test_tool')
        ->and($attributes)->toHaveKey('tool.duration_ms', 50.5)
        ->and($attributes)->toHaveKey('tool.status', 'success');
});

test('bridge creates Tool span with error', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('tool-error-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $executingEvent = new ToolExecutingEvent($agentInstance, 'failing_tool', []);
    $bridge->handle($executingEvent);

    $errorEvent = new ToolErrorEvent(
        $agentInstance,
        'failing_tool',
        [],
        new \Exception('Tool failed')
    );
    $bridge->handle($errorEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('tool.status', 'error')
        ->and($attributes)->toHaveKey('tool.error.type', 'Exception')
        ->and($attributes)->toHaveKey('tool.error.message', 'Tool failed');
});

test('bridge listens to Guard events when trace_guards is enabled', function () {
    $bridge = new TelemetryEventBridge(['trace_guards' => true]);

    $events = $bridge->listensTo();

    expect($events)->toContain('guard_checking')
        ->and($events)->toContain('guard_passed')
        ->and($events)->toContain('guard_violated')
        ->and($events)->toContain('guard_fallback');
});

test('bridge creates Guard span for passed guard', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('guard-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $checkingEvent = new GuardCheckingEvent($agentInstance, 'test_guard', 'test content');
    $bridge->handle($checkingEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    $passedEvent = new GuardPassedEvent($agentInstance, 'test_guard', 'test content');
    $bridge->handle($passedEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('guard.name', 'test_guard')
        ->and($attributes)->toHaveKey('guard.result', 'passed');
});

test('bridge creates Guard span for violated guard', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('guard-violated-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $checkingEvent = new GuardCheckingEvent($agentInstance, 'strict_guard', 'bad content');
    $bridge->handle($checkingEvent);

    $violatedEvent = new GuardViolatedEvent($agentInstance, 'strict_guard', 'bad content', 'contains prohibited words');
    $bridge->handle($violatedEvent);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('guard.result', 'violated')
        ->and($attributes)->toHaveKey('guard.violation_reason', 'contains prohibited words');
});

test('bridge creates Guard span for fallback guard', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('guard-fallback-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $checkingEvent = new GuardCheckingEvent($agentInstance, 'optional_guard', 'content');
    $bridge->handle($checkingEvent);

    $fallbackEvent = new GuardFallbackEvent($agentInstance, 'optional_guard', 2, 3);
    $bridge->handle($fallbackEvent);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('guard.result', 'fallback')
        ->and($attributes)->toHaveKey('guard.attempt_number', 2)
        ->and($attributes)->toHaveKey('guard.max_attempts', 3);
});

test('bridge listens to Memory events when trace_memory is enabled', function () {
    $bridge = new TelemetryEventBridge(['trace_memory' => true]);

    $events = $bridge->listensTo();

    expect($events)->toContain('memory_loading')
        ->and($events)->toContain('memory_loaded');
});

test('bridge creates Memory span for load operation', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('memory-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $loadingEvent = new MemoryLoadingEvent($agentInstance, 'user_data', 'session');
    $bridge->handle($loadingEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    $loadedEvent = new MemoryLoadedEvent($agentInstance, 'user_data', ['name' => 'John'], 'session');
    $bridge->handle($loadedEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('memory.operation', 'load')
        ->and($attributes)->toHaveKey('memory.key', 'user_data')
        ->and($attributes)->toHaveKey('memory.namespace', 'session')
        ->and($attributes)->toHaveKey('memory.loaded', true)
        ->and($attributes)->toHaveKey('memory.value_type', 'array');
});

test('bridge listens to Stream events when trace_streams is enabled', function () {
    $bridge = new TelemetryEventBridge(['trace_streams' => true]);

    $events = $bridge->listensTo();

    expect($events)->toContain('stream_started')
        ->and($events)->toContain('stream_completed');
});

test('bridge creates Stream span from started/completed events', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new \Pagent\Agent('stream-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $startedEvent = new StreamStartedEvent($agentInstance, 'anthropic', 'claude-3-opus');
    $bridge->handle($startedEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    $completedEvent = new StreamCompletedEvent(
        $agentInstance,
        'Hello world from stream',
        5,
        250.75
    );
    $bridge->handle($completedEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('gen_ai.system', 'anthropic')
        ->and($attributes)->toHaveKey('gen_ai.request.model', 'claude-3-opus')
        ->and($attributes)->toHaveKey('stream.enabled', true)
        ->and($attributes)->toHaveKey('stream.total_chunks', 5)
        ->and($attributes)->toHaveKey('stream.duration_ms', 250.75)
        ->and($attributes)->toHaveKey('stream.content_length', 23);
});
