<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

echo "=== Ollama Streaming Examples ===\n\n";
echo "Prerequisites: Ollama server running with qwen3:8b model\n";
echo "Run: ollama pull qwen3:8b && ollama serve\n\n";

// Check if Ollama is available
function checkOllama(): bool
{
    $ch = curl_init('http://localhost:11434/api/version');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $response !== false && $httpCode === 200;
}

if (! checkOllama()) {
    echo "❌ Ollama server not available at http://localhost:11434\n";
    exit(1);
}

echo "✅ Ollama server is running\n\n";

// Example 1: Basic streaming with callback
echo "=== Example 1: Basic Streaming ===\n\n";

agent('ollama-streamer')
    ->provider(ollama())
    ->model('qwen3:8b');

echo "Streaming response:\n";
$fullContent = agent('ollama-streamer')->streamTo('Write a short poem about PHP', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});

echo "\n\n";

// Example 2: Stream with detailed chunk handling
echo "=== Example 2: Detailed Chunk Handling ===\n\n";

$response = agent('ollama-streamer')->stream('Explain object-oriented programming in 2 sentences');

$textChunks = [];
$startTime = null;
$endTime = null;

foreach ($response->getStream() as $chunk) {
    if ($chunk->isStart()) {
        $startTime = microtime(true);
        echo "[Stream started]\n";
    }

    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
        $textChunks[] = $chunk->content;
    }

    if ($chunk->isEnd()) {
        $endTime = microtime(true);
        echo "\n[Stream complete]\n\n";

        $usage = $chunk->getMetadata('usage');
        echo 'Chunks received: '.count($textChunks)."\n";
        echo "Tokens: {$usage['total_tokens']}\n";
        echo 'Duration: '.round(($endTime - $startTime) * 1000)." ms\n";
    }
}

echo "\n";

// Example 3: Compare streaming vs non-streaming
echo "=== Example 3: Streaming vs Non-Streaming ===\n\n";

$question = 'List 5 PHP features';

echo "Non-streaming:\n";
$start = microtime(true);
$response = agent('ollama-streamer')->prompt($question);
$nonStreamTime = microtime(true) - $start;
echo $response->content."\n";
echo 'Time: '.round($nonStreamTime * 1000)." ms\n\n";

echo "Streaming (with real-time output):\n";
$start = microtime(true);
$fullContent = agent('ollama-streamer')->streamTo($question, function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }
});
$streamTime = microtime(true) - $start;
echo "\nTime to complete: ".round($streamTime * 1000)." ms\n\n";

// Example 4: Collect full content from stream
echo "=== Example 4: Collect Full Content ===\n\n";

$streamResponse = agent('ollama-streamer')->stream('What is Laravel?');
$fullContent = $streamResponse->collect();

echo "Full collected content:\n";
echo $fullContent."\n\n";

// Example 5: Stream with progress indicator
echo "=== Example 5: Stream with Progress Indicator ===\n\n";

echo 'Generating response';
$chunkCount = 0;

agent('ollama-streamer')->streamTo('Explain MVC pattern', function ($chunk) use (&$chunkCount) {
    if ($chunk->isText()) {
        $chunkCount++;
        if ($chunkCount % 3 === 0) {
            echo '.';
            flush();
        }
    }
});

echo " Done! ({$chunkCount} chunks)\n\n";

echo "✅ All streaming examples completed!\n";
echo "\nNote: Ollama uses NDJSON streaming (newline-delimited JSON)\n";
echo "This is different from OpenAI/Anthropic which use Server-Sent Events (SSE)\n";
