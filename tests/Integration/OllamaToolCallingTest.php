<?php

declare(strict_types=1);
use Pagent\Tools\FileRead;

/**
 * Ollama Tool Calling Integration Tests.
 *
 * These tests verify that tool calling works correctly with Ollama provider.
 *
 * Note: Tool calling requires models that support function calling.
 * Recommended models: qwen3:8b (good tool support), llama3.1, mistral
 *
 * @group api
 * @group ollama
 */
describe('Tool Calling with Ollama', function (): void {
    beforeEach(function (): void {
        skipIfMissingOllama();
        skipIfMissingOllamaModel('qwen3:8b');
    });

    it('calls a simple calculator tool', function (): void {
        agent('ollama-calculator')
            ->provider(ollama(['timeout' => 120]))
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('add', 'Add two numbers together', fn (int $a, int $b): int => $a + $b);

        $response = agent('ollama-calculator')->prompt('What is 15 + 27?');

        expect($response->content)->toContain('42');
    });

    it('calls multiple tools in sequence', function (): void {
        agent('ollama-multi-tool')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b)
            ->tool('multiply', 'Multiply two numbers', fn (int $a, int $b): int => $a * $b);

        $response = agent('ollama-multi-tool')->prompt('What is 5 + 3, and then multiply the result by 2?');

        expect($response->content)->toContain('16');
    });

    it('handles tool with multiple parameters', function (): void {
        agent('ollama-weather')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a weather assistant. Use the tools provided.')
            ->tool('get_weather', 'Get weather for a city', fn (string $city, bool $include_forecast = false): string => "Weather in {$city}: Sunny".($include_forecast ? ', forecast: clear' : ''));

        $response = agent('ollama-weather')->prompt('What is the weather in Paris?');

        expect($response->content)
            ->toContain('Paris')
            ->toMatch('/sunny/i');
    });

    it('handles complex tool execution', function (): void {
        agent('ollama-complex')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('calculate', 'Perform calculations', function (string $operation, int $a, int $b): int|float {
                return match ($operation) {
                    'add' => $a + $b,
                    'subtract' => $a - $b,
                    'multiply' => $a * $b,
                    'divide' => $b !== 0 ? $a / $b : 0,
                    default => 0,
                };
            });

        $response = agent('ollama-complex')->prompt('What is 84 divided by 2?');

        expect($response->content)->toContain('42');
    });

    it('handles tool returning array data', function (): void {
        agent('ollama-data-tool')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('get_user', 'Get user data', fn (int $id): array => [
                'id' => $id,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ]);

        $response = agent('ollama-data-tool')->prompt('Get the user with ID 123');

        expect($response->content)
            ->toContain('John Doe');
    });

    it('uses OpenAI-compatible tool schema', function (): void {
        agent('ollama-schema-test')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->tool('test_tool', 'A test tool', fn (string $name, int $age, bool $active = true): string => 'test');

        $tools = agent('ollama-schema-test')->getTools();
        $schema = $tools[0]->toOpenAISchema();

        expect($schema)->toHaveKey('type')
            ->and($schema['type'])->toBe('function')
            ->and($schema)->toHaveKey('function')
            ->and($schema['function']['name'])->toBe('test_tool')
            ->and($schema['function']['parameters']['properties'])->toHaveKeys(['name', 'age', 'active'])
            ->and($schema['function']['parameters']['required'])->toBe(['name', 'age']);
    });

    it('handles tool calls in multi-turn conversations', function (): void {
        agent('ollama-conversation')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b)
            ->tool('subtract', 'Subtract two numbers', fn (int $a, int $b): int => $a - $b);

        // First calculation
        $response1 = agent('ollama-conversation')->prompt('What is 10 + 5?');
        expect($response1->content)->toContain('15');

        // Second calculation in same conversation
        $response2 = agent('ollama-conversation')->prompt('Now subtract 3 from that result');

        expect($response2->content)->toMatch('/12|subtract/i');
    });

    it('handles built-in FileRead tool', function (): void {
        // Create a temporary test file
        $testFile = sys_get_temp_dir().'/ollama_tool_test.txt';
        file_put_contents($testFile, 'This is a test file for Ollama tool calling.');

        agent('ollama-file-reader')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool(new FileRead);

        $response = agent('ollama-file-reader')->prompt("Read the file at {$testFile} and tell me what it says");

        expect($response->content)->toContain('test file');

        // Cleanup
        @unlink($testFile);
    });

    it('handles tool errors gracefully', function (): void {
        agent('ollama-error-tool')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('divide', 'Divide two numbers', function (int $a, int $b): float {
                if ($b === 0) {
                    throw new RuntimeException('Division by zero');
                }

                return $a / $b;
            });

        // This should trigger the tool but handle the error
        $response = agent('ollama-error-tool')->prompt('What is 10 divided by 0?');

        // The agent should receive the error and respond appropriately
        expect($response->content)->toMatch('/error|cannot|zero/i');
    });
});

describe('Tool Calling with gpt-oss model', function (): void {
    beforeEach(function (): void {
        skipIfMissingOllama();
        skipIfMissingOllamaModel('gpt-oss:20b');
    });

    it('calls calculator tool with gpt-oss', function (): void {
        agent('ollama-gpt-oss-calc')
            ->provider('ollama')
            ->model('gpt-oss:20b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

        $response = agent('ollama-gpt-oss-calc')->prompt('What is 20 + 22?');

        expect($response->content)->toContain('42');
    })->skip('gpt-oss:20b may not support tool calling - test as needed');
});

describe('Ollama Tool Arguments Normalization', function (): void {
    beforeEach(function (): void {
        skipIfMissingOllama();
        skipIfMissingOllamaModel('qwen3:8b');
    });

    it('normalizes tool arguments correctly', function (): void {
        $called = false;
        $receivedArgs = [];

        agent('ollama-norm-test')
            ->provider('ollama')
            ->model('qwen3:8b')
            ->system('You are a helpful assistant. Use the tools provided.')
            ->tool('test_tool', 'Test tool', function (int $a, int $b) use (&$called, &$receivedArgs): int {
                $called = true;
                $receivedArgs = ['a' => $a, 'b' => $b];

                return $a + $b;
            });

        $response = agent('ollama-norm-test')->prompt('Use test_tool with a=5 and b=10');

        expect($called)->toBeTrue()
            ->and($receivedArgs)->toHaveKeys(['a', 'b']);
    });
});

describe('Tool Calling with qwen2.5-coder:7b', function (): void {
    beforeEach(function (): void {
        skipIfMissingOllama();
        skipIfMissingOllamaModel('qwen2.5-coder:7b');
    });

    it('calls code generation tool with qwen2.5-coder', function (): void {
        agent('ollama-coder')
            ->provider('ollama')
            ->model('qwen2.5-coder:7b')
            ->system('You are a coding assistant. Use the provided tools to help with code generation.')
            ->tool('generate_function', 'Generate a PHP function with given name and parameters', function (string $functionName, array $parameters): string {
                $params = implode(', ', array_map(fn ($p) => "\${$p}", $parameters));

                return "function {$functionName}({$params}) {\n    // TODO: Implement\n    return null;\n}";
            });

        $response = agent('ollama-coder')->prompt('Generate a function called "calculateSum" that takes "a" and "b" as parameters');

        expect($response->content)->toContain('calculateSum')
            ->and($response->content)->toMatch('/function|calculate/i');
    });
});
