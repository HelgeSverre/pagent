<?php

declare(strict_types=1);

use Pagent\Streaming\OpenAIStreamParser;

/**
 * Helper to create a mock stream from OpenAI SSE events
 */
function createOpenAIStream(string $events): mixed
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $events);
    rewind($stream);

    return $stream;
}

test('it parses start chunk with role', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"},\"index\":0}],\"model\":\"gpt-4\"}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    expect($chunks)->not->toBeEmpty();
    expect($chunks[0]->isStart())->toBeTrue();

    fclose($stream);
});

test('it parses text content', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello World\"},\"index\":0}]}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    expect($chunks)->not->toBeEmpty();
    expect($chunks[0]->isText())->toBeTrue();
    expect($chunks[0]->content)->toBe('Hello World');

    fclose($stream);
});

test('it parses DONE marker', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"index\":0}]}\n\ndata: [DONE]\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    $endChunks = array_filter($chunks, fn ($c) => $c->isEnd());
    expect($endChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it emits one terminal chunk when OpenAI sends finish reason and DONE', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"index\":0}]}\n\n".
        "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\",\"index\":0}]}\n\n".
        "data: [DONE]\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    expect(array_values(array_filter($chunks, fn ($chunk) => $chunk->isEnd())))->toHaveCount(1);

    fclose($stream);
});

test('it parses finish_reason', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\",\"index\":0}]}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    $endChunks = array_filter($chunks, fn ($c) => $c->isEnd());
    expect($endChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it parses tool calls', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_123\",\"function\":{\"name\":\"get_weather\",\"arguments\":\"{\\\"location\\\":\\\"London\\\"}\"}}]},\"index\":0}]}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    $toolCallChunks = array_filter($chunks, fn ($c) => $c->isToolCall());
    expect($toolCallChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it retains tool identity across fragmented argument deltas', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_123\",\"function\":{\"name\":\"lookup\",\"arguments\":\"{\"}}]},\"index\":0}]}\n\n".
        "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"}\"}}]},\"index\":0}]}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = array_values(iterator_to_array($parser->parse($stream, 'gpt-4')));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1]->getMetadata('tool_call_id'))->toBe('call_123')
        ->and($chunks[1]->getMetadata('tool_name'))->toBe('lookup');

    fclose($stream);
});

test('it includes usage information when available', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"index\":0}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5,\"total_tokens\":15}}\n\n".
              "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\",\"index\":0}]}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    $endChunks = array_filter($chunks, fn ($c) => $c->isEnd());
    expect($endChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it captures OpenAI usage-only chunks before the terminal marker', function (): void {
    $events = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"index\":0}]}\n\n".
        "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\",\"index\":0}]}\n\n".
        "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":3,\"completion_tokens\":1,\"total_tokens\":4}}\n\n".
        "data: [DONE]\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));
    $endChunk = array_values(array_filter($chunks, fn ($chunk) => $chunk->isEnd()))[0];

    expect($endChunk->getMetadata('usage'))->toBe([
        'prompt_tokens' => 3,
        'completion_tokens' => 1,
        'total_tokens' => 4,
    ])->and($endChunk->getMetadata('finish_reason'))->toBe('stop');

    fclose($stream);
});

test('it yields an error chunk on malformed json', function (): void {
    $events = "data: {invalid json}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->isError())->toBeTrue()
        ->and($chunks[0]->content)->toContain('Failed to parse SSE data');

    fclose($stream);
});

test('it treats top-level OpenAI stream errors as failures instead of successful empty responses', function (): void {
    $events = "data: {\"error\":{\"message\":\"rate limited\",\"type\":\"server_error\"}}\n\n".
        "data: [DONE]\n\n";

    $stream = createOpenAIStream($events);
    $chunks = iterator_to_array((new OpenAIStreamParser)->parse($stream, 'gpt-4'));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->isError())->toBeTrue()
        ->and($chunks[0]->content)->toBe('rate limited')
        ->and($chunks[0]->getMetadata('error_type'))->toBe('server_error');

    fclose($stream);
});

test('it handles empty stream', function (): void {
    $stream = createOpenAIStream('');
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    expect($chunks)->toBeEmpty();

    fclose($stream);
});

test('it skips chunks without choices', function (): void {
    $events = "data: {\"model\":\"gpt-4\"}\n\n".
              "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"index\":0}]}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    expect($chunks)->not->toBeEmpty();
    expect($chunks[0]->content)->toBe('Hello');

    fclose($stream);
});

test('it handles DONE marker without errors', function (): void {
    $events = "data: [DONE]\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'gpt-4'));

    // DONE marker produces an end chunk
    $endChunks = array_filter($chunks, fn ($c) => $c->isEnd());
    expect($endChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it parses transport chunks that split SSE records', function (): void {
    $chunks = (function () {
        yield 'data: {"choices":[{"delta":{"content":"Hel';
        yield 'lo"},"index":0}]}';
        yield "\n\n";
        yield "data: [DONE]\n\n";
    })();

    $parser = new OpenAIStreamParser;
    $parsed = iterator_to_array($parser->parse($chunks, 'gpt-4'));

    expect($parsed[0]->content)->toBe('Hello')
        ->and($parsed[1]->isEnd())->toBeTrue();
});

test('it reports the model returned by OpenAI instead of the requested alias', function (): void {
    $events = "data: {\"model\":\"gpt-4.1-2026-08-01\",\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"index\":0}]}\n\n".
        "data: [DONE]\n\n";

    $stream = createOpenAIStream($events);
    $chunks = iterator_to_array((new OpenAIStreamParser)->parse($stream, 'gpt-4.1'));

    expect($chunks[0]->getMetadata('model'))->toBe('gpt-4.1-2026-08-01')
        ->and($chunks[array_key_last($chunks)]->getMetadata('model'))->toBe('gpt-4.1-2026-08-01');

    fclose($stream);
});
