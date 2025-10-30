<?php

declare(strict_types=1);

namespace Pagent\Http;

use CurlHandle;
use Pagent\Observability\NullSpan;
use Pagent\Observability\Span;
use Pagent\Observability\TelemetryManager;
use Throwable;

final class CurlTransport implements HttpClientInterface
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|string|null  $json
     * @param  array<string, mixed>  $options
     */
    public function requestJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): HttpResponse {
        $span = $this->startSpan($url, $json, $method);

        try {
            $headerBag = [];
            $payload = $this->preparePayload($json);

            $ch = curl_init($url);
            if ($ch === false) {
                throw new ConnectionException('Unable to initialise cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => $options['user_agent'] ?? 'pagent-http-client/1.0',
            ]);

            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            if ($headers !== []) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
            }

            $this->applyTimeouts($ch, $options);

            curl_setopt(
                $ch,
                CURLOPT_HEADERFUNCTION,
                static function ($ch, string $header) use (&$headerBag): int {
                    $trimmed = trim($header);
                    if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/') || ! str_contains($trimmed, ':')) {
                        return strlen($header);
                    }

                    [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
                    $headerBag[strtolower($name)] = $value;

                    return strlen($header);
                }
            );

            $body = curl_exec($ch);
            $info = curl_getinfo($ch);

            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new ConnectionException($error !== '' ? $error : 'Unknown cURL error');
            }

            if (! is_string($body)) {
                curl_close($ch);
                throw new ConnectionException('Unexpected response payload type from cURL.');
            }

            if ($info === false) {
                $info = [];
            }

            curl_close($ch);

            $response = new HttpResponse(
                status: isset($info['http_code']) ? (int) $info['http_code'] : 0,
                headers: $headerBag,
                body: $body,
                info: $info
            );

            $span->setAttributes([
                'http.status_code' => $response->status,
                'http.response.content_length' => strlen($response->body),
            ]);

            $span->setStatus($response->isSuccessful() ? 'ok' : 'error');

            return $response;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error', $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|string|null  $json
     * @param  array<string, mixed>  $options
     */
    public function streamJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): StreamTransport {
        $span = $this->startSpan($url, $json, $method);

        try {
            $payload = $this->preparePayload($json);
            $headerBag = [];
            $stream = fopen('php://temp', 'w+b');

            if ($stream === false) {
                throw new ConnectionException('Unable to open temporary stream');
            }

            $ch = curl_init($url);
            if ($ch === false) {
                throw new ConnectionException('Unable to initialise cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => $options['user_agent'] ?? 'pagent-http-client/1.0',
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($stream): int {
                    $written = fwrite($stream, $chunk);

                    return $written === false ? 0 : $written;
                },
            ]);

            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            if ($headers !== []) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
            }

            $this->applyTimeouts($ch, $options);

            curl_setopt(
                $ch,
                CURLOPT_HEADERFUNCTION,
                static function ($ch, string $header) use (&$headerBag): int {
                    $trimmed = trim($header);
                    if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/') || ! str_contains($trimmed, ':')) {
                        return strlen($header);
                    }

                    [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
                    $headerBag[strtolower($name)] = $value;

                    return strlen($header);
                }
            );

            $result = curl_exec($ch);
            $info = curl_getinfo($ch);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);
                fclose($stream);
                throw new ConnectionException($error !== '' ? $error : 'Unknown cURL error');
            }

            if ($info === false) {
                $info = [];
            }

            curl_close($ch);
            rewind($stream);

            $transport = new StreamTransport(
                resource: $stream,
                status: isset($info['http_code']) ? (int) $info['http_code'] : 0,
                headers: $headerBag,
                info: $info
            );

            $span->setAttributes([
                'http.status_code' => $transport->status(),
                'http.response.content_length' => $transport->info()['size_download'] ?? 0,
            ]);

            $span->setStatus(
                $transport->status() >= 200 && $transport->status() < 300 ? 'ok' : 'error'
            );

            return $transport;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error', $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = sprintf('%s: %s', $key, $value);
        }

        return $formatted;
    }

    /**
     * @param  array<string, mixed>|string|null  $json
     */
    private function preparePayload(array|string|null $json): ?string
    {
        if ($json === null) {
            return null;
        }

        if (is_string($json)) {
            return $json;
        }

        return json_encode($json, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function applyTimeouts(CurlHandle $ch, array $options): void
    {
        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $options['timeout']);
        }

        if (isset($options['connect_timeout']) && is_numeric($options['connect_timeout'])) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $options['connect_timeout']);
        }
    }

    /**
     * @param  array<string, mixed>|string|null  $json
     */
    private function startSpan(string $url, array|string|null $json, string $method): Span|NullSpan|NullSpanShim
    {
        $telemetry = TelemetryManager::instance();

        if (! $telemetry->isEnabled()) {
            return new NullSpanShim;
        }

        $model = null;

        if (is_array($json) && isset($json['model']) && is_string($json['model'])) {
            $model = $json['model'];
        }

        return $telemetry->startLLMSpan(
            provider: $this->extractProvider($url),
            model: $model ?? 'unknown',
            attributes: [
                'http.method' => $method,
                'http.url' => $url,
            ]
        );
    }

    private function extractProvider(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? $host : '';

        return match (true) {
            str_contains($host, 'openai') => 'openai',
            str_contains($host, 'anthropic') => 'anthropic',
            str_contains($host, 'ollama') => 'ollama',
            default => $host ?: 'unknown',
        };
    }
}

/**
 * Lightweight shim that mirrors the Span API we rely on without adding a hard dependency.
 *
 * @internal
 */
final class NullSpanShim
{
    public function setAttributes(array $attributes): void {}

    public function setStatus(string $status, ?string $description = null): void {}

    public function recordException(Throwable $throwable): void {}

    public function end(): void {}
}
