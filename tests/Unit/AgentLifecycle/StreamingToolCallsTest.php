<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\Memory;
use Pagent\Contracts\Middleware;
use Pagent\Contracts\StreamingProvider;
use Pagent\Events\Events\Agent\AfterPromptEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\ProviderCapabilities;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

function streamingToolProvider(
    string $protocol = 'openai-chat-completions',
    string $firstRoundContent = '',
    bool $includeThinking = false,
): object {
    return new class($protocol, $firstRoundContent, $includeThinking) implements IdentifiedProvider, StreamingProvider
    {
        public int $calls = 0;

        /** @var list<array<string, mixed>> */
        public array $options = [];

        public ?StreamResponse $lastStream = null;

        public int $secondReleased = 0;

        public function __construct(
            private readonly string $protocol,
            private readonly string $firstRoundContent,
            private readonly bool $includeThinking,
        ) {}

        public function prompt(string $message, array $options = []): object
        {
            throw new LogicException('This fixture only supports streaming');
        }

        public function streamPrompt(string $message, array $options = []): StreamResponse
        {
            $this->calls++;
            $this->options[] = $options;
            $round = $this->calls;

            if ($round === 1) {
                $this->lastStream = new StreamResponse((function (): Generator {
                    yield StreamChunk::start([
                        'model' => 'tool-model-resolved',
                        'source_marker' => 'round-one',
                    ]);
                    if ($this->includeThinking) {
                        yield new StreamChunk('thinking_delta', 'Checking the arithmetic.');
                    }
                    if ($this->firstRoundContent !== '') {
                        yield StreamChunk::text($this->firstRoundContent, ['model' => 'tool-model-resolved']);
                    }
                    yield new StreamChunk('tool_call', '{"a":2,', metadata: [
                        'tool_call_id' => 'call_add',
                        'tool_name' => 'add',
                        'index' => 0,
                        'model' => 'tool-model-resolved',
                    ]);
                    yield new StreamChunk('tool_call', '"b":3}', metadata: [
                        'tool_call_id' => 'call_add',
                        'index' => 0,
                        'model' => 'tool-model-resolved',
                    ]);
                    yield StreamChunk::end([
                        'finish_reason' => 'tool_calls',
                        'model' => 'tool-model-resolved',
                        'terminal_marker' => 'round-one',
                        'usage' => ['input_tokens' => 2, 'output_tokens' => 1, 'total_tokens' => 3],
                    ]);
                })(), 'fixture', 'tool-model');

                return $this->lastStream;
            }

            $this->lastStream = new StreamResponse((function (): Generator {
                yield StreamChunk::start([
                    'model' => 'answer-model-resolved',
                    'source_marker' => 'round-two',
                ]);
                yield StreamChunk::text('The answer is 5.', ['model' => 'answer-model-resolved']);
                yield StreamChunk::end([
                    'finish_reason' => 'stop',
                    'model' => 'answer-model-resolved',
                    'terminal_marker' => 'round-two',
                    'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5],
                ]);
            })(), 'fixture', 'answer-model', function (): void {
                $this->secondReleased++;
            });

            return $this->lastStream;
        }

        public function providerId(): string
        {
            return 'fixture';
        }

        public function capabilities(): ProviderCapabilities
        {
            $anthropic = $this->protocol === 'anthropic-messages';

            return new ProviderCapabilities(
                supportsStreaming: true,
                supportsTools: true,
                supportsSystemMessages: true,
                protocol: $this->protocol,
                toolProtocol: $anthropic ? 'anthropic' : 'openai',
            );
        }
    };
}

test('streaming executes completed tool calls and continues with one public terminal chunk', function (): void {
    $provider = streamingToolProvider();
    $agent = (new Agent('stream-tools'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

    $response = $agent->stream('What is two plus three?');
    $chunks = iterator_to_array($response->getStream(), false);

    expect($provider->calls)->toBe(2)
        ->and(array_values(array_filter($chunks, fn (StreamChunk $chunk) => $chunk->isEnd())))->toHaveCount(1)
        ->and($response->getFullContent())->toBe('The answer is 5.')
        ->and($response->getModel())->toBe('answer-model-resolved')
        ->and($response->getUsage())->toMatchArray([
            'input_tokens' => 5,
            'output_tokens' => 3,
            'total_tokens' => 8,
        ])
        ->and($response->getToolCalls()[0])->toMatchArray([
            'id' => 'call_add',
            'name' => 'add',
            'arguments' => ['a' => 2, 'b' => 3],
        ])
        ->and($provider->options[1]['messages'])->toBe([
            ['role' => 'user', 'content' => 'What is two plus three?'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_add',
                    'type' => 'function',
                    'function' => ['name' => 'add', 'arguments' => '{"a":2,"b":3}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_add', 'content' => '5'],
        ])
        ->and($agent->messages[array_key_last($agent->messages)])->toBe([
            'role' => 'assistant',
            'content' => 'The answer is 5.',
        ]);
});

test('multi-round streams distinguish all delivered content from the final answer', function (): void {
    $provider = streamingToolProvider(firstRoundContent: 'Let me check. ');
    $completedContent = null;
    $afterPromptContent = null;
    $agent = (new Agent('stream-round-content'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);
    $agent->on('stream_completed', function (StreamCompletedEvent $event) use (&$completedContent): void {
        $completedContent = $event->fullContent;
    });
    $agent->on('after_prompt', function (AfterPromptEvent $event) use (&$afterPromptContent): void {
        $afterPromptContent = $event->response;
    });

    $response = $agent->stream('What is two plus three?');

    expect($response->collect())->toBe('Let me check. The answer is 5.')
        ->and($response->getFullContent())->toBe('Let me check. The answer is 5.')
        ->and($response->getFinalContent())->toBe('The answer is 5.')
        ->and($completedContent)->toBe('Let me check. The answer is 5.')
        ->and($afterPromptContent)->toBe('The answer is 5.')
        ->and($agent->messages[1]['content'])->toBe('Let me check. ')
        ->and($agent->messages[array_key_last($agent->messages)]['content'])->toBe('The answer is 5.');
});

test('manual streaming tool mode exposes calls without executing them', function (): void {
    $provider = streamingToolProvider();
    $executions = 0;
    $agent = (new Agent('manual-stream-tools'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', function (int $a, int $b) use (&$executions): int {
            $executions++;

            return $a + $b;
        });

    $response = $agent->stream('Call add', ['tool_mode' => 'manual']);
    $response->collect();

    expect($provider->calls)->toBe(1)
        ->and($executions)->toBe(0)
        ->and($response->getToolCalls()[0]['arguments'])->toBe(['a' => 2, 'b' => 3])
        ->and($provider->options[0])->not->toHaveKey('tool_mode')
        ->and($agent->messages)->toBe([
            ['role' => 'user', 'content' => 'Call add'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_add',
                    'type' => 'function',
                    'function' => ['name' => 'add', 'arguments' => '{"a":2,"b":3}'],
                ]],
            ],
        ]);
});

test('manual streaming tool calls can continue with external results', function (): void {
    $provider = streamingToolProvider();
    $afterPrompt = [];
    $agent = (new Agent('continued-stream-tools'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);
    $agent->on('after_prompt', function (AfterPromptEvent $event) use (&$afterPrompt): void {
        $afterPrompt[] = [$event->message, $event->response];
    });

    $pending = $agent->stream('Call add externally', [
        'tool_mode' => 'manual',
        'model' => 'requested-model',
        'temperature' => 0.2,
        'retain_chunks' => false,
    ]);
    $pending->collect();

    expect($afterPrompt)->toBe([])
        ->and(fn () => $agent->stream('another prompt'))
        ->toThrow(RuntimeException::class, 'pending manual streamed tool calls');

    $continued = $agent->continueToolCalls(
        $pending,
        ['call_add' => 5],
        ['max_tokens' => 99],
    );

    expect($continued->collect())->toBe('The answer is 5.')
        ->and($provider->calls)->toBe(2)
        ->and($provider->options[0]['retain_chunks'])->toBeFalse()
        ->and($provider->options[1])->toMatchArray([
            'model' => 'requested-model',
            'temperature' => 0.2,
            'max_tokens' => 99,
            'retain_chunks' => false,
        ])
        ->and($provider->options[1]['messages'])->toBe([
            ['role' => 'user', 'content' => 'Call add externally'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_add',
                    'type' => 'function',
                    'function' => ['name' => 'add', 'arguments' => '{"a":2,"b":3}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_add', 'content' => '5'],
        ])
        ->and($agent->messages[array_key_last($agent->messages)])->toBe([
            'role' => 'assistant',
            'content' => 'The answer is 5.',
        ])
        ->and($afterPrompt)->toBe([['Call add externally', 'The answer is 5.']]);
});

test('manual streaming continuation validates results and can be discarded atomically', function (): void {
    $provider = streamingToolProvider();
    $agent = (new Agent('discarded-stream-tools'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);
    $pending = $agent->stream('Call add', ['tool_mode' => 'manual']);
    $pending->collect();

    expect(fn () => $agent->continueToolCalls($pending, []))
        ->toThrow(InvalidArgumentException::class, "Missing external result for tool call 'call_add'")
        ->and(fn () => $agent->continueToolCalls($pending, [
            'call_add' => 5,
            'call_other' => 9,
        ]))->toThrow(InvalidArgumentException::class, "Unexpected external result for tool call 'call_other'");

    $agent->discardToolCalls($pending);

    expect($agent->messages)->toBe([])
        ->and($agent->stream('fresh prompt')->collect())->toBe('The answer is 5.');
});

test('manual streaming defers durable history until results complete the turn', function (): void {
    $memory = new class implements Memory
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $sessions = [
            'manual' => [['role' => 'system', 'content' => 'Existing history']],
        ];

        public function load(string $sessionId): array
        {
            return $this->sessions[$sessionId] ?? [];
        }

        public function save(string $sessionId, array $messages): void
        {
            $this->sessions[$sessionId] = $messages;
        }

        public function delete(string $sessionId): void
        {
            unset($this->sessions[$sessionId]);
        }

        public function exists(string $sessionId): bool
        {
            return isset($this->sessions[$sessionId]);
        }

        public function prune(string $sessionId, int $maxMessages): array
        {
            return $this->sessions[$sessionId] = array_slice(
                $this->sessions[$sessionId] ?? [],
                -$maxMessages,
            );
        }
    };
    $provider = streamingToolProvider();
    $agent = (new Agent('durable-manual-stream-tools'))
        ->provider($provider)
        ->memory($memory)
        ->sessionId('manual')
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

    $pending = $agent->stream('Call add', ['tool_mode' => 'manual']);
    $pending->collect();

    expect($memory->sessions['manual'])->toBe([
        ['role' => 'system', 'content' => 'Existing history'],
    ])
        ->and(fn () => $agent->sessionId('other'))
        ->toThrow(RuntimeException::class, 'pending manual streamed tool calls')
        ->and(fn () => $agent->provider($provider))
        ->toThrow(RuntimeException::class, 'pending manual streamed tool calls');

    $agent->continueToolCalls($pending, ['call_add' => 5])->collect();

    expect($memory->sessions['manual'])->toBe($agent->messages)
        ->and($memory->sessions)->not->toHaveKey('other');
});

test('failed manual completion observers do not leave an unusable pending turn', function (): void {
    $provider = streamingToolProvider();
    $agent = (new Agent('failed-manual-stream-observer'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);
    $agent->on('stream_completed', function (): void {
        throw new RuntimeException('observer failed');
    });

    $response = $agent->stream('Call add', ['tool_mode' => 'manual']);

    expect(fn () => $response->collect())
        ->toThrow(RuntimeException::class, 'observer failed')
        ->and($agent->messages)->toBe([]);

    $fresh = $agent->stream('fresh prompt');
    $fresh->cancel();

    expect($fresh->isCancelled())->toBeTrue();
});

test('none streaming tool mode omits schemas and rejects unexpected tool calls', function (): void {
    $provider = streamingToolProvider();
    $agent = (new Agent('no-stream-tools'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

    $response = $agent->stream('Do not use tools', ['tool_mode' => 'none']);

    expect(fn () => $response->collect())
        ->toThrow(RuntimeException::class, 'tool_mode is disabled')
        ->and($provider->options[0])->not->toHaveKeys(['tools', 'tool_mode'])
        ->and($agent->messages)->toBe([]);
});

test('automatic streaming rejects tool calls when no executable tools are registered', function (): void {
    $provider = streamingToolProvider();
    $agent = (new Agent('missing-stream-tools'))->provider($provider);
    $response = $agent->stream('Try to call an unavailable tool');

    expect(fn () => $response->collect())
        ->toThrow(RuntimeException::class, 'no registered tools')
        ->and($agent->messages)->toBe([]);
});

test('cancelling during a tool follow-up releases the active provider round', function (): void {
    $provider = streamingToolProvider();
    $agent = (new Agent('cancel-stream-tools'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);
    $response = $agent->stream('What is two plus three?');

    foreach ($response->getStream() as $chunk) {
        if ($chunk->isText()) {
            $response->cancel();
            break;
        }
    }

    expect($response->isCancelled())->toBeTrue()
        ->and($provider->calls)->toBe(2)
        ->and($provider->lastStream?->isCancelled())->toBeTrue()
        ->and($provider->secondReleased)->toBe(1)
        ->and($agent->messages)->toBe([]);
});

test('stream middleware can transform text without discarding completed tool calls', function (): void {
    $provider = streamingToolProvider();
    $middleware = new class implements Middleware
    {
        public int $beforeCalls = 0;

        public int $afterCalls = 0;

        public function before(string $message, array $options): array
        {
            $this->beforeCalls++;

            return $options;
        }

        public function after(object $response): object
        {
            $this->afterCalls++;
            $response->content = $response->content === '' ? '' : strtoupper($response->content);

            return $response;
        }
    };
    $agent = (new Agent('middleware-stream-tools'))
        ->provider($provider)
        ->middleware($middleware)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

    $response = $agent->stream('What is two plus three?');

    expect($response->collect())->toBe('THE ANSWER IS 5.')
        ->and($provider->calls)->toBe(2)
        ->and($middleware->beforeCalls)->toBe(2)
        ->and($middleware->afterCalls)->toBe(2)
        ->and($response->getToolCalls()[0]['name'])->toBe('add');
});

test('stream middleware tool-call transformations drive both execution and public chunks', function (): void {
    $provider = streamingToolProvider();
    $executedArguments = null;
    $middleware = new class implements Middleware
    {
        public function before(string $message, array $options): array
        {
            return $options;
        }

        public function after(object $response): object
        {
            if ($response->tool_calls !== []) {
                $response->tool_calls[0]['arguments'] = ['a' => 4, 'b' => 6];
            }

            return $response;
        }
    };
    $agent = (new Agent('middleware-stream-tool-transform'))
        ->provider($provider)
        ->middleware($middleware)
        ->tool('add', 'Add two numbers', function (int $a, int $b) use (&$executedArguments): int {
            $executedArguments = compact('a', 'b');

            return $a + $b;
        });

    $response = $agent->stream('What is two plus three?');
    $response->collect();

    expect($executedArguments)->toBe(['a' => 4, 'b' => 6])
        ->and($response->getToolCalls()[0]['arguments'])->toBe(['a' => 4, 'b' => 6])
        ->and($provider->options[1]['messages'][2])->toBe([
            'role' => 'tool',
            'tool_call_id' => 'call_add',
            'content' => '10',
        ]);
});

test('buffered streaming preserves non-text ordering and provider metadata', function (): void {
    $provider = streamingToolProvider(firstRoundContent: 'Let me check. ', includeThinking: true);
    $middleware = new class implements Middleware
    {
        public function before(string $message, array $options): array
        {
            return $options;
        }

        public function after(object $response): object
        {
            if ($response->tool_calls !== []) {
                $response->content = '';
            }

            return $response;
        }
    };
    $agent = (new Agent('buffered-stream-order'))
        ->provider($provider)
        ->middleware($middleware)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

    $response = $agent->stream('What is two plus three?');
    $chunks = iterator_to_array($response->getStream(), false);

    expect(array_map(fn (StreamChunk $chunk): string => $chunk->type, $chunks))->toBe([
        'start',
        'thinking_delta',
        'tool_call_done',
        'start',
        'text',
        'done',
    ])
        ->and($chunks[0]->getMetadata('source_marker'))->toBe('round-one')
        ->and($chunks[3]->getMetadata('source_marker'))->toBe('round-two')
        ->and($chunks[5]->getMetadata('terminal_marker'))->toBe('round-two');
});

test('streaming tool rounds preserve Anthropic content-block history', function (): void {
    $provider = streamingToolProvider('anthropic-messages');
    $agent = (new Agent('anthropic-stream-tools'))
        ->provider($provider)
        ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

    $agent->stream('What is two plus three?')->collect();

    expect($provider->options[1]['messages'])->toBe([
        ['role' => 'user', 'content' => 'What is two plus three?'],
        ['role' => 'assistant', 'content' => [[
            'type' => 'tool_use',
            'id' => 'call_add',
            'name' => 'add',
            'input' => ['a' => 2, 'b' => 3],
        ]]],
        ['role' => 'user', 'content' => [[
            'type' => 'tool_result',
            'tool_use_id' => 'call_add',
            'content' => '5',
        ]]],
    ]);
});
