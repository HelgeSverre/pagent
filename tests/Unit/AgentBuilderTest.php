<?php

declare(strict_types=1);

use Pagent\AgentBuilder;
use Pagent\Registry;
use Pagent\Tools\FileRead;
use Pagent\Tools\FileWrite;
use Pagent\Tools\Glob;
use Pagent\Tools\Grep;

it('creates agent builders', function (): void {
    $builder = new AgentBuilder('test-agent');

    expect($builder)->toBeInstanceOf(AgentBuilder::class);
});

it('only registers an explicitly built agent', function (): void {
    expect(Registry::has('explicit-register'))->toBeFalse();

    (function (): void {
        $builder = new AgentBuilder('explicit-register');
        $builder->provider('mock');
    })();

    expect(Registry::has('explicit-register'))->toBeFalse();

    $agent = (new AgentBuilder('explicit-register'))
        ->provider('mock')
        ->build();

    expect(Registry::get('explicit-register'))->toBe($agent);
});

it('configures agent through builder', function (): void {
    $builder = new AgentBuilder('configured');
    $builder
        ->provider('mock')
        ->system('You are helpful')
        ->temperature(0.9)
        ->maxTokens(1500);

    $agent = $builder->build();

    expect($agent)->toBeAgent();
    expect($agent->getName())->toBe('configured');
});

it('supports provider configuration', function (): void {
    $builder = new AgentBuilder('with-config');

    $builder->provider('mock', [
        'responses' => ['test' => 'custom response'],
    ]);

    $agent = $builder->build();
    $response = $agent->prompt('test');

    expect($response->content)->toBe('custom response');
});

it('throws exception for unknown provider', function (): void {
    $builder = new AgentBuilder('test');

    expect(fn () => $builder->provider('unknown'))
        ->toThrow(InvalidArgumentException::class, 'Unknown provider: unknown');
});

it('forwards method calls to agent', function (): void {
    $builder = new AgentBuilder('forwarded');

    $builder
        ->provider('mock')
        ->system('System prompt')
        ->model('gpt-4.1-mini')
        ->temperature(0.5);

    $agent = $builder->build();

    // Agent should have been configured
    expect($agent)->toBeAgent();
});

it('accepts provider instances', function (): void {
    $mockProvider = mock(['test' => 'instance response']);

    $builder = new AgentBuilder('with-instance');
    $builder->provider($mockProvider);

    $agent = $builder->build();
    $response = $agent->prompt('test');

    expect($response->content)->toBe('instance response');
});

it('works with ollama helper function', function (): void {
    $ollamaProvider = ollama(['base_url' => 'http://test:11434', 'timeout' => 60]);

    $builder = new AgentBuilder('ollama-helper');
    $builder->provider($ollamaProvider);

    $agent = $builder->build();

    expect($agent)->toBeAgent();
});

it('works with anthropic helper function', function (): void {
    skipIfMissingAnthropicKey();

    $anthropicProvider = anthropic();

    $builder = new AgentBuilder('anthropic-helper');
    $builder->provider($anthropicProvider);

    $agent = $builder->build();

    expect($agent)->toBeAgent();
});

it('works with openai helper function', function (): void {
    skipIfMissingOpenAiKey();

    $openaiProvider = openai();

    $builder = new AgentBuilder('openai-helper');
    $builder->provider($openaiProvider);

    $agent = $builder->build();

    expect($agent)->toBeAgent();
});

it('works with opencode helper function', function (): void {
    $provider = opencode(['api_key' => 'test-key', 'gateway' => 'go']);

    $agent = (new AgentBuilder('opencode-helper'))
        ->provider($provider)
        ->build();

    expect($agent)->toBeAgent();
});

it('supports OpenCode Zen and Go provider names', function (string $name): void {
    $agent = (new AgentBuilder("{$name}-agent"))
        ->provider($name, ['api_key' => 'test-key'])
        ->build();

    expect($agent)->toBeAgent();
})->with(['opencode', 'opencode-zen', 'opencode-go']);

it('ignores config parameter when provider instance is passed', function (): void {
    $mockProvider = mock(['hello' => 'from instance']);

    $builder = new AgentBuilder('ignore-config');
    // Pass both instance and config - config should be ignored
    $builder->provider($mockProvider, ['responses' => ['hello' => 'from config']]);

    $agent = $builder->build();
    $response = $agent->prompt('hello');

    // Should use instance config, not the array config
    expect($response->content)->toBe('from instance');
});

it('maintains backward compatibility with string providers', function (): void {
    $builder = new AgentBuilder('backward-compat');
    $builder->provider('mock', ['responses' => ['test' => 'backward compatible']]);

    $agent = $builder->build();
    $response = $agent->prompt('test');

    expect($response->content)->toBe('backward compatible');
});

it('chains provider instance with other methods', function (): void {
    $mockProvider = mock(['question' => 'answer']);

    $builder = new AgentBuilder('chained');
    $builder
        ->provider($mockProvider)
        ->system('You are helpful')
        ->temperature(0.7);

    $agent = $builder->build();
    $response = $agent->prompt('question');

    expect($response->content)->toBe('answer');
    expect($agent)->toBeAgent();
});

it('adds multiple tools at once using tools() method', function (): void {
    $builder = new AgentBuilder('multi-tool');
    $builder->provider('mock');

    $tools = [
        new FileRead(baseDir: '/tmp'),
        new FileWrite(baseDir: '/tmp'),
        new Glob(baseDir: '/tmp'),
    ];

    $builder->tools($tools);

    $agent = $builder->build();
    $registeredTools = $agent->getTools();

    expect($registeredTools)->toHaveCount(3);
    expect($registeredTools[0])->toBeInstanceOf(FileRead::class);
    expect($registeredTools[1])->toBeInstanceOf(FileWrite::class);
    expect($registeredTools[2])->toBeInstanceOf(Glob::class);
});

it('chains tools() with individual tool() calls', function (): void {
    $builder = new AgentBuilder('chained-tools');
    $builder->provider('mock');

    $builder
        ->tools([
            new FileRead(baseDir: '/tmp'),
            new FileWrite(baseDir: '/tmp'),
        ])
        ->tool('custom', 'A custom tool', fn (string $input): string => strtoupper($input))
        ->tools([
            new Grep(baseDir: '/tmp'),
        ]);

    $agent = $builder->build();
    $registeredTools = $agent->getTools();

    expect($registeredTools)->toHaveCount(4);
    expect($registeredTools[0])->toBeInstanceOf(FileRead::class);
    expect($registeredTools[1])->toBeInstanceOf(FileWrite::class);
    expect($registeredTools[2]->name())->toBe('custom');
    expect($registeredTools[3])->toBeInstanceOf(Grep::class);
});

it('handles empty array in tools() method', function (): void {
    $builder = new AgentBuilder('empty-tools');
    $builder->provider('mock');

    $builder->tools([]);

    $agent = $builder->build();
    $registeredTools = $agent->getTools();

    expect($registeredTools)->toHaveCount(0);
});

it('verifies all tools are properly registered via tools() method', function (): void {
    $builder = new AgentBuilder('verify-tools');
    $builder->provider('mock');

    $fileRead = new FileRead(baseDir: '/project');
    $fileWrite = new FileWrite(baseDir: '/project');
    $grep = new Grep(baseDir: '/project');

    $builder->tools([$fileRead, $fileWrite, $grep]);

    $agent = $builder->build();
    $registeredTools = $agent->getTools();

    expect($registeredTools)->toContain($fileRead);
    expect($registeredTools)->toContain($fileWrite);
    expect($registeredTools)->toContain($grep);
});

it('maintains fluent interface with tools() method', function (): void {
    $builder = new AgentBuilder('fluent-tools');

    $result = $builder
        ->provider('mock')
        ->tools([
            new FileRead(baseDir: '/tmp'),
        ])
        ->system('You are helpful');

    expect($result)->toBe($builder);
});
