<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Contracts\Memory;
use Pagent\Contracts\Middleware;
use Pagent\Contracts\OutputGuard;
use Pagent\Contracts\Provider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Events\Events\Agent\AfterPromptEvent;
use Pagent\Events\Events\Agent\BeforePromptEvent;
use Pagent\Events\Events\Stream\StreamChunkEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Exceptions\GuardException;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

test('switching session ids on a reusable agent loads and persists isolated conversations', function (): void {
    $memory = new class implements Memory
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $sessions = [
            'alpha' => [
                ['role' => 'user', 'content' => 'alpha history'],
                ['role' => 'assistant', 'content' => 'alpha reply'],
            ],
            'beta' => [
                ['role' => 'user', 'content' => 'beta history'],
                ['role' => 'assistant', 'content' => 'beta reply'],
            ],
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
            return $this->sessions[$sessionId] = array_slice($this->sessions[$sessionId] ?? [], -$maxMessages);
        }
    };

    $provider = new class implements Provider
    {
        /** @var array<int, array<string, mixed>> */
        public array $optionsByCall = [];

        public function prompt(string $message, array $options = []): object
        {
            $this->optionsByCall[] = $options;

            return (object) ['content' => "answer: {$message}"];
        }
    };

    $agent = (new Agent('reused-agent'))
        ->provider($provider)
        ->memory($memory)
        ->sessionId('alpha');

    $agent->prompt('alpha question');
    $agent->sessionId('beta')->prompt('beta question');

    $expectedBetaHistory = [
        ['role' => 'user', 'content' => 'beta history'],
        ['role' => 'assistant', 'content' => 'beta reply'],
        ['role' => 'user', 'content' => 'beta question'],
    ];

    expect($provider->optionsByCall[1]['messages'])->toBe($expectedBetaHistory)
        ->and($agent->messages)->toBe([
            ...$expectedBetaHistory,
            ['role' => 'assistant', 'content' => 'answer: beta question'],
        ])
        ->and($memory->sessions['alpha'][0]['content'])->toBe('alpha history')
        ->and($memory->sessions['beta'][0]['content'])->toBe('beta history');
});

test('session instances retain definition-level event behavior', function (): void {
    $afterPrompt = [];
    $definition = (new Agent('session-definition'))
        ->provider(new class implements Provider
        {
            public function prompt(string $message, array $options = []): object
            {
                return (object) ['content' => "answer: {$message}"];
            }
        });

    $definition->on('after_prompt', function (AfterPromptEvent $event) use (&$afterPrompt): void {
        $afterPrompt[] = [$event->agent->getSessionId(), $event->response];
    });

    $definition->forSession('customer-1')->prompt('hello');

    expect($afterPrompt)->toBe([['customer-1', 'answer: hello']])
        ->and($definition->messages)->toBe([]);
});

test('collecting a stream commits the same turn lifecycle as prompt', function (): void {
    $memory = new class implements Memory
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $sessions = [];

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
            return $this->sessions[$sessionId] = array_slice($this->sessions[$sessionId] ?? [], -$maxMessages);
        }
    };

    $provider = new class implements StreamingProvider
    {
        public function prompt(string $message, array $options = []): object
        {
            throw new LogicException('streaming test must not use prompt()');
        }

        public function streamPrompt(string $message, array $options = []): StreamResponse
        {
            $stream = (function (): Generator {
                yield StreamChunk::start();
                yield StreamChunk::text('streamed ');
                yield StreamChunk::text('answer');
                yield StreamChunk::end(['usage' => ['input_tokens' => 2, 'output_tokens' => 2, 'total_tokens' => 4]]);
            })();

            return new StreamResponse($stream, 'fake-stream', 'fake-model');
        }
    };

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

            return $response;
        }
    };

    $afterPrompt = 0;
    $completed = 0;
    $deliveredChunks = [];
    $beforePromptMessages = [];
    $agent = (new Agent('stream-lifecycle'))
        ->provider($provider)
        ->memory($memory)
        ->sessionId('stream-session')
        ->middleware($middleware);

    $agent->on('after_prompt', function (AfterPromptEvent $event) use (&$afterPrompt): void {
        $afterPrompt++;
    });
    $agent->on('before_prompt', function (BeforePromptEvent $event) use (&$beforePromptMessages): void {
        $beforePromptMessages = $event->agent->messages;
    });
    $agent->on('stream_completed', function (StreamCompletedEvent $event) use (&$completed): void {
        $completed++;
    });
    $agent->on('stream_chunk', function (StreamChunkEvent $event) use (&$deliveredChunks): void {
        $deliveredChunks[$event->chunkNumber] = $event->chunk;
    });

    $content = $agent->stream('give an answer')->collect();

    $expectedMessages = [
        ['role' => 'user', 'content' => 'give an answer'],
        ['role' => 'assistant', 'content' => 'streamed answer'],
    ];

    expect($content)->toBe('streamed answer')
        ->and($agent->messages)->toBe($expectedMessages)
        ->and($memory->sessions['stream-session'])->toBe($expectedMessages)
        ->and($middleware->beforeCalls)->toBe(1)
        ->and($middleware->afterCalls)->toBe(1)
        ->and($afterPrompt)->toBe(1)
        ->and($completed)->toBe(1)
        ->and($deliveredChunks)->toBe([1 => 'streamed ', 2 => 'answer'])
        ->and($beforePromptMessages)->toBe([
            ['role' => 'user', 'content' => 'give an answer'],
        ]);
});

test('streamTo never passes rejected output to its callback', function (): void {
    $rejectingGuard = new class implements OutputGuard
    {
        public function check(string $input, string $output): bool
        {
            return false;
        }

        public function checkOutput(string $output): bool
        {
            return false;
        }

        public function supportsIncrementalInspection(): bool
        {
            return false;
        }

        public function getName(): string
        {
            return 'reject-stream-output';
        }

        public function getViolationMessage(): string
        {
            return 'Unsafe streamed output';
        }
    };

    $provider = new class implements StreamingProvider
    {
        public function prompt(string $message, array $options = []): object
        {
            throw new LogicException('streaming test must not use prompt()');
        }

        public function streamPrompt(string $message, array $options = []): StreamResponse
        {
            $stream = (function (): Generator {
                yield StreamChunk::text('secret');
                yield StreamChunk::end();
            })();

            return new StreamResponse($stream, 'fake-stream', 'fake-model');
        }
    };

    $received = [];
    $agent = (new Agent('safe-stream'))
        ->provider($provider)
        ->guard($rejectingGuard);

    expect(fn () => $agent->streamTo('request', function (StreamChunk $chunk) use (&$received): void {
        $received[] = $chunk->content;
    }))->toThrow(GuardException::class, 'Unsafe streamed output');

    expect($received)->toBe([])
        ->and($agent->messages)->toBe([]);
});

test('streamTo atomically commits a fallback without delivering rejected output', function (): void {
    $rejectingGuard = new class implements OutputGuard
    {
        public function check(string $input, string $output): bool
        {
            return false;
        }

        public function checkOutput(string $output): bool
        {
            return false;
        }

        public function supportsIncrementalInspection(): bool
        {
            return false;
        }

        public function getName(): string
        {
            return 'reject-stream-output';
        }

        public function getViolationMessage(): string
        {
            return 'Unsafe streamed output';
        }
    };
    $provider = new class implements StreamingProvider
    {
        public function prompt(string $message, array $options = []): object
        {
            throw new LogicException('streaming test must not use prompt()');
        }

        public function streamPrompt(string $message, array $options = []): StreamResponse
        {
            return new StreamResponse((function (): Generator {
                yield StreamChunk::text('rejected output');
                yield StreamChunk::end();
            })(), 'fake-stream', 'fake-model');
        }
    };
    $memory = new class implements Memory
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $sessions = [];

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
            return $this->sessions[$sessionId] = array_slice($this->sessions[$sessionId] ?? [], -$maxMessages);
        }
    };
    $received = [];
    $afterPrompt = 0;
    $agent = (new Agent('fallback-stream'))
        ->provider($provider)
        ->guard($rejectingGuard)
        ->fallback(fn (GuardException $exception): string => 'safe fallback')
        ->memory($memory)
        ->sessionId('fallback-session');
    $agent->on('after_prompt', function () use (&$afterPrompt): void {
        $afterPrompt++;
    });

    $result = $agent->streamTo('request', function (StreamChunk $chunk) use (&$received): void {
        $received[] = $chunk->content;
    });

    $expectedMessages = [
        ['role' => 'user', 'content' => 'request'],
        ['role' => 'assistant', 'content' => 'safe fallback'],
    ];

    expect($result)->toBe('safe fallback')
        ->and($received)->toBe([])
        ->and($agent->messages)->toBe($expectedMessages)
        ->and($memory->sessions['fallback-session'])->toBe($expectedMessages)
        ->and($afterPrompt)->toBe(1);
});

test('streamTo does not mistake a callback GuardException for a policy failure', function (): void {
    $provider = new class implements StreamingProvider
    {
        public function prompt(string $message, array $options = []): object
        {
            throw new LogicException('streaming test must not use prompt()');
        }

        public function streamPrompt(string $message, array $options = []): StreamResponse
        {
            return new StreamResponse((function (): Generator {
                yield StreamChunk::text('safe output');
                yield StreamChunk::end();
            })(), 'fake-stream', 'fake-model');
        }
    };
    $fallbackCalls = 0;
    $agent = (new Agent('callback-failure'))
        ->provider($provider)
        ->fallback(function () use (&$fallbackCalls): string {
            $fallbackCalls++;

            return 'fallback';
        });

    expect(fn () => $agent->streamTo('request', function (): never {
        throw new GuardException('consumer failure', 'consumer', '', '');
    }))->toThrow(GuardException::class, 'consumer failure');

    expect($fallbackCalls)->toBe(0)
        ->and($agent->messages)->toBe([]);
});

test('output guards inspect middleware-transformed stream content before delivery', function (): void {
    $provider = new class implements StreamingProvider
    {
        public function prompt(string $message, array $options = []): object
        {
            throw new LogicException('streaming test must not use prompt()');
        }

        public function streamPrompt(string $message, array $options = []): StreamResponse
        {
            return new StreamResponse((function (): Generator {
                yield StreamChunk::text('safe provider output');
                yield StreamChunk::end();
            })(), 'fake-stream', 'fake-model');
        }
    };
    $middleware = new class implements Middleware
    {
        public function before(string $message, array $options): array
        {
            return $options;
        }

        public function after(object $response): object
        {
            return (object) ['content' => 'unsafe transformed output'];
        }
    };
    $guard = new class implements OutputGuard
    {
        public function check(string $input, string $output): bool
        {
            return $this->checkOutput($output);
        }

        public function checkOutput(string $output): bool
        {
            return ! str_contains($output, 'unsafe');
        }

        public function supportsIncrementalInspection(): bool
        {
            return false;
        }

        public function getName(): string
        {
            return 'transformed-output';
        }

        public function getViolationMessage(): string
        {
            return 'Transformed output is unsafe';
        }
    };

    $received = [];
    $agent = (new Agent('transformed-safe-stream'))
        ->provider($provider)
        ->middleware($middleware)
        ->guard($guard);

    expect(fn () => $agent->streamTo('request', function (StreamChunk $chunk) use (&$received): void {
        $received[] = $chunk->content;
    }))->toThrow(GuardException::class, 'Transformed output is unsafe');

    expect($received)->toBe([])
        ->and($agent->messages)->toBe([]);
});
