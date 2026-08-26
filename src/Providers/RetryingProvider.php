<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Closure;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\Provider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Exceptions\ApiException;
use Pagent\Exceptions\ConfigurationException;
use Pagent\Http\ConnectionException;
use Pagent\ProviderCapabilities;

use function usleep;

/**
 * Decorator that retries prompt() on retryable API errors and connection
 * failures with exponential backoff.
 *
 * Use {@see self::wrap()} when the caller should retain streaming support from
 * a streaming provider. Stream establishment is retried only until a
 * StreamResponse is returned; consumption failures are never replayed. A
 * plain RetryingProvider deliberately does not claim
 * StreamingProvider because PHP interfaces cannot be conditional at runtime.
 */
final class RetryingProvider implements IdentifiedProvider
{
    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /**
     * @param  null|callable(int): void  $sleeper  Receives the delay in milliseconds; defaults to usleep. Injectable for tests.
     */
    public function __construct(
        private readonly Provider $inner,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        ?callable $sleeper = null,
    ) {
        if ($maxAttempts < 1) {
            throw new ConfigurationException('RetryingProvider maxAttempts must be at least 1');
        }
        if ($baseDelayMs < 0) {
            throw new ConfigurationException('RetryingProvider baseDelayMs cannot be negative');
        }

        $this->sleeper = $sleeper !== null
            ? Closure::fromCallable($sleeper)
            : static function (int $delayMs): void {
                usleep($delayMs * 1000);
            };
    }

    /**
     * Preserve the wrapped provider's optional streaming capability without
     * advertising it for providers that cannot stream.
     *
     * @param  null|callable(int): void  $sleeper
     */
    public static function wrap(
        Provider $inner,
        int $maxAttempts = 3,
        int $baseDelayMs = 200,
        ?callable $sleeper = null,
    ): self|RetryingStreamingProvider {
        if ($inner instanceof StreamingProvider) {
            return new RetryingStreamingProvider($inner, $maxAttempts, $baseDelayMs, $sleeper);
        }

        return new self($inner, $maxAttempts, $baseDelayMs, $sleeper);
    }

    public function prompt(string $message, array $options = []): object
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->inner->prompt($message, $options);
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
        return $this->inner instanceof IdentifiedProvider
            ? $this->inner->providerId()
            : 'custom';
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->inner instanceof IdentifiedProvider
            ? $this->inner->capabilities()
            : new ProviderCapabilities;
    }
}
