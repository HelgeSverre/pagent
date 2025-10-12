<?php

declare(strict_types=1);

namespace Pagent\Middleware;

use Pagent\Contracts\Middleware;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function mb_strlen;

final class LoggingMiddleware implements Middleware
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function before(string $message, array $options): array
    {
        $this->logger->info('Agent prompt initiated', [
            'message' => $message,
            'model' => $options['model'] ?? null,
            'temperature' => $options['temperature'] ?? null,
        ]);

        return $options;
    }

    public function after(object $response): object
    {
        $this->logger->info('Agent response received', [
            'provider' => $response->provider ?? null,
            'model' => $response->model ?? null,
            'tokens' => $response->tokens ?? 0,
            'content_length' => mb_strlen($response->content ?? ''),
        ]);

        return $response;
    }
}
