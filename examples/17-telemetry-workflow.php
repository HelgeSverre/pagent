<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Telemetry: Multi-Agent Workflow ===\n\n";

// Enable console telemetry to see the workflow spans
telemetry_console(verbose: true);

echo "Telemetry enabled with console exporter\n";
echo "This example demonstrates distributed tracing across multiple agents\n\n";

// Create multiple agents for content creation workflow
echo "=== Setting up Content Creation Workflow ===\n\n";

agent('researcher')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a research assistant. Provide factual information and key points about topics.')
    ->telemetry(true);

agent('writer')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a content writer. Write clear, concise summaries based on research.')
    ->telemetry(true);

agent('editor')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are an editor. Polish and improve writing for clarity and impact.')
    ->telemetry(true);

echo "Created agents: researcher, writer, editor\n";
echo "All agents have telemetry enabled\n\n";

// Example 1: Simple pipeline
echo "=== Example 1: Content Creation Pipeline ===\n\n";

$result = pipeline('content-creation')
    ->step('research', agent('researcher'))
    ->step('write', agent('writer'))
    ->step('edit', agent('editor'))
    ->run('Write a brief summary about OpenTelemetry');

echo "Topic: Write a brief summary about OpenTelemetry\n\n";
echo "Final result:\n{$result}\n\n";

echo "--- Telemetry Output Above ---\n";
echo "You should see a hierarchy of spans:\n";
echo "  workflow.pipeline (parent)\n";
echo "    └─ workflow.step: research\n";
echo "       └─ agent.prompt: researcher\n";
echo "          └─ llm.request\n";
echo "    └─ workflow.step: write\n";
echo "       └─ agent.prompt: writer\n";
echo "          └─ llm.request\n";
echo "    └─ workflow.step: edit\n";
echo "       └─ agent.prompt: editor\n";
echo "          └─ llm.request\n\n";

// Example 2: Pipeline with tools
echo "=== Example 2: Research Pipeline with Tools ===\n\n";

agent('fact-checker')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a fact checker. Verify information and check data.')
    ->telemetry(true)
    ->tool('check_date', 'Check if a date is valid', function (string $date): array {
        try {
            $dt = new DateTime($date);

            return ['valid' => true, 'parsed' => $dt->format('Y-m-d')];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    })
    ->tool('count_words', 'Count words in text', fn (string $text): int => str_word_count($text));

$result = pipeline('research-workflow')
    ->step('research', agent('researcher'))
    ->step('fact-check', agent('fact-checker'))
    ->step('write', agent('writer'))
    ->run('Research the history of PHP programming language and verify key dates');

echo "Topic: Research the history of PHP programming language\n\n";
echo "Final result:\n{$result}\n\n";

echo "--- Telemetry Output Above ---\n";
echo "You should see tool.execute spans within the fact-checker step\n";
echo "Tool spans show:\n";
echo "  - tool.name attribute\n";
echo "  - tool.arguments (JSON encoded)\n";
echo "  - tool.result_type\n";
echo "  - Duration and status\n\n";

// Example 3: Parallel agents (simulated with multiple pipelines)
echo "=== Example 3: Multiple Independent Workflows ===\n\n";

echo "Running two independent workflows...\n\n";

// First workflow
$result1 = pipeline('tech-content')
    ->step('research', agent('researcher'))
    ->step('write', agent('writer'))
    ->run('Explain what Laravel is');

echo "Workflow 1 (tech-content): Explain what Laravel is\n";
echo "Result: {$result1}\n\n";

// Second workflow
$result2 = pipeline('science-content')
    ->step('research', agent('researcher'))
    ->step('write', agent('writer'))
    ->run('Explain photosynthesis');

echo "Workflow 2 (science-content): Explain photosynthesis\n";
echo "Result: {$result2}\n\n";

echo "--- Telemetry Output Above ---\n";
echo "You should see two separate workflow.pipeline root spans\n";
echo "Each workflow has its own trace tree\n";
echo "workflow.name attribute distinguishes them\n\n";

echo "✅ Multi-agent workflow telemetry completed!\n\n";
echo "Key points:\n";
echo "- Pipeline creates workflow.pipeline root span\n";
echo "- Each step creates workflow.step child span\n";
echo "- Agent operations create agent.prompt spans\n";
echo "- Tool executions create tool.execute spans\n";
echo "- Parent-child relationships show execution flow\n";
echo "- Duration tracking shows performance bottlenecks\n";
echo "- Use Jaeger/Zipkin to visualize complex workflows\n";
