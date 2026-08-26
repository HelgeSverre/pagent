<?php

declare(strict_types=1);

use Pagent\Streaming\OpenAIResponsesStreamParser;

function responsesEvents(string $events): mixed
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $events);
    rewind($stream);

    return $stream;
}

test('Responses parser treats an incomplete response as a terminal response', function (): void {
    $stream = responsesEvents(<<<'SSE'
event: response.created
data: {"type":"response.created","response":{"id":"resp_1","model":"gpt-5.1-2026-08-01"}}

event: response.output_text.delta
data: {"type":"response.output_text.delta","delta":"partial","output_index":0,"content_index":0}

event: response.incomplete
data: {"type":"response.incomplete","response":{"status":"incomplete","model":"gpt-5.1-2026-08-01","incomplete_details":{"reason":"max_output_tokens"},"usage":{"input_tokens":2,"output_tokens":3,"total_tokens":5}}}

SSE);

    $chunks = iterator_to_array((new OpenAIResponsesStreamParser)->parse($stream, 'gpt-5.1'));
    $end = $chunks[array_key_last($chunks)];

    expect($end->isEnd())->toBeTrue()
        ->and($end->getMetadata('finish_reason'))->toBe('max_output_tokens')
        ->and($end->getMetadata('status'))->toBe('incomplete')
        ->and($end->getMetadata('model'))->toBe('gpt-5.1-2026-08-01');

    fclose($stream);
});

test('Responses parser preserves refusal text as visible content', function (): void {
    $stream = responsesEvents(<<<'SSE'
event: response.refusal.delta
data: {"type":"response.refusal.delta","delta":"I cannot help with that.","output_index":0,"content_index":0}

event: response.completed
data: {"type":"response.completed","response":{"status":"completed","model":"gpt-5.1"}}

SSE);

    $chunks = iterator_to_array((new OpenAIResponsesStreamParser)->parse($stream, 'gpt-5.1'));

    expect($chunks[0]->isText())->toBeTrue()
        ->and($chunks[0]->content)->toBe('I cannot help with that.')
        ->and($chunks[0]->getMetadata('content_type'))->toBe('refusal')
        ->and($chunks[1]->getMetadata('full_content'))->toBe('I cannot help with that.');

    fclose($stream);
});

test('Responses parser emits a completed tool call with full arguments', function (): void {
    $stream = responsesEvents(<<<'SSE'
event: response.output_item.added
data: {"type":"response.output_item.added","output_index":0,"item":{"type":"function_call","call_id":"call_1","name":"weather"}}

event: response.function_call_arguments.delta
data: {"type":"response.function_call_arguments.delta","output_index":0,"delta":"{\"city\"","call_id":"call_1"}

event: response.function_call_arguments.done
data: {"type":"response.function_call_arguments.done","output_index":0,"arguments":"{\"city\":\"Oslo\"}","call_id":"call_1","name":"weather"}

event: response.output_item.done
data: {"type":"response.output_item.done","output_index":0,"item":{"type":"function_call","call_id":"call_1","name":"weather","arguments":"{\"city\":\"Oslo\"}"}}

event: response.completed
data: {"type":"response.completed","response":{"status":"completed","model":"gpt-5.1"}}

SSE);

    $chunks = iterator_to_array((new OpenAIResponsesStreamParser)->parse($stream, 'gpt-5.1'));
    $doneChunks = array_values(array_filter($chunks, fn ($chunk) => $chunk->type === 'tool_call_done'));
    $done = $doneChunks[0];

    expect($doneChunks)->toHaveCount(1)
        ->and($done->content)->toBe('{"city":"Oslo"}')
        ->and($done->getMetadata('arguments_complete'))->toBeTrue()
        ->and($done->getMetadata('tool_name'))->toBe('weather');

    fclose($stream);
});
