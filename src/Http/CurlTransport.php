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
     * @param  string|null  $provider  Telemetry provider label; falls back to guessing from the URL host when unset.
     */
    public function __construct(
        private readonly ?string $provider = null,
    ) {}

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
                fclose($stream);
                throw new ConnectionException('Unable to initialise cURL');
            }

            $multi = curl_multi_init();
            $chunks = [];
            $headersReady = false;
            $completed = false;
            $closed = false;
            $status = 0;
            $info = [];
            $error = null;
            $bufferResponse = ($options['buffer_response'] ?? true) !== false;
            $idleTimeout = isset($options['idle_timeout']) && is_numeric($options['idle_timeout'])
                ? max(0, (int) $options['idle_timeout'])
                : 0;
            $lastActivityAt = microtime(true);

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => $options['user_agent'] ?? 'pagent-http-client/1.0',
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $ch, string $chunk) use ($stream, &$chunks, $bufferResponse, &$lastActivityAt): int {
                    $lastActivityAt = microtime(true);
                    $length = strlen($chunk);
                    $responseStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    // Providers consume successful responses incrementally, so
                    // retaining a second full copy is optional. Error bodies
                    // stay buffered so API exceptions remain informative.
                    if ($bufferResponse || $responseStatus < 200 || $responseStatus >= 300) {
                        $written = fwrite($stream, $chunk);
                        if ($written === false || $written !== $length) {
                            return 0;
                        }
                    }

                    $chunks[] = $chunk;

                    return $length;
                },
            ]);

            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            if ($headers !== []) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
            }

            $this->applyTimeouts($ch, $options, applyLowSpeedTimeout: false);

            curl_setopt(
                $ch,
                CURLOPT_HEADERFUNCTION,
                static function ($ch, string $header) use (&$headerBag, &$headersReady, &$status, &$lastActivityAt): int {
                    $lastActivityAt = microtime(true);
                    $trimmed = trim($header);
                    if ($trimmed === '') {
                        // cURL invokes the header callback once for every response
                        // in a redirect/interim chain. Only expose metadata after
                        // the final response headers have arrived.
                        $isInterim = $status >= 100 && $status < 200;
                        $isRedirect = $status >= 300
                            && $status < 400
                            && isset($headerBag['location']);
                        $headersReady = ! $isInterim && ! $isRedirect;

                        return strlen($header);
                    }

                    if (str_starts_with($trimmed, 'HTTP/')) {
                        $headerBag = [];
                        $headersReady = false;

                        if (preg_match('/^HTTP\\/\\S+\\s+(\\d{3})/', $trimmed, $matches) === 1) {
                            $status = (int) $matches[1];
                        }

                        return strlen($header);
                    }

                    if (! str_contains($trimmed, ':')) {
                        return strlen($header);
                    }

                    [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
                    $headerBag[strtolower($name)] = $value;

                    return strlen($header);
                }
            );

            if (curl_multi_add_handle($multi, $ch) !== CURLM_OK) {
                curl_multi_close($multi);
                curl_close($ch);
                fclose($stream);
                throw new ConnectionException('Unable to add cURL handle to multi transport.');
            }

            $finalize = static function (?Throwable $exception = null) use (
                $span,
                $multi,
                $ch,
                &$closed,
                &$info,
                &$status
            ): void {
                if ($closed) {
                    return;
                }

                $closed = true;
                $latestInfo = curl_getinfo($ch);
                if (is_array($latestInfo)) {
                    $info = $latestInfo;
                }

                if (isset($info['http_code']) && (int) $info['http_code'] > 0) {
                    $status = (int) $info['http_code'];
                }

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                curl_multi_close($multi);

                $span->setAttributes([
                    'http.status_code' => $status,
                    'http.response.content_length' => $info['size_download'] ?? 0,
                ]);

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus('error', $exception->getMessage());
                } else {
                    $span->setStatus($status >= 200 && $status < 300 ? 'ok' : 'error');
                }

                $span->end();
            };

            $advance = static function (bool $wait) use (
                $multi,
                $ch,
                &$completed,
                &$closed,
                &$info,
                &$error,
                &$lastActivityAt,
                $idleTimeout,
                $finalize
            ): void {
                if ($completed || $closed) {
                    return;
                }

                do {
                    $result = curl_multi_exec($multi, $running);
                } while ($result === CURLM_CALL_MULTI_PERFORM);

                if ($result !== CURLM_OK) {
                    $error = new ConnectionException('cURL multi transport failed.');
                    $completed = true;
                    $finalize($error);

                    return;
                }

                if ($wait && $running > 0) {
                    $selected = curl_multi_select($multi, 0.1);
                    if ($selected === -1) {
                        usleep(1_000);
                    }

                    do {
                        $result = curl_multi_exec($multi, $running);
                    } while ($result === CURLM_CALL_MULTI_PERFORM);

                    if ($result !== CURLM_OK) {
                        $error = new ConnectionException('cURL multi transport failed.');
                        $completed = true;
                        $finalize($error);

                        return;
                    }
                }

                if ($running > 0
                    && $idleTimeout > 0
                    && microtime(true) - $lastActivityAt >= $idleTimeout) {
                    $error = new ConnectionException("Stream received no data for {$idleTimeout} seconds.");
                    $completed = true;
                    $finalize($error);

                    return;
                }

                while (($message = curl_multi_info_read($multi)) !== false) {
                    $completed = true;
                    $latestInfo = curl_getinfo($ch);
                    if (is_array($latestInfo)) {
                        $info = $latestInfo;
                    }

                    if (($message['result'] ?? CURLE_OK) !== CURLE_OK) {
                        $curlError = curl_error($ch);
                        $error = new ConnectionException($curlError !== '' ? $curlError : 'Unknown cURL error');
                        $finalize($error);

                        return;
                    }

                    $finalize();
                }
            };

            $awaitHeaders = static function () use (&$headersReady, &$completed, &$error, $advance): void {
                while (! $headersReady && ! $completed) {
                    $advance(true);
                }

                if ($error !== null) {
                    throw $error;
                }
            };

            $nextChunk = static function () use (&$chunks, &$completed, &$error, $advance): ?string {
                while ($chunks === [] && ! $completed) {
                    $advance(true);
                }

                if ($chunks !== []) {
                    return array_shift($chunks);
                }

                if ($error !== null) {
                    throw $error;
                }

                return null;
            };

            $metadata = static function () use (&$status, &$headerBag, &$info, $ch, &$closed): array {
                if (! $closed) {
                    $latestInfo = curl_getinfo($ch);
                    if (is_array($latestInfo)) {
                        $info = $latestInfo;
                    }
                }

                if (isset($info['http_code']) && (int) $info['http_code'] > 0) {
                    $status = (int) $info['http_code'];
                }

                return [
                    'status' => $status,
                    'headers' => $headerBag,
                    'info' => $info,
                ];
            };

            return new StreamTransport(
                resource: $stream,
                status: 0,
                headers: [],
                nextChunk: $nextChunk,
                awaitHeaders: $awaitHeaders,
                metadata: $metadata,
                closeCallback: $finalize,
            );
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error', $e->getMessage());
            $span->end();
            throw $e;
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
    private function applyTimeouts(CurlHandle $ch, array $options, bool $applyLowSpeedTimeout = true): void
    {
        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $options['timeout']);
        }

        if (isset($options['connect_timeout']) && is_numeric($options['connect_timeout'])) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $options['connect_timeout']);
        }

        if ($applyLowSpeedTimeout
            && isset($options['idle_timeout'])
            && is_numeric($options['idle_timeout'])
            && (int) $options['idle_timeout'] > 0) {
            curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
            curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, (int) $options['idle_timeout']);
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
            provider: $this->provider ?? $this->extractProvider($url),
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
