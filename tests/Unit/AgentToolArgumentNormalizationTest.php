<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Providers\Mock;

/**
 * Tests for Agent's tool call argument normalization.
 *
 * The normalizeToolCallArguments() method ensures consistent handling
 * of tool arguments across different provider formats.
 */
describe('Tool Call Argument Normalization', function (): void {
    it('normalizes OpenAI format with arguments key', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        // Use reflection to test private method
        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
            'arguments' => ['param1' => 'value1', 'param2' => 42],
        ];

        $result = $method->invoke($agent, $toolCall);

        expect($result)->toBe(['param1' => 'value1', 'param2' => 42]);
    });

    it('normalizes Anthropic format with input key', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'toolu_123',
            'name' => 'test_tool',
            'input' => ['param1' => 'value1', 'param2' => 42],
        ];

        $result = $method->invoke($agent, $toolCall);

        expect($result)->toBe(['param1' => 'value1', 'param2' => 42]);
    });

    it('decodes JSON string arguments', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
            'arguments' => '{"param1":"value1","param2":42}',
        ];

        $result = $method->invoke($agent, $toolCall);

        expect($result)->toBe(['param1' => 'value1', 'param2' => 42]);
    });

    it('handles empty arguments gracefully', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
        ];

        $result = $method->invoke($agent, $toolCall);

        expect($result)->toBe([]);
    });

    it('rejects invalid JSON with tool context', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
            'arguments' => '{"invalid json',
        ];

        expect(fn () => $method->invoke($agent, $toolCall))
            ->toThrow(RuntimeException::class, "Tool call 'test_tool' arguments must be a valid JSON object");
    });

    it('handles null arguments', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
            'arguments' => null,
        ];

        $result = $method->invoke($agent, $toolCall);

        expect($result)->toBe([]);
    });

    it('rejects unexpected argument types with tool context', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
            'arguments' => 123, // Invalid type
        ];

        expect(fn () => $method->invoke($agent, $toolCall))
            ->toThrow(RuntimeException::class, "Tool call 'test_tool' arguments must be an object or JSON object string, got int");
    });

    it('prefers arguments over input when both present', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
            'arguments' => ['from' => 'arguments'],
            'input' => ['from' => 'input'],
        ];

        $result = $method->invoke($agent, $toolCall);

        expect($result)->toBe(['from' => 'arguments']);
    });

    it('handles complex nested structures', function (): void {
        $agent = new Agent('test');
        $agent->provider(new Mock);

        $reflection = new ReflectionClass($agent);
        $method = $reflection->getMethod('normalizeToolCallArguments');
        $method->setAccessible(true);

        $complexArgs = [
            'simple' => 'string',
            'number' => 42,
            'nested' => [
                'level1' => [
                    'level2' => 'deep value',
                ],
            ],
            'array' => [1, 2, 3],
        ];

        $toolCall = [
            'id' => 'call_123',
            'name' => 'test_tool',
            'arguments' => $complexArgs,
        ];

        $result = $method->invoke($agent, $toolCall);

        expect($result)->toBe($complexArgs);
    });
});
