<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Generator;
use InvalidArgumentException;
use Pagent\Contracts\IdentifiedProvider;
use Pagent\Contracts\StreamingProvider;
use Pagent\ProviderCapabilities;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

use function mb_strlen;
use function str_split;

final class Mock implements IdentifiedProvider, StreamingProvider
{
    private array $responses = [];

    private int $chunkSize = 10;

    public function __construct(array $config = [])
    {
        $this->responses = $config['responses'] ?? [];
        $this->setChunkSize((int) ($config['chunk_size'] ?? 10));
    }

    public function prompt(string $message, array $options = []): object
    {
        // Check for predefined response
        $response = $this->responses[$message] ?? "Mock response to: {$message}";

        $inputTokens = mb_strlen($message);
        $outputTokens = mb_strlen($response);

        return (object) [
            'content' => $response,
            'model' => 'mock',
            'tokens' => $inputTokens + $outputTokens,
            'provider' => 'mock',
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }

    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $response = $this->responses[$message] ?? "Mock response to: {$message}";

        $generator = $this->createStreamGenerator($response, $message);

        return new StreamResponse($generator, 'mock', 'mock');
    }

    public function providerId(): string
    {
        return 'mock';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsStreaming: true,
            protocol: 'mock',
            toolProtocol: 'none',
        );
    }

    /**
     * @return Generator<StreamChunk>
     */
    private function createStreamGenerator(string $response, string $message): Generator
    {
        $inputTokens = mb_strlen($message);
        $outputTokens = mb_strlen($response);

        // Yield start chunk
        yield StreamChunk::start([
            'model' => 'mock',
            'provider' => 'mock',
        ]);

        // Yield text chunks (split response into chunks)
        $chunks = str_split($response, max(1, $this->chunkSize));
        foreach ($chunks as $chunk) {
            yield StreamChunk::text($chunk);
        }

        // Yield end chunk with usage stats
        yield StreamChunk::end([
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
            'stop_reason' => 'end_turn',
        ]);
    }

    public function setResponse(string $prompt, string $response): void
    {
        $this->responses[$prompt] = $response;
    }

    public function setChunkSize(int $size): void
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Mock stream chunk size must be at least 1.');
        }

        $this->chunkSize = $size;
    }
}
