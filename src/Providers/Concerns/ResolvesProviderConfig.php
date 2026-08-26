<?php

declare(strict_types=1);

namespace Pagent\Providers\Concerns;

use Pagent\Exceptions\ApiException;
use Pagent\Exceptions\ConfigurationException;
use Pagent\Http\HttpResponse;
use Pagent\Http\StreamTransport;
use Pagent\Tool\ToolCallArgumentNormalizer;
use Throwable;

use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function uniqid;

/**
 * Shared provider boilerplate: api-key/env resolution, HTTP error
 * translation to ApiException, and tool-call extraction.
 */
trait ResolvesProviderConfig
{
    abstract public function providerId(): string;

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveApiKey(array $config, string $envVar, string $label): string
    {
        $apiKey = $config['api_key'] ?? $_ENV[$envVar] ?? getenv($envVar) ?: '';

        if (! is_string($apiKey) || $apiKey === '') {
            throw new ConfigurationException("{$label} API key not configured");
        }

        return $apiKey;
    }

    /**
     * Translate a non-2xx buffered response into an ApiException.
     */
    private function throwApiError(HttpResponse $response, string $label): never
    {
        try {
            $data = $response->json();
        } catch (Throwable) {
            $data = [];
        }

        throw $this->apiException($data, $response->status, $label);
    }

    /**
     * Fail fast when a streaming request did not return a 2xx status.
     */
    private function ensureStreamSuccessful(StreamTransport $transport, string $label): void
    {
        $status = $transport->status();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $data = json_decode($transport->getContent(), true);

        throw $this->apiException(is_array($data) ? $data : [], $status, $label);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiException(array $data, ?int $statusCode, string $label): ApiException
    {
        $error = $data['error'] ?? null;
        $message = 'Unknown error';
        $errorType = null;

        if (is_string($error) && $error !== '') {
            $message = $error;
        } elseif (is_array($error)) {
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';
            $errorType = is_string($error['type'] ?? null) ? $error['type'] : null;
        }

        return new ApiException(
            "{$label} API error: {$message}",
            provider: $this->providerId(),
            statusCode: $statusCode,
            errorType: $errorType,
        );
    }

    /**
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    private function normalizeToolCall(mixed $id, mixed $name, mixed $arguments, string $label): array
    {
        $name = is_string($name) ? $name : 'unknown';

        return [
            'id' => is_string($id) && $id !== '' ? $id : uniqid('call_'),
            'name' => $name,
            'arguments' => ToolCallArgumentNormalizer::normalize($arguments, "{$label} tool '{$name}'"),
        ];
    }
}
