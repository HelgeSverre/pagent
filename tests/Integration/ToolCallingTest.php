<?php

declare(strict_types=1);

/**
 * Tool Calling Integration Tests.
 *
 * These tests verify that tool calling works correctly with real providers.
 *
 * @group api
 */
describe('Tool Calling with OpenAI', function (): void {
    beforeEach(function (): void {
        if (empty($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'))) {
            $this->markTestSkipped('OPENAI_API_KEY not set');
        }
    });

    it('calls a simple calculator tool', function (): void {
        agent('calculator')
            ->provider('openai')
            ->system('You are a helpful assistant.')
            ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

        $response = agent('calculator')->prompt('What is 15 + 27?');

        expect($response->content)->toContain('42');
    });

    it('calls multiple tools in sequence', function (): void {
        agent('multi-tool')
            ->provider('openai')
            ->system('You are a helpful assistant.')
            ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b)
            ->tool('multiply', 'Multiply two numbers', fn (int $a, int $b): int => $a * $b);

        $response = agent('multi-tool')->prompt('What is 5 + 3, and then multiply the result by 2?');

        expect($response->content)->toContain('16');
    });

    it('handles tool with multiple parameters', function (): void {
        agent('weather')
            ->provider('openai')
            ->system('You are a weather assistant.')
            ->tool('get_weather', 'Get weather', fn (string $city, bool $include_forecast = false): string => "Weather in {$city}: Sunny".($include_forecast ? ', forecast: clear' : ''));

        $response = agent('weather')->prompt('What is the weather in Paris?');

        expect($response->content)
            ->toContain('Paris')
            ->toMatch('/sunny/i');
    });
});

describe('Tool Calling with Anthropic', function (): void {
    beforeEach(function (): void {
        if (empty($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'))) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }
    });

    it('calls a simple calculator tool', function (): void {
        agent('claude-calculator')
            ->provider('anthropic')
            ->system('You are a helpful assistant.')
            ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

        $response = agent('claude-calculator')->prompt('What is 25 + 17?');

        expect($response->content)->toContain('42');
    });

    it('calls multiple tools', function (): void {
        agent('claude-multi-tool')
            ->provider('anthropic')
            ->system('You are a helpful assistant.')
            ->tool('add', 'Add', fn (int $a, int $b): int => $a + $b)
            ->tool('subtract', 'Subtract', fn (int $a, int $b): int => $a - $b);

        $response = agent('claude-multi-tool')->prompt('What is 50 - 8?');

        expect($response->content)->toContain('42');
    });

    it('handles complex tool execution', function (): void {
        agent('claude-complex')
            ->provider('anthropic')
            ->system('You are a helpful assistant.')
            ->tool('calculate', 'Calculate', function (string $operation, int $a, int $b): int|float {
                return match ($operation) {
                    'add' => $a + $b,
                    'subtract' => $a - $b,
                    'multiply' => $a * $b,
                    'divide' => $b !== 0 ? $a / $b : 0,
                    default => 0,
                };
            });

        $response = agent('claude-complex')->prompt('What is 84 divided by 2?');

        expect($response->content)->toContain('42');
    });
});

describe('Tool Schema Generation', function (): void {
    it('generates correct Anthropic schema', function (): void {
        agent('schema-test')
            ->provider('anthropic')
            ->tool('test_tool', 'A test tool', fn (string $name, int $age, bool $active = true): string => '');

        $tools = agent('schema-test')->getTools();
        $schema = $tools[0]->toAnthropicSchema();

        expect($schema)->toHaveKey('name')
            ->and($schema['name'])->toBe('test_tool')
            ->and($schema)->toHaveKey('input_schema')
            ->and($schema['input_schema']['properties'])->toHaveKeys(['name', 'age', 'active'])
            ->and($schema['input_schema']['required'])->toBe(['name', 'age']);
    });

    it('generates correct OpenAI schema', function (): void {
        agent('openai-schema-test')
            ->provider('openai')
            ->tool('test_tool', 'A test tool', fn (string $name, int $age): string => '');

        $tools = agent('openai-schema-test')->getTools();
        $schema = $tools[0]->toOpenAISchema();

        expect($schema)->toHaveKey('type')
            ->and($schema['type'])->toBe('function')
            ->and($schema['function']['name'])->toBe('test_tool')
            ->and($schema['function']['parameters']['properties'])->toHaveKeys(['name', 'age'])
            ->and($schema['function']['parameters']['required'])->toBe(['name', 'age']);
    });
});
