<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;
use Pagent\Exceptions\RuntimeException;

use function explode;
use function is_array;
use function json_decode;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Parses Server-Sent Events emitted by the OpenAI Responses protocol.
 */
final class OpenAIResponsesStreamParser implements StreamParser
{
    private string $accumulatedText = '';

    /** @var array<string, mixed> */
    private array $usage = [];

    private bool $started = false;

    private string $responseModel = '';

    /** @var array<int, array<string, mixed>> */
    private array $toolCalls = [];

    /** @var array<int, true> */
    private array $completedToolCalls = [];

    /**
     * @param  resource|iterable<string>  $stream
     * @return Generator<StreamChunk>
     */
    public function parse($stream, string $model): Generator
    {
        $this->accumulatedText = '';
        $this->usage = [];
        $this->started = false;
        $this->toolCalls = [];
        $this->completedToolCalls = [];
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

    /** @return array{event: string, data: array<string, mixed>}|null */
    private function parseEvent(string $buffer): ?array
    {
        $event = null;
        $data = null;

        foreach (explode("\n", trim($buffer)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $decoded = json_decode(trim(substr($line, 5)), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Failed to parse Responses SSE data');
                }
                $data = $decoded;
            }
        }

        if ($data === null) {
            return null;
        }

        return ['event' => $event ?? ($data['type'] ?? ''), 'data' => $data];
    }

    /**
     * @param  array{event: string, data: array<string, mixed>}  $event
     * @return Generator<StreamChunk>
     */
    private function handleEvent(array $event): Generator
    {
        $type = $event['event'];
        $data = $event['data'];
        $this->captureResponseModel($data);

        if ($type === 'response.created' || $type === 'response.in_progress') {
            if (! $this->started) {
                $this->started = true;
                $response = is_array($data['response'] ?? null) ? $data['response'] : [];
                yield StreamChunk::start([
                    'model' => $this->responseModel,
                    'response_id' => $response['id'] ?? null,
                ]);
            }

            return;
        }

        if ($type === 'response.output_item.added') {
            $item = is_array($data['item'] ?? null) ? $data['item'] : [];
            $outputIndex = is_int($data['output_index'] ?? null) ? $data['output_index'] : 0;
            if (($item['type'] ?? null) === 'function_call') {
                $this->toolCalls[$outputIndex] = $item;
            }

            return;
        }

        if ($type === 'response.output_text.delta') {
            $text = is_string($data['delta'] ?? null) ? $data['delta'] : '';
            $this->accumulatedText .= $text;
            yield StreamChunk::text($text, [
                'model' => $this->responseModel,
                'output_index' => $data['output_index'] ?? 0,
                'content_index' => $data['content_index'] ?? 0,
            ]);

            return;
        }

        if ($type === 'response.refusal.delta') {
            $text = is_string($data['delta'] ?? null) ? $data['delta'] : '';
            $this->accumulatedText .= $text;
            yield StreamChunk::text($text, [
                'model' => $this->responseModel,
                'content_type' => 'refusal',
                'output_index' => $data['output_index'] ?? 0,
                'content_index' => $data['content_index'] ?? 0,
            ]);

            return;
        }

        if ($type === 'response.function_call_arguments.delta') {
            $outputIndex = is_int($data['output_index'] ?? null) ? $data['output_index'] : 0;
            $toolCall = $this->toolCalls[$outputIndex] ?? [];
            yield new StreamChunk(
                type: 'tool_call',
                content: is_string($data['delta'] ?? null) ? $data['delta'] : '',
                delta: $data,
                metadata: [
                    'tool_call_id' => $data['call_id'] ?? $toolCall['call_id'] ?? null,
                    'tool_name' => $data['name'] ?? $toolCall['name'] ?? null,
                    'output_index' => $outputIndex,
                ],
            );

            return;
        }

        if ($type === 'response.function_call_arguments.done') {
            $outputIndex = is_int($data['output_index'] ?? null) ? $data['output_index'] : 0;
            $toolCall = $this->toolCalls[$outputIndex] ?? [];
            $arguments = is_string($data['arguments'] ?? null) ? $data['arguments'] : '';
            $this->completedToolCalls[$outputIndex] = true;
            yield new StreamChunk(
                type: 'tool_call_done',
                content: $arguments,
                delta: $data,
                metadata: [
                    'tool_call_id' => $data['call_id'] ?? $toolCall['call_id'] ?? null,
                    'tool_name' => $data['name'] ?? $toolCall['name'] ?? null,
                    'output_index' => $outputIndex,
                    'arguments_complete' => true,
                    'model' => $this->responseModel,
                ],
            );

            return;
        }

        if ($type === 'response.output_item.done') {
            $item = is_array($data['item'] ?? null) ? $data['item'] : [];
            if (($item['type'] ?? null) !== 'function_call') {
                return;
            }

            $outputIndex = is_int($data['output_index'] ?? null) ? $data['output_index'] : 0;
            if (isset($this->completedToolCalls[$outputIndex])) {
                return;
            }
            $this->completedToolCalls[$outputIndex] = true;
            yield new StreamChunk(
                type: 'tool_call_done',
                content: is_string($item['arguments'] ?? null) ? $item['arguments'] : '',
                delta: $item,
                metadata: [
                    'tool_call_id' => $item['call_id'] ?? null,
                    'tool_name' => $item['name'] ?? null,
                    'output_index' => $outputIndex,
                    'arguments_complete' => true,
                    'model' => $this->responseModel,
                ],
            );

            return;
        }

        if ($type === 'response.completed') {
            $response = is_array($data['response'] ?? null) ? $data['response'] : [];
            $usage = $response['usage'] ?? [];
            if (is_array($usage)) {
                $this->usage = $usage;
            }

            yield StreamChunk::end([
                'finish_reason' => $response['status'] ?? 'completed',
                'usage' => $this->usage,
                'full_content' => $this->accumulatedText,
                'model' => $this->responseModel,
            ]);

            return;
        }

        if ($type === 'response.incomplete') {
            $response = is_array($data['response'] ?? null) ? $data['response'] : [];
            $usage = $response['usage'] ?? [];
            if (is_array($usage)) {
                $this->usage = $usage;
            }
            $details = is_array($response['incomplete_details'] ?? null)
                ? $response['incomplete_details']
                : [];
            $reason = is_string($details['reason'] ?? null) ? $details['reason'] : 'incomplete';

            yield StreamChunk::end([
                'finish_reason' => $reason,
                'status' => 'incomplete',
                'usage' => $this->usage,
                'full_content' => $this->accumulatedText,
                'model' => $this->responseModel,
            ]);

            return;
        }

        if ($type === 'error' || $type === 'response.failed') {
            $response = is_array($data['response'] ?? null) ? $data['response'] : [];
            $error = $data['error'] ?? $response['error'] ?? [];
            $message = is_array($error) ? ($error['message'] ?? 'Unknown error') : 'Unknown error';
            yield StreamChunk::error((string) $message);
        }
    }

    /** @param array<string, mixed> $data */
    private function captureResponseModel(array $data): void
    {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $reportedModel = $response['model'] ?? $data['model'] ?? null;
        if (is_string($reportedModel) && $reportedModel !== '') {
            $this->responseModel = $reportedModel;
        }
    }
}
