<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;

use function json_decode;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Parses Server-Sent Events from OpenAI's streaming API
 */
final class OpenAIStreamParser implements StreamParser
{
    private string $accumulatedText = '';

    private array $usage = [];

    /** @var array<int, array<string, mixed>> */
    private array $toolCalls = [];

    /**
     * Parse SSE stream from OpenAI API
     *
     * @param  resource|iterable<string>  $stream  cURL stream or incremental byte chunks
     * @return Generator<StreamChunk>
     */
    public function parse($stream, string $model): Generator
    {
        $this->accumulatedText = '';
        $this->usage = [];
        $this->toolCalls = [];
        $finishReason = null;
        $sawDone = false;

        foreach (LineIterator::from($stream) as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // OpenAI SSE format: "data: {...}"
            if (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));

                // Check for [DONE] marker
                if ($data === '[DONE]') {
                    $sawDone = true;
                    yield StreamChunk::end([
                        'full_content' => $this->accumulatedText,
                        'usage' => $this->usage,
                        'model' => $model,
                        'finish_reason' => $finishReason,
                    ]);
                    break;
                }

                $chunk = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($chunk)) {
                    $reason = json_last_error() === JSON_ERROR_NONE
                        ? 'expected a JSON object'
                        : json_last_error_msg();
                    yield StreamChunk::error('Failed to parse SSE data: '.$reason);

                    return;
                }

                $reportedFinishReason = $chunk['choices'][0]['finish_reason'] ?? null;
                if (is_string($reportedFinishReason)) {
                    $finishReason = $reportedFinishReason;
                }

                foreach ($this->handleChunk($chunk, $model) as $streamChunk) {
                    yield $streamChunk;
                }
            }
        }

        if (! $sawDone && $finishReason !== null) {
            yield StreamChunk::end([
                'full_content' => $this->accumulatedText,
                'usage' => $this->usage,
                'model' => $model,
                'finish_reason' => $finishReason,
            ]);
        }
    }

    /**
     * Handle a single chunk from OpenAI stream
     *
     * @return Generator<StreamChunk>
     */
    private function handleChunk(array $chunk, string $model): Generator
    {
        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $this->usage = $chunk['usage'];
        }

        $choices = $chunk['choices'] ?? [];
        if (empty($choices)) {
            return;
        }

        $choice = $choices[0];
        $delta = $choice['delta'] ?? [];

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
                $index = is_int($toolCall['index'] ?? null) ? $toolCall['index'] : 0;
                $knownToolCall = $this->toolCalls[$index] ?? [];
                if (is_string($toolCall['id'] ?? null)) {
                    $knownToolCall['id'] = $toolCall['id'];
                }
                if (is_string($toolCall['function']['name'] ?? null)) {
                    $knownToolCall['name'] = $toolCall['function']['name'];
                }
                $this->toolCalls[$index] = $knownToolCall;

                yield new StreamChunk(
                    type: 'tool_call',
                    content: $toolCall['function']['arguments'] ?? '',
                    delta: $toolCall,
                    metadata: [
                        'tool_call_id' => $knownToolCall['id'] ?? null,
                        'tool_name' => $knownToolCall['name'] ?? null,
                        'index' => $index,
                    ],
                );
            }
        }

    }
}
