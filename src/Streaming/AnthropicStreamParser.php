<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;
use RuntimeException;

use function explode;
use function json_decode;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Parses Server-Sent Events from Anthropic's streaming API
 */
final class AnthropicStreamParser
{
    private array $currentMessage = [];

    private array $contentBlocks = [];

    private string $accumulatedText = '';

    /**
     * Parse SSE stream from Anthropic API
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

            $buffer .= $line;

            // SSE events are separated by double newlines
            if (trim($line) === '') {
                $event = $this->parseEvent($buffer);
                $buffer = '';

                if ($event !== null) {
                    yield from $this->handleEvent($event, $model);
                }
            }
        }
    }

    /**
     * Parse a single SSE event from buffer
     */
    private function parseEvent(string $buffer): ?array
    {
        $lines = explode("\n", trim($buffer));
        $event = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $event['event'] = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
                $event['data'] = json_decode($data, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Failed to parse SSE data: '.json_last_error_msg());
                }
            }
        }

        return ! empty($event) ? $event : null;
    }

    /**
     * Handle different event types
     *
     * @return Generator<StreamChunk>
     */
    private function handleEvent(array $event, string $model): Generator
    {
        $data = $event['data'] ?? [];
        $type = $data['type'] ?? null;

        switch ($type) {
            case 'message_start':
                $this->currentMessage = $data['message'] ?? [];
                yield StreamChunk::start([
                    'message_id' => $this->currentMessage['id'] ?? null,
                    'model' => $model,
                    'usage' => $this->currentMessage['usage'] ?? null,
                ]);
                break;

            case 'content_block_start':
                $index = $data['index'] ?? 0;
                $contentBlock = $data['content_block'] ?? [];
                $this->contentBlocks[$index] = $contentBlock;
                break;

            case 'content_block_delta':
                $index = $data['index'] ?? 0;
                $delta = $data['delta'] ?? [];

                if (isset($delta['text'])) {
                    // Text delta
                    $text = $delta['text'];
                    $this->accumulatedText .= $text;
                    yield StreamChunk::text($text, [
                        'index' => $index,
                        'delta_type' => 'text_delta',
                    ]);
                } elseif (isset($delta['partial_json'])) {
                    // Tool input delta
                    yield new StreamChunk(
                        type: 'input_json_delta',
                        content: $delta['partial_json'],
                        delta: $delta,
                        metadata: ['index' => $index],
                    );
                } elseif (isset($delta['thinking'])) {
                    // Thinking delta (extended thinking feature)
                    yield new StreamChunk(
                        type: 'thinking_delta',
                        content: $delta['thinking'],
                        delta: $delta,
                        metadata: ['index' => $index],
                    );
                }
                break;

            case 'content_block_stop':
                // Content block finished
                break;

            case 'message_delta':
                $delta = $data['delta'] ?? [];
                $usage = $data['usage'] ?? [];

                if (isset($delta['stop_reason'])) {
                    $this->currentMessage['stop_reason'] = $delta['stop_reason'];
                }

                if (! empty($usage)) {
                    $this->currentMessage['usage'] = array_merge(
                        $this->currentMessage['usage'] ?? [],
                        $usage
                    );
                }
                break;

            case 'message_stop':
                yield StreamChunk::end([
                    'stop_reason' => $this->currentMessage['stop_reason'] ?? null,
                    'usage' => $this->currentMessage['usage'] ?? null,
                    'full_content' => $this->accumulatedText,
                ]);
                break;

            case 'ping':
                // Keep-alive ping, ignore
                break;

            case 'error':
                $error = $data['error'] ?? [];
                yield StreamChunk::error(
                    $error['message'] ?? 'Unknown error',
                    ['error_type' => $error['type'] ?? 'unknown']
                );
                break;
        }
    }
}
