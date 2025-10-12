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

        return (object) [
            'content' => $response,
            'model' => 'mock',
            'tokens' => mb_strlen($message) + mb_strlen($response),
            'provider' => 'mock',
        ];
    }

    public function setResponse(string $prompt, string $response): void
    {
        $this->responses[$prompt] = $response;
    }
}
