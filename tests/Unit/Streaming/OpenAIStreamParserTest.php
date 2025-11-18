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

test('it throws on malformed json', function (): void {
    $events = "data: {invalid json}\n\n";

    $stream = createOpenAIStream($events);
    $parser = new OpenAIStreamParser;

    expect(fn () => iterator_to_array($parser->parse($stream, 'gpt-4')))
        ->toThrow(RuntimeException::class, 'Failed to parse SSE data');

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
