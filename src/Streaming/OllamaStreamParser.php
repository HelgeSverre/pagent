<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;

use function json_decode;
use function json_encode;
use function trim;

/**
 * Parses NDJSON (Newline-Delimited JSON) from Ollama's streaming API
 *
 * Unlike OpenAI/Anthropic which use Server-Sent Events (SSE),
 * Ollama uses newline-delimited JSON where each line is a complete JSON object.
 */
final class OllamaStreamParser implements StreamParser
{
    private string $accumulatedText = '';

    private array $usage = [];

    private bool $isFirstChunk = true;

    private string $responseModel = '';

    /**
     * Parse NDJSON stream from Ollama API
     *
     * @param  resource|iterable<string>  $stream  cURL stream or incremental byte chunks
     * @return Generator<StreamChunk>
     */
    public function parse($stream, string $model): Generator
    {
        $this->accumulatedText = '';
        $this->usage = [];
        $this->isFirstChunk = true;
        $this->responseModel = $model;

        foreach (LineIterator::from($stream) as $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Parse the JSON line
            $chunk = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($chunk)) {
                $reason = json_last_error() === JSON_ERROR_NONE
                    ? 'expected a JSON object'
                    : json_last_error_msg();
                yield StreamChunk::error('Failed to parse NDJSON data: '.$reason);

                return;
            }

            // Check for error in response
            if (isset($chunk['error'])) {
                yield StreamChunk::error($chunk['error']);
                break;
            }

            foreach ($this->handleChunk($chunk) as $streamChunk) {
                yield $streamChunk;
            }

            // Check if this is the final chunk
            if ($chunk['done'] ?? false) {
                break;
            }
        }
    }

    /**
     * Handle a single chunk from Ollama stream
     *
     * @return Generator<StreamChunk>
     */
    private function handleChunk(array $chunk): Generator
    {
        if (is_string($chunk['model'] ?? null) && $chunk['model'] !== '') {
            $this->responseModel = $chunk['model'];
        }

        $message = $chunk['message'] ?? [];

        // Emit start chunk on first message
        if ($this->isFirstChunk && isset($message['role'])) {
            $this->isFirstChunk = false;
            yield StreamChunk::start([
                'model' => $this->responseModel,
                'role' => $message['role'],
            ]);
        }

        // Handle text content (Ollama sends incremental content, not delta)
        if (isset($message['content']) && $message['content'] !== '') {
            $content = $message['content'];
            $this->accumulatedText .= $content;

            yield StreamChunk::text($content, [
                'model' => $this->responseModel,
            ]);
        }

        // Handle tool calls
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $index => $toolCall) {
                $arguments = $toolCall['function']['arguments'] ?? '';
                yield new StreamChunk(
                    type: 'tool_call',
                    content: is_string($arguments) ? $arguments : json_encode($arguments, JSON_THROW_ON_ERROR),
                    delta: $toolCall,
                    metadata: [
                        'tool_call_id' => $toolCall['id'] ?? null,
                        'tool_name' => $toolCall['function']['name'] ?? null,
                        'index' => $index,
                        'model' => $this->responseModel,
                    ],
                );
            }
        }

        // Check if this is the final chunk
        if ($chunk['done'] ?? false) {
            // Calculate usage from Ollama's token counts
            $promptTokens = $chunk['prompt_eval_count'] ?? 0;
            $completionTokens = $chunk['eval_count'] ?? 0;

            $this->usage = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ];

            yield StreamChunk::end([
                'finish_reason' => 'stop',
                'usage' => $this->usage,
                'full_content' => $this->accumulatedText,
                'model' => $this->responseModel,
                'total_duration' => $chunk['total_duration'] ?? null,
                'load_duration' => $chunk['load_duration'] ?? null,
                'prompt_eval_duration' => $chunk['prompt_eval_duration'] ?? null,
                'eval_duration' => $chunk['eval_duration'] ?? null,
            ]);
        }
    }
}
