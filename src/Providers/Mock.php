<?php

declare(strict_types=1);

namespace Pagent\Providers;

use Pagent\Contracts\Provider;

use function mb_strlen;

final class Mock implements Provider
{
    private array $responses = [];

    public function __construct(array $config = [])
    {
        $this->responses = $config['responses'] ?? [];
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

    public function setResponse(string $prompt, string $response): void
    {
        $this->responses[$prompt] = $response;
    }
}
