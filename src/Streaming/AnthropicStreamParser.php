<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;
use Pagent\Exceptions\RuntimeException;

use function explode;
use function json_decode;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Parses Server-Sent Events from Anthropic's streaming API
 */
final class AnthropicStreamParser implements StreamParser
{
    private array $currentMessage = [];

    private array $contentBlocks = [];

    private string $accumulatedText = '';

    private string $responseModel = '';

    /**
     * Parse SSE stream from Anthropic API
     *
     * @param  resource|iterable<string>  $stream  cURL stream or incremental byte chunks
     * @return Generator<StreamChunk>
     */
    public function parse($stream, string $model): Generator
    {
        $this->currentMessage = [];
        $this->contentBlocks = [];
        $this->accumulatedText = '';
        $this->responseModel = $model;

        foreach (SseEventIterator::from($stream) as $buffer) {
            try {
                $event = $this->parseEvent($buffer);
            } catch (RuntimeException $e) {
                yield StreamChunk::error($e->getMessage());

                return;
            }

            if ($event !== null) {
                foreach ($this->handleEvent($event) as $chunk) {
                    yield $chunk;
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

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($event['data'])) {
                    $reason = json_last_error() === JSON_ERROR_NONE
                        ? 'expected a JSON object'
                        : json_last_error_msg();
                    throw new RuntimeException('Failed to parse SSE data: '.$reason);
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
    private function handleEvent(array $event): Generator
    {
        $data = $event['data'] ?? [];
        $type = $data['type'] ?? null;

        switch ($type) {
            case 'message_start':
                $this->currentMessage = $data['message'] ?? [];
                if (is_string($this->currentMessage['model'] ?? null) && $this->currentMessage['model'] !== '') {
                    $this->responseModel = $this->currentMessage['model'];
                }
                yield StreamChunk::start([
                    'message_id' => $this->currentMessage['id'] ?? null,
                    'model' => $this->responseModel,
                    'usage' => $this->currentMessage['usage'] ?? null,
                ]);
                break;

            case 'content_block_start':
                $index = $data['index'] ?? 0;
                $contentBlock = $data['content_block'] ?? [];
                $this->contentBlocks[$index] = $contentBlock;
                if (($contentBlock['type'] ?? null) === 'tool_use') {
                    // Identity must not depend on a later JSON delta: tools
                    // with no arguments may move straight to block_stop.
                    yield new StreamChunk(
                        type: 'tool_call',
                        content: '',
                        delta: $contentBlock,
                        metadata: [
                            'index' => $index,
                            'tool_call_id' => $contentBlock['id'] ?? null,
                            'tool_name' => $contentBlock['name'] ?? null,
                            'model' => $this->responseModel,
                        ],
                    );
                }
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
                        'model' => $this->responseModel,
                    ]);
                } elseif (isset($delta['partial_json'])) {
                    // Tool input delta
                    $contentBlock = $this->contentBlocks[$index] ?? [];
                    yield new StreamChunk(
                        type: 'input_json_delta',
                        content: $delta['partial_json'],
                        delta: $delta,
                        metadata: [
                            'index' => $index,
                            'tool_call_id' => $contentBlock['id'] ?? null,
                            'tool_name' => $contentBlock['name'] ?? null,
                            'model' => $this->responseModel,
                        ],
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
                    'model' => $this->responseModel,
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
