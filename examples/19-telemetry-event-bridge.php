<?php

declare(strict_types=1);
use Pagent\Observability\TelemetryEventBridge;

require __DIR__.'/../vendor/autoload.php';

/*
 * TelemetryEventBridge Example
 *
 * This example demonstrates how to use the TelemetryEventBridge to automatically
 * create OpenTelemetry spans from events, eliminating manual span creation.
 *
 * The bridge listens to agent events and creates corresponding telemetry spans,
 * making observability automatic and reducing boilerplate code.
 *
 * Supported event types:
 * - LLM operations (request/response)
 * - Tool execution (before/after/error)
 * - Guard checks (checking/passed/violated/fallback)
 * - Memory operations (loading/loaded)
 * - Stream operations (started/completed)
 */

// 1. Enable console telemetry for debugging
telemetry_console(verbose: true);

// 2. Register the telemetry bridge (automatic span creation from events)
$bridge = telemetry_bridge();

echo "[Pagent: Telemetry Event Bridge]\n";

// 3. Create an agent with a mock provider (no API key needed)
$agent = agent('demo-bot')
    ->provider(mock([
        (object) [
            'content' => 'Hello! I am an AI assistant powered by automatic telemetry.',
            'tokens' => 25,
            'model' => 'claude-sonnet-4-6',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 15,
                'total_tokens' => 25,
            ],
        ],
    ]))
    ->telemetry(true); // Enable telemetry for this agent

echo "Sending prompt to agent...\n\n";

// 4. Send a prompt - spans are automatically created from events!
$response = $agent->prompt('Hello, how are you?');

echo "Response: {$response->content}\n\n";

// 5. Check bridge status
echo "Bridge Statistics:\n";
echo "- Active spans: {$bridge->getActiveSpanCount()}\n";
echo '- Events listening to: '.implode(', ', $bridge->listensTo())."\n\n";

// 6. You can also configure which operations to trace
echo "Configuration Options:\n";
echo "You can create a custom bridge with selective tracing:\n\n";
echo "  new TelemetryEventBridge([\n";
echo "      'enabled' => true,         // Master switch (default: true)\n";
echo "      'trace_llm' => true,       // Trace LLM operations (default: true)\n";
echo "      'trace_tools' => true,     // Trace tool execution (default: true)\n";
echo "      'trace_memory' => true,    // Trace memory operations (default: true)\n";
echo "      'trace_guards' => true,    // Trace guard checks (default: true)\n";
echo "      'trace_streams' => true,   // Trace streaming (default: true)\n";
echo "  ]);\n\n";

// Example: LLM-only tracing
$llmOnlyBridge = new TelemetryEventBridge([
    'trace_llm' => true,
    'trace_tools' => false,
    'trace_memory' => false,
    'trace_guards' => false,
    'trace_streams' => false,
]);

echo 'LLM-only bridge listens to: '.implode(', ', $llmOnlyBridge->listensTo())."\n\n";

echo "TelemetryEventBridge automatically created spans for:\n";
echo "  - LLM request/response\n";
echo "  - Tool execution (when tools are used)\n";
echo "  - Guard checks (when guards are configured)\n";
echo "  - Memory operations (when memory is used)\n";
echo "  - Stream operations (when streaming is enabled)\n";
echo "  - Event data captured without manual span creation\n\n";

echo "Check the console output above to see the spans that were created.\n";
echo "\nDone.\n";
