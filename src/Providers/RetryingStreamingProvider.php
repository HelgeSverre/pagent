<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
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

    /**
     * @param  null|callable(int): void  $sleeper
     */
    public function __construct(
        private readonly StreamingProvider $inner,
        int $maxAttempts = 3,
        int $baseDelayMs = 200,
        ?callable $sleeper = null,
    ) {
        $this->retrying = new RetryingProvider($inner, $maxAttempts, $baseDelayMs, $sleeper);
    }

    public function prompt(string $message, array $options = []): object
    {
        return $this->retrying->prompt($message, $options);
    }

    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        return $this->inner->streamPrompt($message, $options);
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
