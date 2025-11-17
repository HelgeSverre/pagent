# Chapter 11: Advanced Streaming Patterns

## What You'll Learn

By the end of this chapter, you'll be able to:
- Integrate tool calling with streaming responses
- Implement robust error recovery for streaming operations
- Control flow and backpressure in stream processing
- Handle multi-modal streaming content
- Optimize streaming performance for production use

**Prerequisites:** Chapters 6-7 (Tool Calling), Chapter 10 (Streaming Basics)
**Time Estimate:** 45 minutes
**Difficulty:** Advanced

## Understanding Advanced Streaming

While basic streaming handles text responses, real-world applications often require more sophisticated patterns. You need to handle tool calls during streaming, recover from network interruptions, manage memory usage with large streams, and process different content types simultaneously.

Let's explore these advanced patterns through practical implementations.

## Streaming with Tool Calling

One of the most powerful patterns combines streaming responses with tool execution. The agent can call tools while streaming, enabling dynamic content generation based on real-time data.

### Live Dashboard Updater

Here's a system that streams dashboard updates while fetching live data:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;
use function Pagent\anthropic;

// Define tools for fetching dashboard metrics
$tools = [
    [
        'name' => 'get_server_metrics',
        'description' => 'Fetch current server performance metrics',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'server_id' => ['type' => 'string'],
                'metric_type' => [
                    'type' => 'string',
                    'enum' => ['cpu', 'memory', 'disk', 'network'],
                ],
            ],
            'required' => ['server_id', 'metric_type'],
        ],
    ],
    [
        'name' => 'get_error_logs',
        'description' => 'Retrieve recent error logs',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'severity' => [
                    'type' => 'string',
                    'enum' => ['critical', 'error', 'warning'],
                ],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['severity'],
        ],
    ],
];

// Tool implementations
$toolHandlers = [
    'get_server_metrics' => function (array $args): array {
        // Simulate fetching metrics
        return [
            'server_id' => $args['server_id'],
            'metric' => $args['metric_type'],
            'value' => match($args['metric_type']) {
                'cpu' => rand(20, 95) . '%',
                'memory' => rand(40, 85) . '%',
                'disk' => rand(30, 70) . '%',
                'network' => rand(100, 500) . ' Mbps',
            },
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    },
    'get_error_logs' => function (array $args): array {
        // Simulate fetching logs
        $logs = [];
        $limit = $args['limit'] ?? 5;

        for ($i = 0; $i < $limit; $i++) {
            $logs[] = [
                'severity' => $args['severity'],
                'message' => "Sample {$args['severity']} message #" . ($i + 1),
                'timestamp' => date('Y-m-d H:i:s', strtotime("-{$i} minutes")),
            ];
        }

        return $logs;
    },
];

// Create streaming dashboard updater
class DashboardStreamer
{
    private array $tools;
    private array $handlers;
    private array $buffer = [];
    private int $updateCount = 0;

    public function __construct(array $tools, array $handlers)
    {
        $this->tools = $tools;
        $this->handlers = $handlers;
    }

    public function streamDashboard(string $query): void
    {
        $agent = anthropic()
            ->tools($this->tools)
            ->stream(function ($chunk) {
                $this->processChunk($chunk);
            });

        $response = $agent->ask($query);

        // Flush any remaining buffer
        $this->flushBuffer();

        echo "\n\nDashboard update complete. Total updates: {$this->updateCount}\n";
    }

    private function processChunk(array $chunk): void
    {
        // Handle different chunk types
        if (isset($chunk['type'])) {
            switch ($chunk['type']) {
                case 'text':
                    $this->handleTextChunk($chunk);
                    break;

                case 'tool_use':
                    $this->handleToolCall($chunk);
                    break;

                case 'content_block_stop':
                    $this->flushBuffer();
                    break;
            }
        }
    }

    private function handleTextChunk(array $chunk): void
    {
        if (isset($chunk['text'])) {
            $this->buffer[] = $chunk['text'];

            // Flush buffer when we have a complete line
            if (strpos($chunk['text'], "\n") !== false) {
                $this->flushBuffer();
            }
        }
    }

    private function handleToolCall(array $chunk): void
    {
        if (!isset($chunk['name']) || !isset($this->handlers[$chunk['name']])) {
            return;
        }

        // Execute tool and inject result into stream
        $result = $this->handlers[$chunk['name']]($chunk['input'] ?? []);

        // Format and display tool result
        $this->flushBuffer();
        echo "\n[LIVE DATA] ";
        echo json_encode($result, JSON_PRETTY_PRINT);
        echo "\n";

        $this->updateCount++;
    }

    private function flushBuffer(): void
    {
        if (!empty($this->buffer)) {
            echo implode('', $this->buffer);
            $this->buffer = [];
        }
    }
}

// Use the dashboard streamer
$dashboardStreamer = new DashboardStreamer($tools, $toolHandlers);

$dashboardStreamer->streamDashboard(
    "Generate a server health dashboard showing CPU and memory for server-001,
     and any critical errors from the last hour. Update metrics every few paragraphs."
);
```

This pattern enables real-time data integration during streaming, perfect for live dashboards, monitoring systems, and dynamic reports.

## Error Recovery in Streams

Streaming operations can fail due to network issues, timeouts, or API errors. Implementing robust error recovery ensures your application continues functioning even when problems occur.

### Streaming Code Analyzer with Error Recovery

Here's a resilient code analyzer that handles streaming failures:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;
use function Pagent\openai;

class ResilientStreamAnalyzer
{
    private int $maxRetries = 3;
    private int $retryDelay = 1000; // milliseconds
    private array $checkpoints = [];
    private string $lastSuccessfulChunk = '';

    public function analyzeCode(string $code, string $language = 'php'): array
    {
        $analysis = [
            'issues' => [],
            'suggestions' => [],
            'metrics' => [],
            'processing_errors' => [],
        ];

        $retryCount = 0;
        $completed = false;

        while (!$completed && $retryCount < $this->maxRetries) {
            try {
                $this->streamAnalysis($code, $language, $analysis);
                $completed = true;
            } catch (\Exception $e) {
                $retryCount++;
                $analysis['processing_errors'][] = [
                    'attempt' => $retryCount,
                    'error' => $e->getMessage(),
                    'timestamp' => microtime(true),
                ];

                if ($retryCount < $this->maxRetries) {
                    $this->handleRetry($retryCount);
                } else {
                    $this->handleFinalFailure($analysis, $e);
                }
            }
        }

        return $analysis;
    }

    private function streamAnalysis(string $code, string $language, array &$analysis): void
    {
        $currentSection = '';
        $buffer = '';

        $agent = openai()
            ->stream(function ($chunk) use (&$currentSection, &$buffer, &$analysis) {
                try {
                    $this->processAnalysisChunk($chunk, $currentSection, $buffer, $analysis);
                } catch (\Exception $e) {
                    // Store partial progress
                    $this->createCheckpoint($currentSection, $buffer, $analysis);
                    throw $e;
                }
            })
            ->temperature(0.3);

        $prompt = $this->buildAnalysisPrompt($code, $language);

        // Resume from checkpoint if available
        if ($this->hasCheckpoint()) {
            $prompt .= $this->getCheckpointContext();
        }

        $response = $agent->ask($prompt);

        // Final buffer flush
        if (!empty($buffer)) {
            $this->parseAndStore($buffer, $currentSection, $analysis);
        }
    }

    private function processAnalysisChunk(
        array $chunk,
        string &$currentSection,
        string &$buffer,
        array &$analysis
    ): void {
        if (!isset($chunk['choices'][0]['delta']['content'])) {
            return;
        }

        $content = $chunk['choices'][0]['delta']['content'];
        $buffer .= $content;

        // Detect section markers
        if (preg_match('/^## (Issues|Suggestions|Metrics):/m', $buffer, $matches)) {
            // Save previous section
            if ($currentSection) {
                $this->parseAndStore($buffer, $currentSection, $analysis);
            }

            $currentSection = strtolower($matches[1]);
            $buffer = '';
        }

        // Store successful chunk for recovery
        $this->lastSuccessfulChunk = $content;
    }

    private function parseAndStore(string $content, string $section, array &$analysis): void
    {
        if (empty($section) || empty($content)) {
            return;
        }

        // Parse content based on section
        $lines = explode("\n", trim($content));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '- ') || str_starts_with($line, '* ')) {
                $analysis[$section][] = substr($line, 2);
            }
        }
    }

    private function createCheckpoint(string $section, string $buffer, array $analysis): void
    {
        $this->checkpoints[] = [
            'section' => $section,
            'buffer' => $buffer,
            'analysis_state' => $analysis,
            'timestamp' => microtime(true),
        ];
    }

    private function hasCheckpoint(): bool
    {
        return !empty($this->checkpoints);
    }

    private function getCheckpointContext(): string
    {
        $lastCheckpoint = end($this->checkpoints);

        return "\n\n[Resuming from checkpoint - Last section: {$lastCheckpoint['section']}]";
    }

    private function handleRetry(int $attempt): void
    {
        $delay = $this->retryDelay * pow(2, $attempt - 1); // Exponential backoff
        echo "Retrying (attempt {$attempt}/{$this->maxRetries}) after {$delay}ms...\n";
        usleep($delay * 1000);
    }

    private function handleFinalFailure(array &$analysis, \Exception $e): void
    {
        $analysis['status'] = 'partial';
        $analysis['final_error'] = $e->getMessage();

        // Attempt to salvage partial results from checkpoints
        if ($this->hasCheckpoint()) {
            $lastCheckpoint = end($this->checkpoints);
            $analysis = array_merge($analysis, $lastCheckpoint['analysis_state']);
            $analysis['recovered_from_checkpoint'] = true;
        }
    }

    private function buildAnalysisPrompt(string $code, string $language): string
    {
        return <<<PROMPT
        Analyze this {$language} code and provide:

        ## Issues:
        List any bugs, potential errors, or code smells.

        ## Suggestions:
        List improvements for performance, readability, or best practices.

        ## Metrics:
        List code metrics like complexity, line count, or function count.

        Code to analyze:
        ```{$language}
        {$code}
        ```

        Format each item as a bullet point.
        PROMPT;
    }
}

// Example usage with error injection
$analyzer = new ResilientStreamAnalyzer();

$code = <<<'PHP'
function calculateDiscount($price, $discount) {
    if ($discount > 100) {
        $discount = 100;
    }
    return $price * (1 - $discount / 100);
}

class ProductManager {
    private $products = [];

    public function addProduct($product) {
        $this->products[] = $product;
    }

    public function getProducts() {
        return $this->products;
    }
}
PHP;

$results = $analyzer->analyzeCode($code, 'php');

echo "Analysis Results:\n";
echo json_encode($results, JSON_PRETTY_PRINT);
```

This implementation provides checkpointing, exponential backoff, and partial result recovery—essential for production streaming applications.

## Flow Control and Backpressure

When processing large streams, you need to control the flow rate to prevent memory exhaustion and maintain system stability.

### Real-Time Translation System with Backpressure

Here's a translation system that manages flow control:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;
use function Pagent\anthropic;

class StreamTranslator
{
    private int $bufferSize = 1024; // bytes
    private int $maxMemory = 10485760; // 10MB
    private float $pauseThreshold = 0.8; // 80% memory usage
    private array $memorySnapshots = [];

    public function translateStream(
        string $text,
        string $targetLanguage,
        callable $onProgress = null
    ): string {
        $chunks = $this->splitIntoChunks($text);
        $translated = '';
        $stats = [
            'chunks_processed' => 0,
            'total_chunks' => count($chunks),
            'pauses' => 0,
            'memory_peaks' => [],
        ];

        foreach ($chunks as $index => $chunk) {
            // Check memory pressure
            if ($this->shouldPause()) {
                $this->handleBackpressure($stats);
            }

            // Process chunk with streaming
            $translatedChunk = $this->translateChunk(
                $chunk,
                $targetLanguage,
                $index,
                $stats
            );

            $translated .= $translatedChunk;
            $stats['chunks_processed']++;

            // Progress callback
            if ($onProgress) {
                $onProgress([
                    'progress' => ($index + 1) / count($chunks),
                    'translated_bytes' => strlen($translated),
                    'memory_usage' => memory_get_usage(true),
                    'stats' => $stats,
                ]);
            }
        }

        return $translated;
    }

    private function translateChunk(
        string $chunk,
        string $targetLanguage,
        int $index,
        array &$stats
    ): string {
        $buffer = '';
        $streamBuffer = '';

        $agent = anthropic()
            ->stream(function ($data) use (&$streamBuffer, &$buffer, &$stats) {
                // Monitor memory during streaming
                $memoryUsage = memory_get_usage(true);

                if ($memoryUsage > $this->maxMemory * $this->pauseThreshold) {
                    // Apply backpressure
                    usleep(100000); // 100ms pause
                    $stats['pauses']++;
                }

                if (isset($data['type']) && $data['type'] === 'content_block_delta') {
                    $streamBuffer .= $data['delta']['text'] ?? '';

                    // Flush when buffer is full
                    if (strlen($streamBuffer) >= $this->bufferSize) {
                        $buffer .= $streamBuffer;
                        $streamBuffer = '';

                        // Force garbage collection if needed
                        if ($memoryUsage > $this->maxMemory * 0.9) {
                            gc_collect_cycles();
                        }
                    }
                }
            })
            ->maxTokens(2048);

        $prompt = "Translate to {$targetLanguage} (chunk {$index}): {$chunk}";
        $agent->ask($prompt);

        // Flush remaining buffer
        $buffer .= $streamBuffer;

        return $buffer;
    }

    private function splitIntoChunks(string $text): array
    {
        // Smart chunking that respects sentence boundaries
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            if (strlen($currentChunk) + strlen($sentence) > $this->bufferSize) {
                if ($currentChunk) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $sentence;
            } else {
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
            }
        }

        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    private function shouldPause(): bool
    {
        $usage = memory_get_usage(true);
        $this->memorySnapshots[] = $usage;

        // Keep only recent snapshots
        if (count($this->memorySnapshots) > 10) {
            array_shift($this->memorySnapshots);
        }

        return $usage > ($this->maxMemory * $this->pauseThreshold);
    }

    private function handleBackpressure(array &$stats): void
    {
        $stats['memory_peaks'][] = memory_get_usage(true);

        echo "Applying backpressure - Memory usage high\n";

        // Pause and allow garbage collection
        gc_collect_cycles();
        usleep(500000); // 500ms pause

        // Wait for memory to decrease
        $attempts = 0;
        while ($this->shouldPause() && $attempts < 10) {
            usleep(100000); // 100ms
            $attempts++;
        }
    }
}

// Example with progress monitoring
$translator = new StreamTranslator();

$document = str_repeat(
    "This is a test document with multiple sentences. It contains various paragraphs.
     Each paragraph has important information. The translation should preserve meaning. ",
    50
);

$translated = $translator->translateStream(
    $document,
    'Spanish',
    function ($progress) {
        printf(
            "Progress: %.1f%% | Memory: %.2f MB | Pauses: %d\n",
            $progress['progress'] * 100,
            $progress['memory_usage'] / 1048576,
            $progress['stats']['pauses']
        );
    }
);

echo "\nTranslation complete. Length: " . strlen($translated) . " bytes\n";
```

## Progressive Report Generator

Let's combine all these patterns into a sophisticated report generator that streams content progressively while managing resources:

```php
<?php

declare(strict_types=1);

use Pagent\Pagent;
use function Pagent\anthropic;

class ProgressiveReportGenerator
{
    private array $sections = [];
    private array $completedSections = [];
    private float $startTime;

    public function generateReport(array $data, array $options = []): void
    {
        $this->startTime = microtime(true);

        // Define report sections
        $this->sections = [
            'executive_summary' => ['priority' => 1, 'max_tokens' => 500],
            'detailed_analysis' => ['priority' => 2, 'max_tokens' => 2000],
            'recommendations' => ['priority' => 3, 'max_tokens' => 1000],
            'appendices' => ['priority' => 4, 'max_tokens' => 1500],
        ];

        // Stream sections progressively
        foreach ($this->sections as $section => $config) {
            $this->streamSection($section, $data, $config, $options);
        }

        $this->finalize();
    }

    private function streamSection(
        string $section,
        array $data,
        array $config,
        array $options
    ): void {
        echo "\n" . str_repeat('=', 50) . "\n";
        echo strtoupper(str_replace('_', ' ', $section)) . "\n";
        echo str_repeat('=', 50) . "\n\n";

        $buffer = '';
        $wordCount = 0;
        $startTime = microtime(true);

        $agent = anthropic()
            ->stream(function ($chunk) use (&$buffer, &$wordCount, $section) {
                if (isset($chunk['type']) && $chunk['type'] === 'content_block_delta') {
                    $text = $chunk['delta']['text'] ?? '';

                    // Progressive display with formatting
                    echo $text;
                    flush();

                    $buffer .= $text;
                    $wordCount += str_word_count($text);

                    // Store progress
                    $this->updateProgress($section, $wordCount);
                }
            })
            ->maxTokens($config['max_tokens'])
            ->temperature($options['temperature'] ?? 0.7);

        $prompt = $this->buildSectionPrompt($section, $data);
        $agent->ask($prompt);

        // Mark section complete
        $this->completedSections[$section] = [
            'content' => $buffer,
            'word_count' => $wordCount,
            'duration' => microtime(true) - $startTime,
        ];

        echo "\n\n[Section complete - {$wordCount} words in " .
             round(microtime(true) - $startTime, 2) . "s]\n";
    }

    private function buildSectionPrompt(string $section, array $data): string
    {
        $dataJson = json_encode($data, JSON_PRETTY_PRINT);

        $prompts = [
            'executive_summary' =>
                "Write an executive summary based on this data.
                 Focus on key findings and high-level insights.
                 Data: {$dataJson}",

            'detailed_analysis' =>
                "Provide detailed analysis of the data, including trends,
                 patterns, and significant observations.
                 Data: {$dataJson}",

            'recommendations' =>
                "Based on the analysis, provide actionable recommendations.
                 Be specific and practical.
                 Data: {$dataJson}",

            'appendices' =>
                "Create appendices with supporting information,
                 methodologies, and additional context.
                 Data: {$dataJson}",
        ];

        return $prompts[$section] ?? "Generate content for {$section}: {$dataJson}";
    }

    private function updateProgress(string $section, int $wordCount): void
    {
        // Could emit to event system or update UI
        $progress = [
            'section' => $section,
            'words' => $wordCount,
            'elapsed' => microtime(true) - $this->startTime,
        ];
    }

    private function finalize(): void
    {
        $totalWords = array_sum(array_column($this->completedSections, 'word_count'));
        $totalTime = microtime(true) - $this->startTime;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "REPORT GENERATION COMPLETE\n";
        echo str_repeat('=', 50) . "\n";
        echo "Total words: {$totalWords}\n";
        echo "Total time: " . round($totalTime, 2) . " seconds\n";
        echo "Sections completed: " . count($this->completedSections) . "\n";
    }
}

// Generate a comprehensive report
$generator = new ProgressiveReportGenerator();

$reportData = [
    'company' => 'TechCorp Inc',
    'quarter' => 'Q4 2024',
    'revenue' => 15000000,
    'growth' => 23.5,
    'challenges' => ['market competition', 'supply chain'],
    'opportunities' => ['AI integration', 'global expansion'],
];

$generator->generateReport($reportData, [
    'temperature' => 0.6,
    'format' => 'detailed',
]);
```

## Key Takeaways

1. **Tool Integration**: Streaming and tool calling work together to create dynamic, data-driven experiences
2. **Error Recovery**: Implement checkpoints, retries, and partial result recovery for production reliability
3. **Flow Control**: Manage memory and resources with backpressure mechanisms
4. **Progressive Enhancement**: Stream content in priority order for better user experience
5. **Performance Monitoring**: Track metrics during streaming to optimize performance

## Next Steps

You've mastered advanced streaming patterns. In Chapter 12, we'll explore building production-ready applications with Pagent, including deployment strategies, monitoring, and scaling considerations.

### Practice Exercises

1. **Enhanced Dashboard**: Extend the dashboard streamer to handle WebSocket connections for real-time updates
2. **Resilient Pipeline**: Build a streaming pipeline that can recover from any point of failure
3. **Adaptive Flow Control**: Implement dynamic buffer sizing based on network conditions
4. **Multi-Stream Orchestration**: Create a system that manages multiple concurrent streams with different priorities

### Troubleshooting Guide

**Problem**: Tool calls interrupt streaming flow
**Solution**: Buffer tool results and inject them at natural break points in the text stream

**Problem**: Memory usage grows unbounded
**Solution**: Implement chunking with aggressive garbage collection and buffer limits

**Problem**: Network interruptions cause data loss
**Solution**: Use checkpointing with unique identifiers for each stream segment

**Problem**: Backpressure causes poor user experience
**Solution**: Implement adaptive buffering that adjusts based on client consumption rate

Remember: Advanced streaming is about balancing performance, reliability, and user experience. Start with simple patterns and progressively add sophistication as your requirements grow.