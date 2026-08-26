<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Closure;
use Generator;
use Pagent\Exceptions\LogicException;
use Pagent\Exceptions\RuntimeException;
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

    /** @var list<StreamChunk> */
    private array $chunks = [];

    private ?array $usage = null;

    private ?string $stopReason = null;

    private bool $consumed = false;

    private bool $settled = false;

    private bool $completing = false;

    private bool $cancelled = false;

    private ?Throwable $failure = null;

    private bool $sawTerminalChunk = false;

    /** @var list<Closure(self): void> */
    private array $completeHandlers = [];

    /** @var list<Closure(Throwable, self): void> */
    private array $errorHandlers = [];

    /** @var list<Closure(self): void> */
    private array $cancelHandlers = [];

    /**
     * @param  Generator<StreamChunk>  $stream
     * @param  null|callable(): void  $canceller
     */
    public function __construct(
        private readonly Generator $stream,
        private readonly string $provider,
        private readonly string $model,
        ?callable $canceller = null,
    ) {
        if ($canceller !== null) {
            $this->cancelHandlers[] = static function (self $response) use ($canceller): void {
                $canceller();
            };
        }
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

    /** @return list<StreamChunk> */
    public function getChunks(): array
    {
        return $this->chunks;
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
        return $this->model;
    }

    /** @return Generator<StreamChunk> */
    private function iterate(): Generator
    {
        if ($this->consumed) {
            throw new LogicException('A StreamResponse can only be consumed once.');
        }

        $this->consumed = true;

        try {
            foreach ($this->stream as $chunk) {
                $this->record($chunk);
                yield $chunk;

                if ($chunk->isError()) {
                    throw new RuntimeException('Provider stream failed: '.$chunk->content);
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
        if ($chunk->isText()) {
            $this->fullContent .= $chunk->content;
        }

        $this->chunks[] = $chunk;

        if ($chunk->isEnd()) {
            $this->sawTerminalChunk = true;
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

        try {
            foreach ($this->completeHandlers as $handler) {
                $handler($this);
            }

            $this->settled = true;
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
    }
}
