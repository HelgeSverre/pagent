<?php

declare(strict_types=1);

use Pagent\Tool\ToolCallArgumentNormalizer;

test('tool call arguments normalize arrays and JSON objects consistently', function (): void {
    expect(ToolCallArgumentNormalizer::normalize(['city' => 'Oslo']))
        ->toBe(['city' => 'Oslo'])
        ->and(ToolCallArgumentNormalizer::normalize('{"city":"Oslo"}'))
        ->toBe(['city' => 'Oslo'])
        ->and(ToolCallArgumentNormalizer::normalize(null))
        ->toBe([]);
});

test('tool call arguments reject malformed and non-object payloads with context', function (): void {
    expect(fn () => ToolCallArgumentNormalizer::normalize('{broken', "OpenAI tool 'weather'"))
        ->toThrow(RuntimeException::class, "OpenAI tool 'weather' arguments must be a valid JSON object")
        ->and(fn () => ToolCallArgumentNormalizer::normalize('42', "OpenAI tool 'weather'"))
        ->toThrow(RuntimeException::class, "OpenAI tool 'weather' arguments must be a valid JSON object")
        ->and(fn () => ToolCallArgumentNormalizer::normalize(42, "OpenAI tool 'weather'"))
        ->toThrow(RuntimeException::class, "OpenAI tool 'weather' arguments must be an object or JSON object string");
});
