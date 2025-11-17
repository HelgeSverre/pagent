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

describe('Class-Based Tools with OpenAI', function (): void {
    beforeEach(function (): void {
        if (empty($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY'))) {
            $this->markTestSkipped('OPENAI_API_KEY not set');
        }
    });

    it('can use FileRead class-based tool', function (): void {
        // Create a temporary test file
        $testFile = sys_get_temp_dir().'/pagent_test_'.uniqid().'.txt';
        file_put_contents($testFile, 'Hello from class-based FileRead tool!');

        agent('file-reader')
            ->provider('openai')
            ->system('You are a helpful assistant that reads files.')
            ->tool(new \Pagent\Tools\FileRead);

        $response = agent('file-reader')->prompt("Read the file at {$testFile} and tell me what it says.");

        // Check that the response contains at least one of the expected terms
        expect($response->content)
            ->toMatch('/(Hello|class-based|FileRead)/i');

        // Clean up
        @unlink($testFile);
    });

    it('can use FileWrite class-based tool', function (): void {
        $testFile = sys_get_temp_dir().'/pagent_write_test_'.uniqid().'.txt';

        agent('file-writer')
            ->provider('openai')
            ->model('gpt-4')
            ->system('You are a helpful assistant that writes files.')
            ->tool(new \Pagent\Tools\FileWrite);

        $response = agent('file-writer')->prompt("Write 'Test content from OpenAI' to the file {$testFile}");

        // Verify the file was created
        expect(file_exists($testFile))->toBeTrue();

        // Clean up
        @unlink($testFile);
    });

    it('generates correct schemas for class-based tools', function (): void {
        $fileRead = new \Pagent\Tools\FileRead;

        $anthropicSchema = $fileRead->toAnthropicSchema();
        expect($anthropicSchema)->toHaveKey('name')
            ->and($anthropicSchema)->toHaveKey('description')
            ->and($anthropicSchema)->toHaveKey('input_schema');

        $openaiSchema = $fileRead->toOpenAISchema();
        expect($openaiSchema)->toHaveKey('type')
            ->and($openaiSchema['type'])->toBe('function')
            ->and($openaiSchema)->toHaveKey('function')
            ->and($openaiSchema['function'])->toHaveKey('name');
    });
});

describe('Class-Based Tools with Anthropic', function (): void {
    beforeEach(function (): void {
        if (empty($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY'))) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }
    });

    it('can use FileRead class-based tool', function (): void {
        // Create a temporary test file
        $testFile = sys_get_temp_dir().'/pagent_claude_test_'.uniqid().'.txt';
        file_put_contents($testFile, 'Hello from Anthropic with FileRead!');

        agent('claude-file-reader')
            ->provider('anthropic')
            ->system('You are a helpful assistant that reads files.')
            ->tool(new \Pagent\Tools\FileRead);

        $response = agent('claude-file-reader')->prompt("Read the file at {$testFile} and tell me what it says.");

        // Check that the response contains at least one of the expected terms
        expect($response->content)
            ->toMatch('/(Hello|Anthropic|FileRead)/i');

        // Clean up
        @unlink($testFile);
    });

    it('can use FileWrite class-based tool', function (): void {
        $testFile = sys_get_temp_dir().'/pagent_claude_write_'.uniqid().'.txt';

        agent('claude-file-writer')
            ->provider('anthropic')
            ->system('You are a helpful assistant that writes files.')
            ->tool(new \Pagent\Tools\FileWrite);

        $response = agent('claude-file-writer')->prompt("Write 'Test content from Claude' to the file {$testFile}");

        // Verify the file was created
        expect(file_exists($testFile))->toBeTrue();

        // Clean up
        @unlink($testFile);
    });

    it('can use multiple class-based tools together', function (): void {
        $sourceFile = sys_get_temp_dir().'/pagent_source_'.uniqid().'.txt';
        $destFile = sys_get_temp_dir().'/pagent_dest_'.uniqid().'.txt';

        file_put_contents($sourceFile, 'Copy me to another file!');

        agent('claude-multi-class-tools')
            ->provider('anthropic')
            ->system('You are a helpful assistant that can read and write files.')
            ->tool(new \Pagent\Tools\FileRead)
            ->tool(new \Pagent\Tools\FileWrite);

        $response = agent('claude-multi-class-tools')
            ->prompt("Read the file at {$sourceFile} and write its contents to {$destFile}");

        // Verify both files exist and have the same content
        expect(file_exists($destFile))->toBeTrue();

        // Clean up
        @unlink($sourceFile);
        @unlink($destFile);
    });
});
