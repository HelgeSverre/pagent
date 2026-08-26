<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Events\EventManager;
use Pagent\Events\Events\Guard\GuardCheckingEvent;
use Pagent\Events\Events\Guard\GuardFallbackEvent;
use Pagent\Events\Events\Guard\GuardPassedEvent;
use Pagent\Events\Events\Guard\GuardViolatedEvent;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Events\Events\LLM\BeforeLLMRequestEvent;
use Pagent\Events\Events\Mcp\McpConnectionEstablishedEvent;
use Pagent\Events\Events\Mcp\McpConnectionFailedEvent;
use Pagent\Events\Events\Mcp\McpToolCalledEvent;
use Pagent\Events\Events\Mcp\McpToolCallingEvent;
use Pagent\Events\Events\Mcp\McpToolErrorEvent;
use Pagent\Events\Events\Mcp\McpToolsDiscoveredEvent;
use Pagent\Events\Events\Mcp\McpToolsDiscoveringEvent;
use Pagent\Events\Events\Memory\MemoryLoadedEvent;
use Pagent\Events\Events\Memory\MemoryLoadingEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Events\Events\Stream\StreamStartedEvent;
use Pagent\Events\Events\Tool\ToolErrorEvent;
use Pagent\Events\Events\Tool\ToolExecutedEvent;
use Pagent\Events\Events\Tool\ToolExecutingEvent;
use Pagent\Exceptions\ConfigurationException;
use Pagent\Mcp\McpClient;
use Pagent\Mcp\Transports\StdioTransport;
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
    $agentInstance = new Agent('test-agent');
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
    $agentInstance = new Agent('test-agent-2');
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
    $agentInstance = new Agent('test-agent-3');
    $agentInstance->provider(mock())->telemetry(true);

    $beforeEvent = new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []);
    $bridge->handle($beforeEvent);

    // No span should be created when TelemetryManager is disabled
    expect($bridge->getActiveSpanCount())->toBe(0);
});

test('bridge handles missing after event gracefully', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new Agent('test-agent-4');
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
    $agentInstance = new Agent('test-agent-5');
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

test('global telemetry bridge registration is idempotent and resettable', function () {
    EventManager::reset();
    TelemetryEventBridge::resetGlobal();

    $first = TelemetryEventBridge::global();
    $second = TelemetryEventBridge::global();

    EventManager::reset();
    $rebound = TelemetryEventBridge::global();

    TelemetryEventBridge::resetGlobal();
    $replacement = TelemetryEventBridge::global();
    TelemetryEventBridge::resetGlobal();

    expect($second)->toBe($first)
        ->and($rebound)->toBe($first)
        ->and($replacement)->not->toBe($first);
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
    $agentInstance = new Agent('tool-test-agent');
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
    $agentInstance = new Agent('tool-error-test-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $executingEvent = new ToolExecutingEvent($agentInstance, 'failing_tool', []);
    $bridge->handle($executingEvent);

    $errorEvent = new ToolErrorEvent(
        $agentInstance,
        'failing_tool',
        [],
        new Exception('Tool failed')
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
    $agentInstance = new Agent('guard-test-agent');
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
    $agentInstance = new Agent('guard-violated-test-agent');
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
    $agentInstance = new Agent('guard-fallback-test-agent');
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
    $agentInstance = new Agent('memory-test-agent');
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
    $agentInstance = new Agent('stream-test-agent');
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

// MCP event tests
test('bridge listens to MCP events when trace_mcp is enabled', function () {
    $bridge = new TelemetryEventBridge(['trace_mcp' => true]);

    $events = $bridge->listensTo();

    expect($events)->toContain('mcp_connection_established')
        ->and($events)->toContain('mcp_connection_failed')
        ->and($events)->toContain('mcp_tools_discovering')
        ->and($events)->toContain('mcp_tools_discovered')
        ->and($events)->toContain('mcp_tool_calling')
        ->and($events)->toContain('mcp_tool_called')
        ->and($events)->toContain('mcp_tool_error');
});

test('bridge ignores MCP events when trace_mcp is disabled', function () {
    $bridge = new TelemetryEventBridge(['trace_mcp' => false]);

    $events = $bridge->listensTo();

    expect($events)->not->toContain('mcp_connection_established')
        ->and($events)->not->toContain('mcp_connection_failed')
        ->and($events)->not->toContain('mcp_tools_discovering')
        ->and($events)->not->toContain('mcp_tools_discovered')
        ->and($events)->not->toContain('mcp_tool_calling')
        ->and($events)->not->toContain('mcp_tool_called')
        ->and($events)->not->toContain('mcp_tool_error');
});

test('bridge creates span for successful connection', function () {
    $bridge = new TelemetryEventBridge;
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport, 'test-client', '1.0.0');

    $event = new McpConnectionEstablishedEvent(
        $client,
        'test-client',
        '1.0.0',
        ['tools' => true],
        ['name' => 'test-server', 'version' => '2.0.0'],
        123.45
    );

    $bridge->handle($event);

    // Span should be created and ended immediately
    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $span = $spans[0];
    expect($span->getName())->toBe('mcp.connection');

    $attributes = $span->getAttributes()->toArray();
    expect($attributes)->toHaveKey('mcp.client.name', 'test-client')
        ->and($attributes)->toHaveKey('mcp.client.version', '1.0.0')
        ->and($attributes)->toHaveKey('mcp.server.name', 'test-server')
        ->and($attributes)->toHaveKey('mcp.server.version', '2.0.0')
        ->and($attributes)->toHaveKey('mcp.duration_ms', 123.45);
});

test('bridge creates error span for failed connection', function () {
    $bridge = new TelemetryEventBridge;
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);
    $error = new RuntimeException('Connection failed');

    $event = new McpConnectionFailedEvent($client, 'test-client', '1.0.0', $error);

    $bridge->handle($event);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('mcp.error.type', 'RuntimeException')
        ->and($attributes)->toHaveKey('mcp.error.message', 'Connection failed');
});

test('bridge creates paired span for tool discovery', function () {
    $bridge = new TelemetryEventBridge;
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);

    $discoveringEvent = new McpToolsDiscoveringEvent($client);
    $bridge->handle($discoveringEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    $tools = [
        ['name' => 'calculator'],
        ['name' => 'weather'],
        ['name' => 'translator'],
    ];

    $discoveredEvent = new McpToolsDiscoveredEvent($client, $tools, 3, 45.67);
    $bridge->handle($discoveredEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('mcp.tools.count', 3)
        ->and($attributes)->toHaveKey('mcp.duration_ms', 45.67);
});

test('bridge creates paired span for tool call success', function () {
    $bridge = new TelemetryEventBridge;
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);

    $callingEvent = new McpToolCallingEvent($client, 'calculator', ['a' => 5, 'b' => 3]);
    $bridge->handle($callingEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    $calledEvent = new McpToolCalledEvent(
        $client,
        'calculator',
        ['a' => 5, 'b' => 3],
        ['answer' => 8],
        23.45
    );
    $bridge->handle($calledEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $span = $spans[0];
    expect($span->getName())->toBe('mcp.tool.call');

    $attributes = $span->getAttributes()->toArray();
    expect($attributes)->toHaveKey('mcp.tool.name', 'calculator')
        ->and($attributes)->toHaveKey('mcp.tool.duration_ms', 23.45)
        ->and($attributes)->toHaveKey('mcp.tool.status', 'success');
});

test('bridge creates error span for tool call failure', function () {
    $bridge = new TelemetryEventBridge;
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);

    $callingEvent = new McpToolCallingEvent($client, 'calculator', ['a' => 5]);
    $bridge->handle($callingEvent);

    $error = new RuntimeException('Division by zero');
    $errorEvent = new McpToolErrorEvent($client, 'calculator', ['a' => 5], $error);
    $bridge->handle($errorEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $attributes = $spans[0]->getAttributes()->toArray();
    expect($attributes)->toHaveKey('mcp.tool.status', 'error')
        ->and($attributes)->toHaveKey('mcp.tool.error.type', 'RuntimeException')
        ->and($attributes)->toHaveKey('mcp.tool.error.message', 'Division by zero');
});

test('bridge handles orphaned MCP discovering event', function () {
    $bridge = new TelemetryEventBridge;
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);

    $discoveringEvent = new McpToolsDiscoveringEvent($client);
    $bridge->handle($discoveringEvent);

    expect($bridge->getActiveSpanCount())->toBe(1);

    // Clear active spans without firing discovered event
    $bridge->clearActiveSpans();

    // Verify no exceptions thrown
    expect($bridge->getActiveSpanCount())->toBe(0);
});

test('bridge respects TelemetryManager disabled for MCP events', function () {
    TelemetryManager::instance()->initialize(['enabled' => false]);

    $bridge = new TelemetryEventBridge;
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);

    $event = new McpConnectionEstablishedEvent(
        $client,
        'test-client',
        '1.0.0',
        [],
        [],
        1.0
    );

    $bridge->handle($event);

    // No spans should be created when TelemetryManager is disabled
    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(0);
});

test('bridge respects enabled=false for MCP events', function () {
    $bridge = new TelemetryEventBridge(['enabled' => false, 'trace_mcp' => true]);
    $transport = new StdioTransport('test-command');
    $client = new McpClient($transport);

    $event = new McpConnectionEstablishedEvent(
        $client,
        'test-client',
        '1.0.0',
        [],
        [],
        1.0
    );

    $bridge->handle($event);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(0);
});

test('bridge handles multiple concurrent MCP clients', function () {
    $bridge = new TelemetryEventBridge;
    $transport1 = new StdioTransport('test-command-1');
    $transport2 = new StdioTransport('test-command-2');
    $client1 = new McpClient($transport1);
    $client2 = new McpClient($transport2);

    // Start discovering for both clients
    $bridge->handle(new McpToolsDiscoveringEvent($client1));
    $bridge->handle(new McpToolsDiscoveringEvent($client2));

    expect($bridge->getActiveSpanCount())->toBe(2);

    // Complete discovering for both
    $bridge->handle(new McpToolsDiscoveredEvent($client1, [], 0, 1.0));
    $bridge->handle(new McpToolsDiscoveredEvent($client2, [], 0, 2.0));

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(2);
});

test('bridge span keys use client object ID', function () {
    $bridge = new TelemetryEventBridge;
    $transport1 = new StdioTransport('test-command-1');
    $transport2 = new StdioTransport('test-command-2');
    $client1 = new McpClient($transport1);
    $client2 = new McpClient($transport2);

    // Start tool calls for both clients with same tool name
    $bridge->handle(new McpToolCallingEvent($client1, 'calculator', []));
    $bridge->handle(new McpToolCallingEvent($client2, 'calculator', []));

    // Both spans should be active (different clients)
    expect($bridge->getActiveSpanCount())->toBe(2);

    // Complete both tool calls
    $bridge->handle(new McpToolCalledEvent($client1, 'calculator', [], [], 1.0));
    $bridge->handle(new McpToolCalledEvent($client2, 'calculator', [], [], 2.0));

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();

    $spans = $this->exporter->getSpans();
    expect($spans)->toHaveCount(2);
});

test('bridge skips spans for agents with telemetry disabled', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new Agent('telemetry-disabled-agent');
    $agentInstance->provider(mock())->telemetry(false);

    $beforeEvent = new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []);
    $bridge->handle($beforeEvent);

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();
    expect($this->exporter->getSpans())->toHaveCount(0);
});

test('bridge keeps separate spans for same-named agent instances', function () {
    $bridge = new TelemetryEventBridge;

    $agentA = new Agent('twin-agent');
    $agentA->provider(mock())->telemetry(true);
    $agentB = new Agent('twin-agent');
    $agentB->provider(mock())->telemetry(true);

    $bridge->handle(new BeforeLLMRequestEvent($agentA, 'anthropic', 'claude-3-opus', []));
    $bridge->handle(new BeforeLLMRequestEvent($agentB, 'anthropic', 'claude-3-opus', []));

    // Same name, different instances: two active spans, no clobbering
    expect($bridge->getActiveSpanCount())->toBe(2);

    $bridge->handle(new AfterLLMResponseEvent($agentA, 'anthropic', 'claude-3-opus', [], 1.0));
    $bridge->handle(new AfterLLMResponseEvent($agentB, 'anthropic', 'claude-3-opus', [], 2.0));

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();
    expect($this->exporter->getSpans())->toHaveCount(2);
});

test('bridge ends and evicts stale span when a start event repeats without an end', function () {
    $bridge = new TelemetryEventBridge;
    $agentInstance = new Agent('repeat-start-agent');
    $agentInstance->provider(mock())->telemetry(true);

    $bridge->handle(new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []));
    $bridge->handle(new BeforeLLMRequestEvent($agentInstance, 'anthropic', 'claude-3-opus', []));

    // The orphaned first span was ended and evicted, not leaked
    expect($bridge->getActiveSpanCount())->toBe(1);

    $bridge->handle(new AfterLLMResponseEvent($agentInstance, 'anthropic', 'claude-3-opus', [], 1.0));

    expect($bridge->getActiveSpanCount())->toBe(0);

    TelemetryManager::instance()->shutdown();
    expect($this->exporter->getSpans())->toHaveCount(2);
});

test('global bridge throws when reconfigured without reset', function () {
    TelemetryEventBridge::resetGlobal();
    TelemetryEventBridge::global();

    expect(fn () => TelemetryEventBridge::global(['trace_llm' => false]))
        ->toThrow(ConfigurationException::class, 'resetGlobal');

    TelemetryEventBridge::resetGlobal();
});
