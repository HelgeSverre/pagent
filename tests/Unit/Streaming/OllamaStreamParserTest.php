<?php

declare(strict_types=1);

use Pagent\Streaming\OllamaStreamParser;
use Pagent\Streaming\StreamChunk;

/**
 * Helper to create a mock stream from Ollama NDJSON
 */
function createOllamaStream(string $ndjson): mixed
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $ndjson);
    rewind($stream);

    return $stream;
}

test('it parses start chunk with role', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"\"},\"done\":false}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    expect($chunks)->not->toBeEmpty();
    expect($chunks[0]->isStart())->toBeTrue();

    fclose($stream);
});

test('it parses content chunks', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"Hello\"},\"done\":false}\n".
              "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"Hello World\"},\"done\":true}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    $textChunks = array_filter($chunks, fn ($c) => $c->isText());
    expect($textChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it parses done chunk with usage statistics', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"Test\"},\"done\":true,\"prompt_eval_count\":100,\"eval_count\":50}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    $endChunks = array_filter($chunks, fn ($c) => $c->isEnd());
    expect($endChunks)->not->toBeEmpty();

    $endChunk = array_values($endChunks)[0];
    expect($endChunk->getMetadata('usage')['prompt_tokens'])->toBe(100);
    expect($endChunk->getMetadata('usage')['completion_tokens'])->toBe(50);

    fclose($stream);
});

test('it parses tool calls', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"\",\"tool_calls\":[{\"id\":\"call_123\",\"function\":{\"name\":\"get_weather\",\"arguments\":\"{\\\"location\\\":\\\"London\\\"}\"}}]},\"done\":false}\n".
              "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"\"},\"done\":true}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    $toolCallChunks = array_filter($chunks, fn ($c) => $c->isToolCall());
    expect($toolCallChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it parses error responses', function (): void {
    $ndjson = "{\"error\":\"model not found\"}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->isError())->toBeTrue();
    expect($chunks[0]->content)->toBe('model not found');

    fclose($stream);
});

test('it stops parsing on error', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"Before\"},\"done\":false}\n".
              "{\"error\":\"something went wrong\"}\n".
              "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"After\"},\"done\":false}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    $errorChunks = array_filter($chunks, fn ($c) => $c->isError());
    expect($errorChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it stops parsing when done is true', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"Complete\"},\"done\":true}\n".
              "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"Should not appear\"},\"done\":false}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    $textChunks = array_values(array_filter($chunks, fn ($c) => $c->isText()));
    expect($textChunks)->toHaveCount(1);
    expect($textChunks[0]->content)->toBe('Complete');

    fclose($stream);
});

test('it throws on malformed json', function (): void {
    $ndjson = "{invalid json}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;

    expect(fn () => iterator_to_array($parser->parse($stream, 'qwen3:8b')))
        ->toThrow(RuntimeException::class, 'Failed to parse NDJSON data');

    fclose($stream);
});

test('it handles empty stream', function (): void {
    $stream = createOllamaStream('');
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    expect($chunks)->toBeEmpty();

    fclose($stream);
});

test('it handles chunks without message field', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"done\":false}\n".
              "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"Hello\"},\"done\":true}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    $textChunks = array_filter($chunks, fn ($c) => $c->isText());
    expect($textChunks)->not->toBeEmpty();

    fclose($stream);
});

test('it does not emit text chunk when content is empty', function (): void {
    $ndjson = "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"\"},\"done\":false}\n".
              "{\"model\":\"qwen3:8b\",\"message\":{\"role\":\"assistant\",\"content\":\"\"},\"done\":true}\n";

    $stream = createOllamaStream($ndjson);
    $parser = new OllamaStreamParser;
    $chunks = iterator_to_array($parser->parse($stream, 'qwen3:8b'));

    $textChunks = array_filter($chunks, fn ($c) => $c->isText());
    expect($textChunks)->toBeEmpty();

    fclose($stream);
});
