<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;
use RuntimeException;

use function json_decode;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Parses Server-Sent Events from OpenAI's streaming API
 */
final class OpenAIStreamParser
{
    private string $accumulatedText = '';

    private array $usage = [];

    /**
     * Parse SSE stream from OpenAI API
     *
     * @param  resource  $stream  cURL stream resource
     * @return Generator<StreamChunk>
     */
    public function parse($stream, string $model): Generator
    {
        $buffer = '';

        while (! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }

            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // OpenAI SSE format: "data: {...}"
            if (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));

                // Check for [DONE] marker
                if ($data === '[DONE]') {
                    yield StreamChunk::end([
                        'full_content' => $this->accumulatedText,
                        'usage' => $this->usage,
                        'model' => $model,
                    ]);
                    break;
                }

                $chunk = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Failed to parse SSE data: '.json_last_error_msg());
                }

                yield from $this->handleChunk($chunk, $model);
            }
        }
    }

    /**
     * Handle a single chunk from OpenAI stream
     *
     * @return Generator<StreamChunk>
     */
    private function handleChunk(array $chunk, string $model): Generator
    {
        $choices = $chunk['choices'] ?? [];
        if (empty($choices)) {
            return;
        }

        $choice = $choices[0];
        $delta = $choice['delta'] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;

        // Check if this is the first chunk (has role)
        if (isset($delta['role'])) {
            yield StreamChunk::start([
                'model' => $model,
                'role' => $delta['role'],
            ]);
        }

        // Text content delta
        if (isset($delta['content'])) {
            $text = $delta['content'];
            $this->accumulatedText .= $text;
            yield StreamChunk::text($text, [
                'index' => $choice['index'] ?? 0,
                'model' => $model,
            ]);
        }

        // Tool calls delta
        if (isset($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $toolCall) {
                yield new StreamChunk(
                    type: 'tool_call',
                    content: $toolCall['function']['arguments'] ?? '',
                    delta: $toolCall,
                    metadata: [
                        'tool_call_id' => $toolCall['id'] ?? null,
                        'tool_name' => $toolCall['function']['name'] ?? null,
                        'index' => $toolCall['index'] ?? 0,
                    ],
                );
            }
        }

        // Usage information (if available)
        if (isset($chunk['usage'])) {
            $this->usage = $chunk['usage'];
        }

        // Check for completion
        if ($finishReason !== null) {
            yield StreamChunk::end([
                'finish_reason' => $finishReason,
                'usage' => $this->usage,
                'full_content' => $this->accumulatedText,
                'model' => $model,
            ]);
        }
    }
}
