<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Closure;
use Generator;
use Pagent\Exceptions\LogicException;
use Pagent\Exceptions\RuntimeException;
use Pagent\Tool\ToolCallArgumentNormalizer;
use Pagent\Usage\UsageNormalizer;
use Throwable;

/**
 * A single-consumption LLM stream with explicit completion, failure, and
 * cancellation lifecycle hooks.
 *
 * The hooks let higher-level orchestration commit a conversation only after a
 * stream has completed, and roll it back when the consumer abandons it.
 */
final class StreamResponse
{
    private string $fullContent = '';

    private ?string $finalContent = null;

    /** @var list<StreamChunk> */
    private array $chunks = [];

    private int $chunkCount = 0;

    /** @var array<string, array{id: ?string, name: ?string, arguments: string}> */
    private array $toolCalls = [];

    private ?array $usage = null;

    private ?string $stopReason = null;

    private bool $consumed = false;

    private bool $settled = false;

    private bool $completing = false;

    private bool $cancelled = false;

    private bool $transportReleased = false;

    private ?Throwable $failure = null;

    private bool $sawTerminalChunk = false;

    /** @var list<Closure(self): void> */
    private array $completeHandlers = [];

    /** @var list<Closure(Throwable, self): void> */
    private array $errorHandlers = [];

    /** @var list<Closure(self): void> */
    private array $cancelHandlers = [];

    private readonly string $requestedModel;

    private string $actualModel;

    /** @var (Closure(): void)|null */
    private readonly ?Closure $canceller;

    /** @var (Closure(): void)|null */
    private readonly ?Closure $releaser;

    /**
     * @param  Generator<StreamChunk>  $stream
     * @param  null|callable(): void  $canceller
     * @param  null|callable(): void  $releaser
     */
    public function __construct(
        private readonly Generator $stream,
        private readonly string $provider,
        string $model,
        ?callable $canceller = null,
        private readonly bool $retainChunks = true,
        ?callable $releaser = null,
    ) {
        $this->requestedModel = $model;
        $this->actualModel = $model;
        $this->canceller = $canceller === null ? null : Closure::fromCallable($canceller);
        $this->releaser = $releaser === null ? null : Closure::fromCallable($releaser);
    }

    public function __destruct()
    {
        if (! $this->settled) {
            $this->cancel();
        }
    }

    /**
     * Get a lifecycle-aware stream generator.
     *
     * The response can be consumed exactly once. Use consume() when a policy
     * must inspect a chunk before it is delivered to a caller.
     *
     * @return Generator<StreamChunk>
     */
    public function getStream(): Generator
    {
        return $this->iterate();
    }

    /**
     * Consume the stream while optionally inspecting each chunk before it is
     * delivered. This is intended for output guards and other policies.
     *
     * @param  null|callable(StreamChunk): void  $beforeDelivery
     * @param  null|callable(StreamChunk): void  $onChunk
     */
    public function consume(?callable $beforeDelivery = null, ?callable $onChunk = null): void
    {
        foreach ($this->iterate() as $chunk) {
            if ($beforeDelivery !== null) {
                $beforeDelivery($chunk);
            }

            if ($onChunk !== null) {
                $onChunk($chunk);
            }
        }
    }

    /** Iterate through all chunks and collect full content. */
    public function collect(): string
    {
        $this->consume();

        return $this->fullContent;
    }

    /**
     * Stream chunks to a callback.
     *
     * For guarded delivery use consume($guard, $callback) so the guard runs
     * before user-visible output.
     *
     * @param  callable(StreamChunk): void  $callback
     */
    public function streamTo(callable $callback): void
    {
        $this->consume(onChunk: $callback);
    }

    /**
     * Register a callback that runs exactly once after natural completion.
     *
     * @param  callable(self): void  $handler
     */
    public function onComplete(callable $handler): self
    {
        $closure = Closure::fromCallable($handler);
        if ($this->settled && ! $this->cancelled && $this->failure === null) {
            $closure($this);
        } else {
            $this->completeHandlers[] = $closure;
        }

        return $this;
    }

    /**
     * Register a callback that runs exactly once when streaming fails.
     *
     * @param  callable(Throwable, self): void  $handler
     */
    public function onError(callable $handler): self
    {
        $closure = Closure::fromCallable($handler);
        if ($this->failure !== null) {
            $closure($this->failure, $this);
        } elseif (! $this->settled) {
            $this->errorHandlers[] = $closure;
        }

        return $this;
    }

    /**
     * Register a callback that runs exactly once when consumption is abandoned
     * or cancel() is called.
     *
     * @param  callable(self): void  $handler
     */
    public function onCancel(callable $handler): self
    {
        $closure = Closure::fromCallable($handler);
        if ($this->cancelled) {
            $closure($this);
        } elseif (! $this->settled) {
            $this->cancelHandlers[] = $closure;
        }

        return $this;
    }

    /** Abort a stream that is no longer needed and release its transport. */
    public function cancel(): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->cancelled = true;

        foreach ($this->cancelHandlers as $handler) {
            try {
                $handler($this);
            } catch (Throwable) {
                // Cancellation is best-effort for lifecycle observers, but every
                // handler must still run so the transport is always released.
            }
        }

        $this->invokeCanceller();
        $this->releaseTransport();
    }

    public function isComplete(): bool
    {
        return $this->settled && ! $this->cancelled && $this->failure === null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getFailure(): ?Throwable
    {
        return $this->failure;
    }

    public function getFullContent(): string
    {
        return $this->fullContent;
    }

    /** Final provider round content; equals full content for single-round streams. */
    public function getFinalContent(): string
    {
        return $this->finalContent ?? $this->fullContent;
    }

    /** @return list<StreamChunk> */
    public function getChunks(): array
    {
        return $this->chunks;
    }

    /** Number of chunks observed, including chunks omitted from retention. */
    public function getChunkCount(): int
    {
        return $this->chunkCount;
    }

    /**
     * Return completed, provider-neutral tool calls after the stream settles.
     *
     * @return list<array{id: string, name: string, arguments: array<string, mixed>, raw_arguments: string}>
     */
    public function getToolCalls(): array
    {
        $calls = [];

        foreach (array_values($this->toolCalls) as $index => $call) {
            if ($call['name'] === null || $call['name'] === '') {
                throw new RuntimeException('Streamed tool call is missing a function name.');
            }

            $rawArguments = $call['arguments'];
            $calls[] = [
                'id' => $call['id'] ?? 'call_stream_'.$index,
                'name' => $call['name'],
                'arguments' => $rawArguments === ''
                    ? []
                    : ToolCallArgumentNormalizer::normalize($rawArguments, "Streamed tool '{$call['name']}'"),
                'raw_arguments' => $rawArguments,
            ];
        }

        return $calls;
    }

    public function getUsage(): ?array
    {
        return $this->usage;
    }

    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->actualModel;
    }

    public function getRequestedModel(): string
    {
        return $this->requestedModel;
    }

    /** @return Generator<StreamChunk> */
    private function iterate(): Generator
    {
        if ($this->cancelled) {
            throw new LogicException('A cancelled StreamResponse cannot be consumed.');
        }

        if ($this->consumed) {
            throw new LogicException('A StreamResponse can only be consumed once.');
        }

        $this->consumed = true;

        try {
            foreach ($this->stream as $chunk) {
                $this->record($chunk);

                if ($chunk->isError()) {
                    $error = new RuntimeException('Provider stream failed: '.$chunk->content);
                    $this->fail($error);

                    throw $error;
                }

                if ($chunk->isEnd()) {
                    $this->complete();
                    yield $chunk;

                    return;
                }

                yield $chunk;

                if ($this->cancelled) {
                    return;
                }
            }

            if (! $this->sawTerminalChunk) {
                throw new RuntimeException('Provider stream ended without a terminal chunk.');
            }

            $this->complete();
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        } finally {
            if (! $this->settled) {
                $this->cancel();
            }
        }
    }

    private function record(StreamChunk $chunk): void
    {
        $this->chunkCount++;

        if ($chunk->isText()) {
            $this->fullContent .= $chunk->content;
        }

        if ($this->retainChunks) {
            $this->chunks[] = $chunk;
        }

        $reportedModel = $chunk->getMetadata('model');
        if (is_string($reportedModel) && $reportedModel !== '') {
            $this->actualModel = $reportedModel;
        }

        if ($chunk->isToolCall()) {
            $this->recordToolCall($chunk);
        }

        if ($chunk->isEnd()) {
            $this->sawTerminalChunk = true;
            $finalContent = $chunk->getMetadata('final_content');
            if (is_string($finalContent)) {
                $this->finalContent = $finalContent;
            }
            $usage = $chunk->getMetadata('usage');
            $this->usage = UsageNormalizer::normalize(is_array($usage) ? $usage : null);
            $this->stopReason = $chunk->getMetadata('stop_reason', $chunk->getMetadata('finish_reason'));
        }
    }

    private function complete(): void
    {
        if ($this->settled || $this->completing) {
            return;
        }

        $this->completing = true;
        $this->settled = true;

        try {
            foreach ($this->completeHandlers as $handler) {
                $handler($this);
            }

            $this->releaseTransport();
        } catch (Throwable $exception) {
            $this->settled = false;

            throw $exception;
        } finally {
            $this->completing = false;
        }
    }

    private function fail(Throwable $error): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->failure = $error;

        foreach ($this->errorHandlers as $handler) {
            try {
                $handler($error, $this);
            } catch (Throwable) {
                // Preserve the transport/parser failure seen by callers.
            }
        }

        $this->releaseTransport();
    }

    private function recordToolCall(StreamChunk $chunk): void
    {
        $id = $chunk->getMetadata('tool_call_id');
        $metadata = $chunk->metadata ?? [];
        $index = $metadata['output_index'] ?? $metadata['index'] ?? null;
        $round = $metadata['tool_round'] ?? 0;
        $key = 'round:'.(string) $round.':'.(
            is_int($index) || is_string($index)
                ? 'index:'.(string) $index
                : (is_string($id) && $id !== '' ? 'id:'.$id : 'index:0')
        );
        $call = $this->toolCalls[$key] ?? [
            'id' => is_string($id) && $id !== '' ? $id : uniqid('call_'),
            'name' => null,
            'arguments' => '',
        ];

        if (is_string($id) && $id !== '') {
            $call['id'] = $id;
        }

        $name = $chunk->getMetadata('tool_name');
        if (is_string($name) && $name !== '') {
            $call['name'] = $name;
        }

        $call['arguments'] = $chunk->getMetadata('arguments_complete', false)
            ? $chunk->content
            : $call['arguments'].$chunk->content;
        $this->toolCalls[$key] = $call;
    }

    private function releaseTransport(): void
    {
        if ($this->transportReleased) {
            return;
        }

        $this->transportReleased = true;

        if ($this->releaser === null) {
            return;
        }

        try {
            ($this->releaser)();
        } catch (Throwable) {
            // Settlement state and the original failure take precedence over a
            // best-effort transport cleanup error.
        }
    }

    private function invokeCanceller(): void
    {
        if ($this->canceller === null) {
            return;
        }

        try {
            ($this->canceller)();
        } catch (Throwable) {
            // Cancellation and lifecycle rollback remain best-effort, but the
            // generic releaser still needs to run afterwards.
        }
    }
}
