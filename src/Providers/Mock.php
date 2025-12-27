<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Generator;
use Pagent\Contracts\Provider;
use Pagent\Streaming\StreamChunk;
use Pagent\Streaming\StreamResponse;

use function mb_strlen;
use function str_split;

final class Mock implements Provider
{
    private array $responses = [];

    private int $chunkSize = 10;

    public function __construct(array $config = [])
    {
        $this->responses = $config['responses'] ?? [];
        $this->chunkSize = $config['chunk_size'] ?? 10;
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
        $chunks = str_split($response, $this->chunkSize);
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
        $this->chunkSize = $size;
    }
}
