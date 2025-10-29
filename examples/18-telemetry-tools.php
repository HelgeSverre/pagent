<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Telemetry: Tool Execution Tracking ===\n\n";

// Enable verbose console telemetry to see all tool details
telemetry_console(verbose: true);

echo "Telemetry enabled with verbose console output\n";
echo "This example demonstrates detailed tool execution tracking\n\n";

// Example 1: File system tools
echo "=== Example 1: File System Tools ===\n\n";

agent('file-assistant')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a file system assistant. Use tools to interact with files.')
    ->telemetry(true)
    ->tool('file_exists', 'Check if file exists', function (string $path): bool {
        return file_exists($path);
    })
    ->tool('file_size', 'Get file size in bytes', function (string $path): int {
        if (! file_exists($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        return filesize($path);
    })
    ->tool('read_file', 'Read file contents', function (string $path, int $maxLength = 1000): string {
        if (! file_exists($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);

        return strlen($content) > $maxLength ? substr($content, 0, $maxLength).'...' : $content;
    });

echo "Q: Check if composer.json exists and tell me its size\n";
$response = agent('file-assistant')->prompt('Check if composer.json exists and tell me its size');
echo "A: {$response->content}\n\n";

echo "--- Tool Execution Spans Above ---\n";
echo "Each tool execution creates a tool.execute span with:\n";
echo "  - tool.name: The tool function name\n";
echo "  - tool.arguments: JSON-encoded arguments\n";
echo "  - tool.result_type: Return value type (bool, int, string, etc.)\n";
echo "  - Duration and status\n\n";

// Example 2: Data processing tools
echo "=== Example 2: Data Processing Tools ===\n\n";

agent('data-processor')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a data processing assistant.')
    ->telemetry(true)
    ->tool('analyze_text', 'Analyze text statistics', function (string $text): array {
        return [
            'length' => strlen($text),
            'words' => str_word_count($text),
            'lines' => substr_count($text, "\n") + 1,
            'chars_no_spaces' => strlen(preg_replace('/\s+/', '', $text)),
        ];
    })
    ->tool('transform_text', 'Transform text case', function (string $text, string $mode = 'upper'): string {
        return match ($mode) {
            'upper' => strtoupper($text),
            'lower' => strtolower($text),
            'title' => ucwords($text),
            default => $text,
        };
    })
    ->tool('extract_numbers', 'Extract all numbers from text', function (string $text): array {
        preg_match_all('/\d+/', $text, $matches);

        return array_map('intval', $matches[0]);
    });

$text = 'The quick brown fox jumps over 13 lazy dogs. There are 42 animals in total.';
echo "Q: Analyze this text and extract numbers: '{$text}'\n";
$response = agent('data-processor')->prompt("Analyze this text and extract numbers: '{$text}'");
echo "A: {$response->content}\n\n";

echo "--- Tool Execution Spans Above ---\n";
echo "Tool spans show the execution flow:\n";
echo "  1. analyze_text called with text argument\n";
echo "  2. extract_numbers called with text argument\n";
echo "  3. Each span shows duration and result type\n\n";

// Example 3: External API simulation
echo "=== Example 3: API Integration Tools ===\n\n";

agent('api-client')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are an API integration assistant.')
    ->telemetry(true)
    ->tool('fetch_user', 'Fetch user from API', function (int $userId): array {
        // Simulate API call with delay
        usleep(100000); // 100ms

        return [
            'id' => $userId,
            'name' => 'User '.$userId,
            'email' => "user{$userId}@example.com",
            'created_at' => date('Y-m-d H:i:s'),
        ];
    })
    ->tool('fetch_posts', 'Fetch user posts from API', function (int $userId, int $limit = 5): array {
        // Simulate API call with delay
        usleep(150000); // 150ms

        $posts = [];
        for ($i = 1; $i <= $limit; $i++) {
            $posts[] = [
                'id' => $i,
                'user_id' => $userId,
                'title' => "Post {$i}",
                'body' => "This is post {$i} by user {$userId}",
            ];
        }

        return $posts;
    });

echo "Q: Get user 42 and their posts\n";
$response = agent('api-client')->prompt('Get user 42 and their posts');
echo "A: {$response->content}\n\n";

echo "--- Tool Execution Spans Above ---\n";
echo "Timing information shows:\n";
echo "  - fetch_user took ~100ms\n";
echo "  - fetch_posts took ~150ms\n";
echo "  - Total duration visible in parent span\n";
echo "  - Useful for identifying slow tools\n\n";

// Example 4: Error handling in tools
echo "=== Example 4: Tool Error Handling ===\n\n";

agent('safe-calculator')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a safe calculator.')
    ->telemetry(true)
    ->tool('divide', 'Divide two numbers safely', function (float $a, float $b): float {
        if ($b === 0.0) {
            throw new RuntimeException('Division by zero error');
        }

        return $a / $b;
    })
    ->tool('sqrt', 'Calculate square root', function (float $n): float {
        if ($n < 0) {
            throw new RuntimeException('Cannot calculate square root of negative number');
        }

        return sqrt($n);
    });

echo "Q: What is 10 divided by 2?\n";
$response1 = agent('safe-calculator')->prompt('What is 10 divided by 2?');
echo "A: {$response1->content}\n\n";

echo "Q: What is 10 divided by 0?\n";
$response2 = agent('safe-calculator')->prompt('What is 10 divided by 0?');
echo "A: {$response2->content}\n\n";

echo "--- Tool Error Spans Above ---\n";
echo "Error handling visible in spans:\n";
echo "  - tool.execute span shows error status\n";
echo "  - tool.error attribute contains error message\n";
echo "  - Duration still tracked\n";
echo "  - Agent handles error gracefully\n\n";

// Example 5: Complex tool chain
echo "=== Example 5: Complex Tool Chain ===\n\n";

agent('workflow-agent')
    ->provider('anthropic')
    ->model('claude-3-5-sonnet-20241022')
    ->system('You are a workflow automation assistant.')
    ->telemetry(true)
    ->tool('step1_fetch', 'Fetch initial data', function (): array {
        usleep(50000);

        return ['status' => 'fetched', 'data' => ['a' => 1, 'b' => 2]];
    })
    ->tool('step2_process', 'Process data', function (array $data): array {
        usleep(75000);

        return ['status' => 'processed', 'result' => array_sum($data)];
    })
    ->tool('step3_validate', 'Validate result', function (int $result): array {
        usleep(25000);

        return ['valid' => $result > 0, 'result' => $result];
    });

echo "Q: Execute the workflow: fetch, process, and validate\n";
$response = agent('workflow-agent')->prompt('Execute the workflow: fetch, process, and validate');
echo "A: {$response->content}\n\n";

echo "--- Tool Chain Spans Above ---\n";
echo "Sequential tool execution visible:\n";
echo "  1. step1_fetch executed first (~50ms)\n";
echo "  2. step2_process executed next (~75ms)\n";
echo "  3. step3_validate executed last (~25ms)\n";
echo "  Total workflow duration visible in parent agent.prompt span\n\n";

echo "✅ Tool execution telemetry completed!\n\n";
echo "Key points:\n";
echo "- Every tool execution creates a tool.execute span\n";
echo "- Spans include tool.name and tool.arguments attributes\n";
echo "- tool.result_type shows the return value type\n";
echo "- Errors are captured in tool.error attribute\n";
echo "- Duration tracking helps identify slow tools\n";
echo "- Tool chains show sequential execution\n";
echo "- Use Jaeger/Zipkin to visualize tool dependencies\n";
