<?php

declare(strict_types=1);

use Pagent\Streaming\AnthropicStreamParser;

/**
 * Helper to create a mock stream from SSE events
 */
function createStreamFromEvents(string $events): mixed
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $events);
    rewind($stream);

    return $stream;
}

test('it parses message_start event', function (): void {
    $events = "event: message_start\n".
              "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_123\",\"usage\":{\"input_tokens\":10,\"output_tokens\":0}}}\n\n";

    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    expect($chunks)->not->toBeEmpty();
    expect($chunks[0]->isStart())->toBeTrue();

    fclose($stream);
});

test('it parses content_block_delta event', function (): void {
    $events = "event: content_block_delta\n".
              "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello World\"}}\n\n";

    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    expect($chunks)->not->toBeEmpty();
    expect($chunks[0]->isText())->toBeTrue();
    expect($chunks[0]->content)->toBe('Hello World');

    fclose($stream);
});

test('it associates streamed tool arguments with their tool call', function (): void {
    $events = "event: content_block_start\n".
              "data: {\"type\":\"content_block_start\",\"index\":1,\"content_block\":{\"type\":\"tool_use\",\"id\":\"toolu_123\",\"name\":\"lookup\",\"input\":{}}}\n\n".
              "event: content_block_delta\n".
              'data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\\"query\\":\\"docs\\"}"}}';

    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-sonnet-4-6'));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->isToolCall())->toBeTrue()
        ->and($chunks[1]->isToolCall())->toBeTrue()
        ->and($chunks[1]->getMetadata('tool_call_id'))->toBe('toolu_123')
        ->and($chunks[1]->getMetadata('tool_name'))->toBe('lookup');

    fclose($stream);
});

test('it parses message_stop event', function (): void {
    $events = "event: message_delta\n".
              "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":25}}\n\n".
              "event: message_stop\n".
              "data: {\"type\":\"message_stop\"}\n\n";

    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    $endChunks = array_filter($chunks, fn ($c) => $c->isEnd());
    expect($endChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it parses thinking delta', function (): void {
    $events = "event: content_block_delta\n".
              "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"thinking_delta\",\"thinking\":\"Let me think...\"}}\n\n";

    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    expect($chunks)->not->toBeEmpty();
    expect($chunks[0]->type)->toBe('thinking_delta');

    fclose($stream);
});

test('it parses error events', function (): void {
    $events = "event: error\n".
              "data: {\"type\":\"error\",\"error\":{\"type\":\"rate_limit_error\",\"message\":\"Rate limit exceeded\"}}\n\n";

    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->isError())->toBeTrue();
    expect($chunks[0]->content)->toBe('Rate limit exceeded');

    fclose($stream);
});

test('it yields an error chunk on malformed json in SSE data', function (): void {
    $events = "event: message_start\ndata: {invalid json}\n\n";

    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->isError())->toBeTrue()
        ->and($chunks[0]->content)->toContain('Failed to parse SSE data');

    fclose($stream);
});

test('it yields an error chunk when SSE data is valid JSON but not an object', function (): void {
    $stream = createStreamFromEvents("event: message_start\ndata: null\n\n");
    $chunks = iterator_to_array((new AnthropicStreamParser)->parse($stream, 'claude-3'));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->isError())->toBeTrue()
        ->and($chunks[0]->content)->toContain('expected a JSON object');

    fclose($stream);
});

test('it handles empty stream', function (): void {
    $stream = createStreamFromEvents('');
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    expect($chunks)->toBeEmpty();

    fclose($stream);
});

test('it handles stream with only whitespace', function (): void {
    $events = "   \n\n   \n   ";
    $stream = createStreamFromEvents($events);
    $parser = new AnthropicStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'claude-3'));

    expect($chunks)->toBeEmpty();

    fclose($stream);
});

test('it reports the concrete Anthropic response model', function (): void {
    $events = "event: message_start\n".
        "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_123\",\"model\":\"claude-sonnet-4-6-20260801\"}}\n\n".
        "event: message_stop\n".
        "data: {\"type\":\"message_stop\"}\n\n";

    $stream = createStreamFromEvents($events);
    $chunks = iterator_to_array((new AnthropicStreamParser)->parse($stream, 'claude-sonnet-4-6'));

    expect($chunks[0]->getMetadata('model'))->toBe('claude-sonnet-4-6-20260801')
        ->and($chunks[1]->getMetadata('model'))->toBe('claude-sonnet-4-6-20260801');

    fclose($stream);
});

test('it emits tool identity even when Anthropic sends no argument deltas', function (): void {
    $events = "event: content_block_start\n".
        "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"tool_use\",\"id\":\"toolu_empty\",\"name\":\"now\",\"input\":{}}}\n\n".
        "event: content_block_stop\n".
        "data: {\"type\":\"content_block_stop\",\"index\":0}\n\n".
        "event: message_stop\n".
        "data: {\"type\":\"message_stop\"}\n\n";

    $stream = createStreamFromEvents($events);
    $chunks = iterator_to_array((new AnthropicStreamParser)->parse($stream, 'claude'));

    expect($chunks[0]->isToolCall())->toBeTrue()
        ->and($chunks[0]->content)->toBe('')
        ->and($chunks[0]->getMetadata('tool_call_id'))->toBe('toolu_empty')
        ->and($chunks[0]->getMetadata('tool_name'))->toBe('now');

    fclose($stream);
});
