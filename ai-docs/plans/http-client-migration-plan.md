# HTTP Client Migration Plan

**Created:** October 30, 2025  
**Updated:** October 30, 2025  
**Status:** Planning  
**Effort:** Small-Medium (2-3 days total)  
**Priority:** High (Foundation for telemetry and maintainability)

---

## Executive Summary

**Goal:** Replace scattered `curl_*` calls with a clean, testable HTTP client abstraction that enables better telemetry integration, testing, and error handling.

**Decision:** **Custom cURL Wrapper** - Build minimal `Pagent\Http\CurlTransport` (~50 lines of core code)

**Migration Strategy:** Gradual, provider-by-provider with feature flags

**Why Custom cURL?**

- ✅ Perfect streaming control with `CURLOPT_WRITEFUNCTION`
- ✅ Zero new dependencies (ext-curl already used)
- ✅ Rich telemetry via `curl_getinfo()` (DNS, connect, TLS, transfer timing)
- ✅ Native resource handles for existing parsers
- ✅ Minimal code (~50 lines core logic)
- ✅ Complete control for future features

---

## Current State Analysis

### curl\_\* Usage Patterns

**Providers Using curl:**

- `src/Providers/Anthropic.php` (prompt + streamPrompt)
- `src/Providers/OpenAI.php` (prompt + streamPrompt)
- `src/Providers/Ollama.php` (prompt + streamPrompt)

### Required Features

| Feature                | Current Implementation                          | Importance |
| ---------------------- | ----------------------------------------------- | ---------- |
| **Streaming**          | `CURLOPT_WRITEFUNCTION` callback → `php://temp` | Critical   |
| **Custom Headers**     | Provider API keys, versions                     | Critical   |
| **POST with JSON**     | `CURLOPT_POSTFIELDS` + `json_encode()`          | Critical   |
| **Timeout Control**    | `CURLOPT_TIMEOUT` (0 for streaming, 30s normal) | Critical   |
| **HTTP Status**        | `curl_getinfo($ch, CURLINFO_HTTP_CODE)`         | Critical   |
| **Error Handling**     | Provider-specific JSON error extraction         | Critical   |
| **Rewindable Streams** | `rewind($stream)` for parser access             | Critical   |
| **Memory Buffering**   | `php://temp` streams                            | Important  |
| **Timing Data**        | `curl_getinfo()` for telemetry                  | Important  |

---

## Recommended Architecture

### Design Principles

1. **Minimal Abstraction** - Thin wrapper around curl, not a framework
2. **Telemetry-First** - Built-in span creation and rich timing data
3. **Backward Compatible** - Preserve existing parser interface
4. **Testable** - Clean interface with fake implementation
5. **Zero Dependencies** - Use what we already have

### Class Structure

```
Pagent\Http\
├── HttpClientInterface.php          # Contract
├── CurlTransport.php                # Production implementation (~50 lines)
├── FakeHttpClient.php               # Testing implementation
├── HttpResponse.php                 # DTO for non-streaming responses
├── StreamTransport.php              # DTO for streaming responses
└── Exceptions\
    ├── HttpException.php            # Base
    ├── TimeoutException.php
    └── ConnectionException.php
```

### Interface Design

```php
namespace Pagent\Http;

interface HttpClientInterface
{
    /**
     * Perform a synchronous JSON request.
     */
    public function requestJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): HttpResponse;

    /**
     * Perform a streaming JSON request.
     * Returns a transport with buffered resource handle.
     */
    public function streamJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): StreamTransport;
}
```

### Core Implementation (~50 lines)

```php
namespace Pagent\Http;

final class CurlTransport implements HttpClientInterface
{
    public function requestJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): HttpResponse {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_POSTFIELDS => is_array($json) ? json_encode($json) : $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 30,
        ]);

        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new ConnectionException($error);
        }

        return new HttpResponse(
            status: $info['http_code'],
            headers: $this->parseResponseHeaders($info),
            body: $body,
            info: $info,
        );
    }

    public function streamJson(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $json = null,
        array $options = []
    ): StreamTransport {
        $stream = fopen('php://temp', 'w+b');
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_POSTFIELDS => is_array($json) ? json_encode($json) : $json,
            CURLOPT_TIMEOUT => 0, // Unlimited for streaming
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($stream) {
                fwrite($stream, $data);
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            fclose($stream);
            throw new ConnectionException($error);
        }

        rewind($stream);

        return new StreamTransport(
            resource: $stream,
            status: $info['http_code'],
            headers: $this->parseResponseHeaders($info),
            info: $info,
        );
    }

    private function formatHeaders(array $headers): array
    {
        return array_map(
            fn($key, $value) => "{$key}: {$value}",
            array_keys($headers),
            $headers
        );
    }

    private function parseResponseHeaders(array $info): array
    {
        // Extract headers from curl_getinfo
        return [
            'content-type' => $info['content_type'] ?? '',
        ];
    }
}
```

### DTOs

```php
final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public array $info = [], // curl_getinfo() data for telemetry
    ) {}

    public function json(): array
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * Get timing information from curl_getinfo().
     */
    public function timing(): array
    {
        return [
            'namelookup' => $this->info['namelookup_time'] ?? 0,
            'connect' => $this->info['connect_time'] ?? 0,
            'starttransfer' => $this->info['starttransfer_time'] ?? 0,
            'total' => $this->info['total_time'] ?? 0,
        ];
    }
}
```

```php
final class StreamTransport
{
    public function __construct(
        private $resource,        // php://temp handle
        private int $status,      // HTTP status code
        private array $headers,   // Response headers
        private array $info,      // curl_getinfo() data
    ) {}

    /**
     * Get the buffered resource (rewindable).
     */
    public function resource()
    {
        return $this->resource;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function info(): array
    {
        return $this->info;
    }

    /**
     * Read all content from resource.
     */
    public function getContent(): string
    {
        rewind($this->resource);
        return stream_get_contents($this->resource);
    }
}
```

---

## Telemetry Integration

The CurlTransport will integrate directly with TelemetryManager. Here's the full implementation with telemetry:

```php
final class CurlTransport implements HttpClientInterface
{
    public function requestJson(/* ... */): HttpResponse
    {
        // Start span
        $span = TelemetryManager::instance()->startLLMSpan(
            provider: $this->extractProvider($url),
            model: is_array($json) ? ($json['model'] ?? 'unknown') : 'unknown',
            attributes: [
                'http.method' => $method,
                'http.url' => $this->sanitizeUrl($url),
                'pagent.stream' => false,
            ]
        );

        try {
            // Execute curl request
            $response = $this->executeCurlRequest(/* ... */);

            // Enrich span with timing data
            $span->setAttribute('http.status_code', $response->status);
            $span->setAttribute('http.timing.namelookup', $response->info['namelookup_time']);
            $span->setAttribute('http.timing.connect', $response->info['connect_time']);
            $span->setAttribute('http.timing.starttransfer', $response->info['starttransfer_time']);
            $span->setAttribute('http.timing.total', $response->info['total_time']);

            if (!$response->isSuccessful()) {
                $span->setStatus('error', "HTTP {$response->status}");
            }

            return $response;
        } finally {
            $span->end();
        }
    }
}
```

---

## Migration Strategy

### Phase 1: Foundation (Day 1)

**Tasks:**

- [ ] Create `Pagent\Http` namespace
- [ ] Implement `HttpClientInterface`
- [ ] Implement `HttpResponse` DTO
- [ ] Implement `StreamTransport` DTO
- [ ] Implement `CurlTransport` with telemetry
- [ ] Implement `FakeHttpClient` for testing
- [ ] Write unit tests

**Deliverable:** Working HTTP client with tests

### Phase 2: OpenAI Migration (Day 1.5)

**Tasks:**

- [ ] Add feature flag `PAGENT_HTTP_CLIENT=curl|legacy`
- [ ] Inject `HttpClientInterface` into OpenAI provider
- [ ] Refactor `OpenAI::prompt()` to use `requestJson()`
- [ ] Refactor `OpenAI::streamPrompt()` to use `streamJson()`
- [ ] Verify parsers still work with resource handles
- [ ] Run integration tests

### Phase 3: Anthropic Migration (Day 2)

**Tasks:**

- [ ] Inject HttpClientInterface into Anthropic provider
- [ ] Refactor both methods
- [ ] Test SSE streaming
- [ ] Verify telemetry

### Phase 4: Ollama Migration (Day 2.5)

**Tasks:**

- [ ] Inject HttpClientInterface
- [ ] Test NDJSON streaming
- [ ] Handle local connection errors

### Phase 5: Cleanup (Day 3)

**Tasks:**

- [ ] Remove all curl\_\* imports from providers
- [ ] Remove feature flag
- [ ] Update documentation
- [ ] Run full test suite
- [ ] Benchmark performance

---

## Testing Strategy

### FakeHttpClient for Unit Tests

```php
final class FakeHttpClient implements HttpClientInterface
{
    private array $responses = [];
    private array $requests = [];
    private int $responseIndex = 0;

    public function addResponse(array $response): void
    {
        $this->responses[] = ['type' => 'normal', 'data' => $response];
    }

    public function addStreamResponse(array $response): void
    {
        $this->responses[] = ['type' => 'stream', 'data' => $response];
    }

    public function requestJson(/* ... */): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'json');

        $response = $this->responses[$this->responseIndex++];
        $data = $response['data'];

        return new HttpResponse(
            status: $data['status'] ?? 200,
            headers: $data['headers'] ?? [],
            body: $data['body'] ?? '',
            info: $data['info'] ?? [],
        );
    }

    public function streamJson(/* ... */): StreamTransport
    {
        $this->requests[] = compact('method', 'url', 'headers', 'json');

        $response = $this->responses[$this->responseIndex++];
        $data = $response['data'];

        $stream = fopen('php://temp', 'w+b');
        foreach ($data['chunks'] ?? [] as $chunk) {
            fwrite($stream, $chunk);
        }
        rewind($stream);

        return new StreamTransport(
            resource: $stream,
            status: $data['status'] ?? 200,
            headers: $data['headers'] ?? [],
            info: $data['info'] ?? [],
        );
    }

    public function lastRequest(): array
    {
        return end($this->requests);
    }
}
```

### Unit Test Examples

```php
test('requestJson executes POST with headers', function () {
    $fake = new FakeHttpClient();
    $fake->addResponse([
        'status' => 200,
        'body' => '{"ok":true}',
    ]);

    $response = $fake->requestJson(
        method: 'POST',
        url: 'https://api.test.com/chat',
        headers: ['Authorization' => 'Bearer test'],
        json: ['model' => 'gpt-4']
    );

    expect($response->status)->toBe(200);
    expect($response->json())->toBe(['ok' => true]);

    $req = $fake->lastRequest();
    expect($req['method'])->toBe('POST');
    expect($req['json'])->toBe(['model' => 'gpt-4']);
});

test('streamJson writes chunks to resource', function () {
    $fake = new FakeHttpClient();
    $fake->addStreamResponse([
        'status' => 200,
        'chunks' => ['data: a\n\n', 'data: b\n\n'],
    ]);

    $transport = $fake->streamJson(
        method: 'POST',
        url: 'https://api.test.com/stream'
    );

    $content = $transport->getContent();
    expect($content)->toBe('data: a\n\ndata: b\n\n');

    // Verify rewindable
    rewind($transport->resource());
    expect(stream_get_contents($transport->resource()))->toBe($content);
});

test('includes curl timing in response', function () {
    $fake = new FakeHttpClient();
    $fake->addResponse([
        'status' => 200,
        'body' => '{}',
        'info' => [
            'namelookup_time' => 0.001,
            'connect_time' => 0.010,
            'total_time' => 0.100,
        ],
    ]);

    $response = $fake->requestJson('GET', 'https://api.test.com');

    $timing = $response->timing();
    expect($timing['namelookup'])->toBe(0.001);
    expect($timing['total'])->toBe(0.100);
});
```

---

## Provider Migration Example

### Before (Current Code)

```php
// OpenAI.php
public function prompt(string $message, array $options = []): Response
{
    $ch = curl_init($this->baseUrl.'/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->apiKey}",
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $data = json_decode($response, true);
        throw new RuntimeException("OpenAI API error: {$data['error']['message']}");
    }

    return $this->parseResponse($response);
}
```

### After (With HttpClient)

```php
// OpenAI.php
public function __construct(
    array $config = [],
    private ?HttpClientInterface $httpClient = null,
) {
    $this->httpClient = $httpClient ?? new CurlTransport();
    // ... rest of constructor
}

public function prompt(string $message, array $options = []): Response
{
    $response = $this->httpClient->requestJson(
        method: 'POST',
        url: $this->baseUrl.'/chat/completions',
        headers: [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$this->apiKey}",
        ],
        json: $body,
        options: ['timeout' => 30]
    );

    if (!$response->isSuccessful()) {
        $data = $response->json();
        throw new RuntimeException("OpenAI API error: {$data['error']['message']}");
    }

    return $this->parseResponse($response->body);
}
```

**Benefits:**

- Testable (inject FakeHttpClient)
- Automatic telemetry
- Cleaner error handling
- Rich timing data available

---

## Timeline & Effort

| Phase       | Tasks                              | Days        |
| ----------- | ---------------------------------- | ----------- |
| **Phase 1** | CurlTransport + FakeClient + Tests | 1.0         |
| **Phase 2** | OpenAI Migration                   | 0.5         |
| **Phase 3** | Anthropic Migration                | 0.25        |
| **Phase 4** | Ollama Migration                   | 0.25        |
| **Phase 5** | Cleanup + Docs                     | 0.5         |
| **Testing** | Integration + Benchmarks           | 0.25        |
| **Total**   |                                    | **~3 days** |

---

## Success Criteria

- [ ] All 630+ tests pass
- [ ] Zero regression in functionality
- [ ] Telemetry spans created with rich timing data
- [ ] Performance within 5% of current curl implementation
- [ ] Easy to test with FakeHttpClient
- [ ] Clean provider code (no curl\_\* calls)

---

## Post-Migration Benefits

### Immediate

- ✅ Comprehensive telemetry with curl_getinfo() timing
- ✅ Easy testing with FakeHttpClient
- ✅ Better error handling
- ✅ Type-safe DTOs
- ✅ **Zero new dependencies**
- ✅ **Complete control**

### Future

- ✅ Easy retry logic
- ✅ Circuit breaker
- ✅ Request/response middleware
- ✅ PSR-18 facade
- ✅ Rate limiting
- ✅ Connection pooling
- ✅ HTTP/2 (if curl supports)

---

## Dependencies

**None!** Uses existing `ext-curl`.

---

## References

- [PHP curl_setopt](https://www.php.net/manual/en/function.curl-setopt.php)
- [PHP curl_getinfo](https://www.php.net/manual/en/function.curl-getinfo.php)
- [OpenTelemetry HTTP Conventions](https://opentelemetry.io/docs/specs/semconv/http/)
- [cURL Options](https://curl.se/libcurl/c/curl_easy_setopt.html)

---

**Status:** Ready for implementation  
**Effort:** 3 days  
**Risk:** Low (incremental migration with feature flags)
