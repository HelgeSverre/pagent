<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Closure;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Exceptions\ApiException;
use Pagent\Http\ConnectionException;
use Pagent\ProviderCapabilities;
use Pagent\Streaming\StreamResponse;

/**
 * Streaming-capable retry decorator.
 *
 * Completed prompts use the retry policy. Streams are delegated exactly once:
 * replaying a partially consumed stream could duplicate output or tool calls.
 */
final class RetryingStreamingProvider implements IdentifiedProvider, StreamingProvider
{
    private readonly RetryingProvider $retrying;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /**
     * @param  null|callable(int): void  $sleeper
     */
    public function __construct(
        private readonly StreamingProvider $inner,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        ?callable $sleeper = null,
    ) {
        $this->retrying = new RetryingProvider($inner, $maxAttempts, $baseDelayMs, $sleeper);
        $this->sleeper = $sleeper !== null
            ? Closure::fromCallable($sleeper)
            : static function (int $delayMs): void {
                usleep($delayMs * 1000);
            };
    }

    public function prompt(string $message, array $options = []): object
    {
        return $this->retrying->prompt($message, $options);
    }

    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                // Built-in providers await and validate response headers before
                // returning StreamResponse. Retrying here is therefore safe:
                // no output has been observable yet.
                return $this->inner->streamPrompt($message, $options);
            } catch (ApiException $e) {
                if (! $e->isRetryable() || $attempt >= $this->maxAttempts) {
                    throw $e;
                }
            } catch (ConnectionException $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }
            }

            ($this->sleeper)($this->baseDelayMs * (2 ** ($attempt - 1)));
        }
    }

    public function providerId(): string
    {
        return $this->retrying->providerId();
    }

    public function capabilities(): ProviderCapabilities
    {
        $capabilities = $this->retrying->capabilities();

        return new ProviderCapabilities(
            supportsStreaming: true,
            supportsTools: $capabilities->supportsTools,
            supportsSystemMessages: $capabilities->supportsSystemMessages,
            supportsStructuredOutput: $capabilities->supportsStructuredOutput,
            protocol: $capabilities->protocol,
            toolProtocol: $capabilities->toolProtocol,
        );
    }
}
