<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;
use RuntimeException;

use function json_decode;
use function trim;

/**
 * Parses NDJSON (Newline-Delimited JSON) from Ollama's streaming API
 *
 * Unlike OpenAI/Anthropic which use Server-Sent Events (SSE),
 * Ollama uses newline-delimited JSON where each line is a complete JSON object.
 */
final class OllamaStreamParser
{
    private string $accumulatedText = '';

    private array $usage = [];

    private bool $isFirstChunk = true;

    /**
     * Parse NDJSON stream from Ollama API
     *
     * @param  resource  $stream  cURL stream resource
     * @return Generator<StreamChunk>
     */
    public function parse($stream, string $model): Generator
    {
        while (! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }

            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Parse the JSON line
            $chunk = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to parse NDJSON data: '.json_last_error_msg());
            }

            // Check for error in response
            if (isset($chunk['error'])) {
                yield StreamChunk::error($chunk['error']);
                break;
            }

            yield from $this->handleChunk($chunk, $model);

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
    private function handleChunk(array $chunk, string $model): Generator
    {
        $message = $chunk['message'] ?? [];

        // Emit start chunk on first message
        if ($this->isFirstChunk && isset($message['role'])) {
            $this->isFirstChunk = false;
            yield StreamChunk::start([
                'model' => $model,
                'role' => $message['role'],
            ]);
        }

        // Handle text content (Ollama sends incremental content, not delta)
        if (isset($message['content']) && $message['content'] !== '') {
            $content = $message['content'];

            // Calculate the delta by comparing with accumulated text
            // Ollama sends the full accumulated text each time, so we need to extract the new part
            if (strlen($content) > strlen($this->accumulatedText)) {
                $delta = substr($content, strlen($this->accumulatedText));
                $this->accumulatedText = $content;

                yield StreamChunk::text($delta, [
                    'model' => $model,
                ]);
            }
        }

        // Handle tool calls
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                yield new StreamChunk(
                    type: 'tool_call',
                    content: $toolCall['function']['arguments'] ?? '',
                    delta: $toolCall,
                    metadata: [
                        'tool_call_id' => $toolCall['id'] ?? null,
                        'tool_name' => $toolCall['function']['name'] ?? null,
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
                'model' => $model,
                'total_duration' => $chunk['total_duration'] ?? null,
                'load_duration' => $chunk['load_duration'] ?? null,
                'prompt_eval_duration' => $chunk['prompt_eval_duration'] ?? null,
                'eval_duration' => $chunk['eval_duration'] ?? null,
            ]);
        }
    }
}
