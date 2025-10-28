<?php

declare(strict_types=1);

/**
 * Server-Sent Events (SSE) Streaming Example
 *
 * This example demonstrates how to create an HTTP endpoint
 * that streams LLM responses using Server-Sent Events.
 *
 * Run with PHP's built-in server:
 *   php -S localhost:8000 examples/streaming-sse-endpoint.php
 *
 * Then open in browser or use curl:
 *   curl http://localhost:8000
 */

require_once __DIR__.'/../vendor/autoload.php';

use Pagent\Providers\Anthropic;

use function Pagent\agent;

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Disable output buffering for real-time streaming
if (ob_get_level()) {
    ob_end_clean();
}

/**
 * Send an SSE event
 */
function sendSSE(string $event, mixed $data): void
{
    echo "event: {$event}\n";
    echo 'data: '.json_encode($data)."\n\n";
    flush();
}

/**
 * Send a simple message
 */
function sendMessage(string $message): void
{
    echo "data: {$message}\n\n";
    flush();
}

try {
    // Get question from query parameter
    $question = $_GET['q'] ?? 'Tell me a short joke about programming';

    // Send initial connection message
    sendSSE('connected', [
        'message' => 'Stream connected',
        'question' => $question,
    ]);

    // Create agent
    $agent = agent('sse-streamer')
        ->provider(new Anthropic([
            'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'),
        ]))
        ->system('You are a helpful assistant. Be concise.')
        ->model('claude-3-haiku-20240307')
        ->maxTokens(200);

    // Stream the response
    $streamResponse = $agent->stream($question);

    $fullText = '';

    foreach ($streamResponse->getStream() as $chunk) {
        if ($chunk->isStart()) {
            sendSSE('start', [
                'model' => $chunk->getMetadata('model'),
                'message_id' => $chunk->getMetadata('message_id'),
            ]);
        } elseif ($chunk->isText()) {
            $text = $chunk->content;
            $fullText .= $text;

            sendSSE('token', [
                'text' => $text,
                'accumulated' => $fullText,
            ]);
        } elseif ($chunk->isEnd()) {
            sendSSE('done', [
                'full_text' => $fullText,
                'stop_reason' => $chunk->getMetadata('stop_reason'),
                'usage' => $chunk->getMetadata('usage'),
            ]);
        } elseif ($chunk->isError()) {
            sendSSE('error', [
                'message' => $chunk->content,
            ]);
        }

        // Small delay to make streaming visible (optional)
        usleep(10000); // 10ms
    }

    // Send completion marker
    sendSSE('complete', ['status' => 'success']);

} catch (Throwable $e) {
    sendSSE('error', [
        'message' => $e->getMessage(),
        'type' => get_class($e),
    ]);
}
