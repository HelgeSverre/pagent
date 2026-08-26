<?php

declare(strict_types=1);

use Pagent\Contracts\Provider;
use Pagent\Contracts\StreamingProvider;
use Pagent\Exceptions\ApiException;
use Pagent\Http\ConnectionException;
use Pagent\Providers\Mock;
use Pagent\Providers\RetryingProvider;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

/**
 * @param  list<Throwable|object>  $results  exceptions to throw, or a response to return
 */
function flakyProvider(array $results): Provider
{
    return new class($results) implements Provider
    {
        public int $calls = 0;

        public function __construct(private array $results) {}

        public function prompt(string $message, array $options = []): object
        {
            $this->calls++;
            $result = array_shift($this->results);

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }
    };
}

it('retries retryable api errors and returns the eventual response', function (): void {
    $inner = flakyProvider([
        new ApiException('overloaded', provider: 'test', statusCode: 529),
        new ApiException('rate limited', provider: 'test', statusCode: 429),
        (object) ['content' => 'ok'],
    ]);

    $delays = [];
    $provider = new RetryingProvider($inner, maxAttempts: 3, baseDelayMs: 100, sleeper: function (int $ms) use (&$delays): void {
        $delays[] = $ms;
    });

    $response = $provider->prompt('hello');

    expect($response->content)->toBe('ok')
        ->and($inner->calls)->toBe(3)
        ->and($delays)->toBe([100, 200]);
});

it('does not retry non-retryable api errors', function (): void {
    $inner = flakyProvider([
        new ApiException('bad key', provider: 'test', statusCode: 401),
        (object) ['content' => 'never reached'],
    ]);

    $provider = new RetryingProvider($inner, sleeper: fn (int $ms) => null);

    expect(fn () => $provider->prompt('hello'))->toThrow(ApiException::class, 'bad key')
        ->and($inner->calls)->toBe(1);
});

it('retries connection failures and rethrows the last exception when attempts are exhausted', function (): void {
    $inner = flakyProvider([
        new ConnectionException('timeout 1'),
        new ConnectionException('timeout 2'),
        new ConnectionException('timeout 3'),
    ]);

    $provider = new RetryingProvider($inner, maxAttempts: 3, sleeper: fn (int $ms) => null);

    expect(fn () => $provider->prompt('hello'))->toThrow(ConnectionException::class, 'timeout 3')
        ->and($inner->calls)->toBe(3);
});

it('preserves identity, capabilities, and streaming through the wrapper factory', function (): void {
    $provider = RetryingProvider::wrap(new Mock(['responses' => ['hi' => 'hello there']]));

    expect($provider)->toBeInstanceOf(StreamingProvider::class)
        ->and($provider->providerId())->toBe('mock')
        ->and($provider->capabilities()->supportsStreaming)->toBeTrue()
        ->and($provider->streamPrompt('hi')->collect())->toBe('hello there');
});

it('does not advertise streaming for a non-streaming inner provider', function (): void {
    $provider = RetryingProvider::wrap(flakyProvider([(object) ['content' => 'ok']]));

    expect($provider)->not->toBeInstanceOf(StreamingProvider::class)
        ->and($provider->providerId())->toBe('custom')
        ->and($provider->capabilities()->supportsStreaming)->toBeFalse();
});

it('retains streaming for providers that do not implement the optional identity contract', function (): void {
    $inner = new class implements StreamingProvider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) ['content' => 'complete'];
        }

        public function streamPrompt(string $message, array $options = []): StreamResponse
        {
            $stream = (function (): Generator {
                yield StreamChunk::start();
                yield StreamChunk::text('streamed');
                yield StreamChunk::end();
            })();

            return new StreamResponse($stream, 'custom', 'custom');
        }
    };

    $provider = RetryingProvider::wrap($inner);

    expect($provider)->toBeInstanceOf(StreamingProvider::class)
        ->and($provider->capabilities()->supportsStreaming)->toBeTrue()
        ->and($provider->streamPrompt('hi')->collect())->toBe('streamed');
});
