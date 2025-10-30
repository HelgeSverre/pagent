# HTTP Client Migration Plan

**Created:** October 30, 2025  
**Status:** Planning  
**Effort:** Medium-Large (3-5 days total)  
**Priority:** High (Foundation for telemetry and maintainability)

---

## Executive Summary

**Goal:** Replace raw `curl_*` functions with a maintainable HTTP client library wrapped in a custom adapter, enabling better telemetry integration, testing, and error handling.

**Recommendation:** **Symfony HttpClient** wrapped in custom `Pagent\Http\HttpClient` adapter

**Migration Strategy:** Gradual, provider-by-provider with feature flags

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

### Current Code Pattern (Streaming)

```php
// 1. Create buffer stream
$stream = fopen('php://temp', 'w+b');

// 2. Setup curl with write callback
curl_setopt_array($ch, [
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($stream) {
        fwrite($stream, $data);
        return strlen($data);
    },
    CURLOPT_TIMEOUT => 0, // No timeout
]);

// 3. Execute and handle errors
$execResult = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// 4. Rewind and parse
rewind($stream);
$parser = new OpenAIStreamParser;
$generator = $parser->parse($stream, $model);

// 5. Return StreamResponse
return new StreamResponse($generator, 'openai', $model);
```

---

## Library Comparison: Guzzle vs Symfony HttpClient

### Option 1: Guzzle (Popular, Feature-Rich)

**Pros:**

- ✅ Industry standard, widely known
- ✅ Rich middleware ecosystem (HandlerStack)
- ✅ PSR-7/PSR-18 native support
- ✅ Extensive documentation and community
- ✅ Built-in retry mechanisms
- ✅ Promise-based async support

**Cons:**

- ❌ Streaming requires PSR-7 StreamInterface reading (less ergonomic)
- ❌ Larger dependency footprint (guzzlehttp/psr7, guzzlehttp/promises)
- ❌ Middleware for per-chunk telemetry is awkward
- ❌ Timeout semantics for long streams require careful configuration
- ❌ Mock testing requires more setup

**Streaming Implementation Complexity:**

```php
// Guzzle streaming (more manual)
$response = $client->request('POST', $url, [
    'stream' => true,
    'json' => $body,
]);

$body = $response->getBody(); // PSR-7 StreamInterface
$stream = fopen('php://temp', 'w+');

while (!$body->eof()) {
    $chunk = $body->read(8192);
    fwrite($stream, $chunk);
}
```

**Score:** 7/10 for this use case

---

### Option 2: Symfony HttpClient (Recommended)

**Pros:**

- ✅ **First-class streaming** with native chunk iteration
- ✅ **Lightweight** - minimal dependencies
- ✅ **Excellent timeout control** (0.0 for unlimited streaming)
- ✅ **MockHttpClient** makes testing trivial
- ✅ **Clean error handling** - doesn't throw on 4xx/5xx
- ✅ **Direct chunk access** via `$client->stream()`
- ✅ **Fast and efficient** - used in Symfony framework
- ✅ PSR-18 adapter available if needed later

**Cons:**

- ❌ Less familiar to some developers
- ❌ Fewer built-in middleware patterns (but we're building our own anyway)
- ❌ Requires PSR-18 bridge for strict PSR compliance

**Streaming Implementation Complexity:**

```php
// Symfony streaming (ergonomic)
$response = $client->request('POST', $url, [
    'json' => $body,
    'timeout' => 0.0, // Unlimited
]);

$stream = fopen('php://temp', 'w+');
foreach ($client->stream($response, 0.0) as $chunk) {
    if ($chunk->isTimeout()) continue;
    $data = $chunk->getContent(false); // Don't throw on errors
    if ($data !== '') {
        fwrite($stream, $data);
        yield $data; // Easy per-chunk processing
    }
}
```

**Score:** 9/10 for this use case

---

## Recommended Architecture

### Design Principles

1. **Abstraction** - Hide concrete client behind interface
2. **Telemetry-First** - Built-in span creation and event emission
3. **Backward Compatible** - Preserve existing parser interface
4. **Testable** - Easy mocking and assertion
5. **Extensible** - Support future providers/features

### Class Structure

```
Pagent\Http\
├── HttpClientInterface.php          # Contract
├── SymfonyHttpClient.php            # Symfony implementation
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
     *
     * @return HttpResponse
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
     *
     * @return StreamTransport
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

### DTOs

```php
final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
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
}
```

```php
final class StreamTransport
{
    public function __construct(
        private $resource,           // php://temp handle
        private Generator $chunks,    // Yields string chunks
        private ResponseInterface $response, // Underlying Symfony response
    ) {}

    /**
     * Get the buffered resource (rewindable).
     */
    public function resource()
    {
        return $this->resource;
    }

    /**
     * Iterate over chunks as they arrive.
     */
    public function chunks(): Generator
    {
        return $this->chunks;
    }

    /**
     * Get final response (blocks until complete).
     */
    public function awaitFinal(): HttpResponse
    {
        // Consume remaining chunks
        iterator_to_array($this->chunks);

        return new HttpResponse(
            status: $this->response->getStatusCode(),
            headers: $this->response->getHeaders(),
            body: $this->response->getContent(false), // Don't throw
        );
    }

    /**
     * Get initial headers (available immediately).
     */
    public function getInitialHeaders(): array
    {
        return $this->response->getHeaders(false);
    }
}
```

---

## Telemetry Integration

### Span Lifecycle

**Non-Streaming Request:**

```php
public function requestJson(/* ... */): HttpResponse
{
    // 1. Start span
    $span = TelemetryManager::instance()->startLLMSpan(
        provider: $this->extractProvider($url),
        model: $json['model'] ?? 'unknown',
        attributes: [
            'http.method' => $method,
            'http.url' => $this->sanitizeUrl($url),
            'http.request.body.size' => strlen(json_encode($json)),
            'pagent.stream' => false,
        ]
    );

    try {
        // 2. Make request
        $response = $this->client->request($method, $url, [
            'headers' => $headers,
            'json' => $json,
            'timeout' => $options['timeout'] ?? 30.0,
        ]);

        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        // 3. Set span attributes
        $span->setAttribute('http.status_code', $status);
        $span->setAttribute('http.response.body.size', strlen($body));

        // 4. Handle errors
        if ($status >= 400) {
            $errorData = json_decode($body, true);
            $span->setStatus('error', "HTTP {$status}");
            $span->setAttribute('gen_ai.error.type', $errorData['error']['type'] ?? 'unknown');
            $span->setAttribute('gen_ai.error.message', $errorData['error']['message'] ?? '');
        }

        return new HttpResponse($status, $response->getHeaders(), $body);
    } finally {
        // 5. Always end span
        $span->end();
    }
}
```

**Streaming Request:**

```php
public function streamJson(/* ... */): StreamTransport
{
    $span = TelemetryManager::instance()->startLLMSpan(
        provider: $this->extractProvider($url),
        model: $json['model'] ?? 'unknown',
        attributes: [
            'http.method' => $method,
            'http.url' => $this->sanitizeUrl($url),
            'pagent.stream' => true,
            'http.timeout' => 0.0,
        ]
    );

    $response = $this->client->request($method, $url, [
        'headers' => $headers,
        'json' => $json,
        'timeout' => 0.0, // Unlimited for streaming
    ]);

    $stream = fopen('php://temp', 'w+b');
    $chunkCount = 0;
    $totalBytes = 0;

    // Generator that emits chunks and tracks telemetry
    $generator = (function() use ($response, $stream, $span, &$chunkCount, &$totalBytes) {
        try {
            foreach ($this->client->stream($response, 0.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }

                $data = $chunk->getContent(false);
                if ($data !== '') {
                    fwrite($stream, $data);
                    $chunkCount++;
                    $totalBytes += strlen($data);

                    // Emit chunk event every 10 chunks to reduce overhead
                    if ($chunkCount % 10 === 0) {
                        $span->addEvent('llm.stream.chunk', [
                            'chunks' => $chunkCount,
                            'bytes' => $totalBytes,
                        ]);
                    }

                    yield $data;
                }

                if ($chunk->isLast()) {
                    break;
                }
            }

            // Final attributes
            $span->setAttribute('http.status_code', $response->getStatusCode());
            $span->setAttribute('llm.stream.total_chunks', $chunkCount);
            $span->setAttribute('llm.stream.total_bytes', $totalBytes);
        } finally {
            $span->end();
        }
    })();

    return new StreamTransport($stream, $generator, $response);
}
```

### Telemetry Events

| Event                   | Attributes                                   | When             |
| ----------------------- | -------------------------------------------- | ---------------- |
| `http.request.start`    | `method`, `url`, `body_size`                 | Before request   |
| `http.response.headers` | `status_code`, `content_type`                | Headers received |
| `llm.stream.chunk`      | `chunks`, `bytes`                            | Every 10th chunk |
| `llm.stream.complete`   | `total_chunks`, `total_bytes`, `duration`    | Stream ends      |
| `http.error`            | `status_code`, `error_type`, `error_message` | Error response   |

---

## Migration Strategy

### Phase 1: Foundation (Day 1-2)

**Tasks:**

- [ ] Add Symfony HttpClient dependency
  ```bash
  composer require symfony/http-client
  ```
- [ ] Create `Pagent\Http` namespace structure
- [ ] Implement `HttpClientInterface`
- [ ] Implement `HttpResponse` DTO
- [ ] Implement `StreamTransport` DTO
- [ ] Implement `SymfonyHttpClient` with telemetry
- [ ] Write unit tests with `MockHttpClient`

**Deliverable:** Working HTTP client library with full test coverage

---

### Phase 2: OpenAI Migration (Day 2-3)

**Why OpenAI First?**

- Most commonly used provider
- Good baseline for other providers
- Excellent test coverage

**Tasks:**

- [ ] Add feature flag: `PAGENT_HTTP_CLIENT=symfony|curl`
- [ ] Refactor `OpenAI::prompt()` to use `HttpClient::requestJson()`
- [ ] Refactor `OpenAI::streamPrompt()` to use `HttpClient::streamJson()`
- [ ] Preserve existing `OpenAIStreamParser` interface
- [ ] Update integration tests
- [ ] Verify telemetry spans are created
- [ ] Performance comparison (curl vs Symfony)

**Migration Pattern:**

```php
// Before (curl)
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

// After (HttpClient)
$response = $this->httpClient->requestJson(
    method: 'POST',
    url: $this->baseUrl.'/chat/completions',
    headers: [
        'Content-Type' => 'application/json',
        'Authorization' => "Bearer {$this->apiKey}",
    ],
    json: $body,
    options: ['timeout' => 30.0]
);

if (!$response->isSuccessful()) {
    $data = $response->json();
    $error = $data['error']['message'] ?? 'Unknown error';
    throw new RuntimeException("OpenAI API error: {$error}");
}

return new Response(/* ... parse $response->body ... */);
```

**Streaming Migration:**

```php
// Before (curl with callback)
$stream = fopen('php://temp', 'w+b');
curl_setopt_array($ch, [
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($stream) {
        fwrite($stream, $data);
        return strlen($data);
    },
    CURLOPT_TIMEOUT => 0,
]);
curl_exec($ch);
curl_close($ch);
rewind($stream);

$parser = new OpenAIStreamParser;
$generator = $parser->parse($stream, $model);

// After (HttpClient)
$transport = $this->httpClient->streamJson(
    method: 'POST',
    url: $this->baseUrl.'/chat/completions',
    headers: [
        'Content-Type' => 'application/json',
        'Authorization' => "Bearer {$this->apiKey}",
    ],
    json: $body,
    options: ['timeout' => 0.0]
);

$parser = new OpenAIStreamParser;
$generator = $parser->parse($transport->resource(), $model);

return new StreamResponse(
    stream: $generator,
    provider: 'openai',
    model: $model,
);
```

---

### Phase 3: Anthropic Migration (Day 3)

**Tasks:**

- [ ] Apply same pattern as OpenAI
- [ ] Update Anthropic-specific headers (`x-api-key`, `anthropic-version`)
- [ ] Test SSE streaming with `AnthropicStreamParser`
- [ ] Verify telemetry attributes

**Key Differences:**

```php
headers: [
    'Content-Type' => 'application/json',
    'x-api-key' => $this->apiKey,
    'anthropic-version' => '2023-06-01',
]
```

---

### Phase 4: Ollama Migration (Day 4)

**Tasks:**

- [ ] Migrate Ollama provider
- [ ] Test NDJSON streaming with `OllamaStreamParser`
- [ ] Test local connection errors
- [ ] Update error messages

**Ollama Notes:**

- No authentication headers
- Local endpoint (better error messages)
- NDJSON format instead of SSE

---

### Phase 5: Cleanup & Documentation (Day 5)

**Tasks:**

- [ ] Remove all `curl_*` imports
- [ ] Remove feature flag (make Symfony default)
- [ ] Update documentation
  - [ ] README.md
  - [ ] docs/streaming.md
  - [ ] CONTRIBUTING.md (testing with MockHttpClient)
- [ ] Update examples if needed
- [ ] Performance benchmarks
- [ ] Security audit (URL sanitization, header injection)

---

## Testing Strategy

### Unit Tests (No Network)

**Test File:** `tests/Unit/Http/SymfonyHttpClientTest.php`

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

test('requestJson makes POST with correct headers', function () {
    $mockClient = new MockHttpClient([
        new MockResponse('{"status":"ok"}', [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]),
    ]);

    $client = new SymfonyHttpClient($mockClient);

    $response = $client->requestJson(
        method: 'POST',
        url: 'https://api.example.com/test',
        headers: ['Authorization' => 'Bearer test-key'],
        json: ['foo' => 'bar']
    );

    expect($response->status)->toBe(200);
    expect($response->json())->toBe(['status' => 'ok']);
});

test('streamJson yields chunks incrementally', function () {
    $chunks = ["data: chunk1\n\n", "data: chunk2\n\n", "data: chunk3\n\n"];

    $mockClient = new MockHttpClient([
        new MockResponse(
            body: function () use ($chunks) {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }
            },
            info: ['http_code' => 200]
        ),
    ]);

    $client = new SymfonyHttpClient($mockClient);

    $transport = $client->streamJson(
        method: 'POST',
        url: 'https://api.example.com/stream',
        json: ['stream' => true]
    );

    $received = [];
    foreach ($transport->chunks() as $chunk) {
        $received[] = $chunk;
    }

    expect($received)->toBe($chunks);

    // Resource should be rewindable
    rewind($transport->resource());
    $content = stream_get_contents($transport->resource());
    expect($content)->toBe(implode('', $chunks));
});

test('handles HTTP errors without throwing', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(
            body: '{"error":{"message":"Invalid API key"}}',
            info: ['http_code' => 401]
        ),
    ]);

    $client = new SymfonyHttpClient($mockClient);

    $response = $client->requestJson('POST', 'https://api.example.com/test');

    expect($response->status)->toBe(401);
    expect($response->isClientError())->toBeTrue();
    expect($response->json()['error']['message'])->toBe('Invalid API key');
});
```

### Integration Tests

**Test File:** `tests/Integration/Http/HttpClientIntegrationTest.php`

```php
test('real OpenAI request works with new client')
    ->skipIfMissingOpenAI()
    ->group('api')
    ->run(function () {
        $client = new SymfonyHttpClient(HttpClient::create());

        $response = $client->requestJson(
            method: 'POST',
            url: 'https://api.openai.com/v1/chat/completions',
            headers: [
                'Authorization' => 'Bearer '.getenv('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ],
            json: [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => 'Say "test successful"']
                ],
                'max_tokens' => 10,
            ]
        );

        expect($response->isSuccessful())->toBeTrue();
        expect($response->json()['choices'][0]['message']['content'])
            ->toContain('test');
    });
```

### Telemetry Tests

**Test File:** `tests/Unit/Http/TelemetryIntegrationTest.php`

```php
use Pagent\Observability\Exporters\InMemoryExporter;

test('HTTP client creates spans with correct attributes', function () {
    $exporter = new InMemoryExporter();
    TelemetryManager::instance()
        ->initialize(['enabled' => true])
        ->setExporter($exporter);

    $mockClient = new MockHttpClient([
        new MockResponse('{"status":"ok"}', ['http_code' => 200]),
    ]);

    $client = new SymfonyHttpClient($mockClient);

    $client->requestJson(
        method: 'POST',
        url: 'https://api.openai.com/v1/chat/completions',
        json: ['model' => 'gpt-4o']
    );

    $spans = $exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $span = $spans[0];
    expect($span->getName())->toBe('llm.request');
    expect($span->getAttributes()['gen_ai.system'])->toBe('openai');
    expect($span->getAttributes()['gen_ai.request.model'])->toBe('gpt-4o');
    expect($span->getAttributes()['http.status_code'])->toBe(200);
    expect($span->getAttributes()['http.method'])->toBe('POST');
});

test('streaming creates chunk events', function () {
    $exporter = new InMemoryExporter();
    TelemetryManager::instance()
        ->initialize(['enabled' => true])
        ->setExporter($exporter);

    $mockClient = new MockHttpClient([
        new MockResponse(
            body: function () {
                yield "chunk1";
                yield "chunk2";
                yield "chunk3";
            },
            info: ['http_code' => 200]
        ),
    ]);

    $client = new SymfonyHttpClient($mockClient);

    $transport = $client->streamJson('POST', 'https://api.test.com/stream');

    // Consume stream
    iterator_to_array($transport->chunks());

    $spans = $exporter->getSpans();
    $events = $spans[0]->getEvents();

    expect($events)->toContain(
        fn($e) => $e->getName() === 'llm.stream.chunk'
    );
});
```

---

## Performance Considerations

### Benchmarks to Run

```php
// tests/Benchmark/HttpClientBenchmark.php
test('compare curl vs symfony performance', function () {
    $iterations = 100;

    // Benchmark curl
    $curlStart = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        // ... curl request
    }
    $curlTime = microtime(true) - $curlStart;

    // Benchmark Symfony
    $symfonyStart = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        // ... symfony request
    }
    $symfonyTime = microtime(true) - $symfonyStart;

    echo "curl: {$curlTime}s\n";
    echo "Symfony: {$symfonyTime}s\n";
    echo "Difference: ".($symfonyTime - $curlTime)."s\n";

    // Allow up to 10% overhead
    expect($symfonyTime)->toBeLessThan($curlTime * 1.1);
});
```

### Memory Profile

```php
test('memory usage remains constant during streaming', function () {
    $baseline = memory_get_usage();

    $transport = $client->streamJson(/* large stream */);

    $maxMemory = $baseline;
    foreach ($transport->chunks() as $chunk) {
        $current = memory_get_usage();
        $maxMemory = max($maxMemory, $current);
    }

    // Should not grow beyond 10MB for any stream
    expect($maxMemory - $baseline)->toBeLessThan(10 * 1024 * 1024);
});
```

---

## Security Considerations

### URL Sanitization for Telemetry

```php
private function sanitizeUrl(string $url): string
{
    // Remove API keys from query strings
    return preg_replace('/([?&])(api_key|key|token)=[^&]+/', '$1$2=***', $url);
}
```

### Header Redaction

```php
private function sanitizeHeaders(array $headers): array
{
    $sensitive = ['Authorization', 'x-api-key', 'Cookie'];

    foreach ($sensitive as $key) {
        if (isset($headers[$key])) {
            $headers[$key] = '***';
        }
    }

    return $headers;
}
```

### SSRF Protection

```php
private function validateUrl(string $url): void
{
    $parsed = parse_url($url);

    if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) {
        throw new InvalidArgumentException('Only HTTP(S) URLs allowed');
    }

    // Prevent requests to private IPs (if needed)
    if (isset($parsed['host'])) {
        $ip = gethostbyname($parsed['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            // Optional: block private ranges
        }
    }
}
```

---

## Rollback Plan

### Feature Flag Implementation

```php
// config/http.php
return [
    'driver' => env('PAGENT_HTTP_CLIENT', 'symfony'), // or 'curl'
];

// Provider constructor
public function __construct(array $config = [])
{
    $driver = $config['http_driver'] ?? env('PAGENT_HTTP_CLIENT', 'symfony');

    $this->httpClient = match($driver) {
        'symfony' => new SymfonyHttpClient(),
        'curl' => new CurlHttpClient(), // Backward compat wrapper
        default => throw new InvalidArgumentException("Unknown HTTP driver: {$driver}"),
    };
}
```

### Monitoring

- [ ] Track error rates per provider after migration
- [ ] Monitor response times (should be ±5% of curl)
- [ ] Watch for timeout issues in streaming
- [ ] Check telemetry span volume

---

## Success Criteria

### Must Have

- [ ] All 630+ tests pass
- [ ] Zero regression in functionality
- [ ] Telemetry spans created for all requests
- [ ] Streaming performance within 10% of curl
- [ ] Memory usage stable during long streams

### Nice to Have

- [ ] Performance improvement over curl
- [ ] Better error messages
- [ ] Easier testing with MockHttpClient
- [ ] PSR-18 compliance

---

## Documentation Updates

### Files to Update

1. **README.md**
   - Update architecture section
   - Add HTTP client info

2. **docs/streaming.md**
   - Document new streaming implementation
   - Show MockHttpClient testing examples

3. **CONTRIBUTING.md**
   - Testing with MockHttpClient
   - Running benchmarks

4. **New:** `docs/http-client.md`
   - Architecture overview
   - Custom implementation guide
   - Telemetry integration
   - Testing patterns

---

## Timeline & Effort Estimate

| Phase       | Tasks                    | Effort | Days       |
| ----------- | ------------------------ | ------ | ---------- |
| **Phase 1** | Foundation + Tests       | Medium | 1.5        |
| **Phase 2** | OpenAI Migration         | Medium | 1.0        |
| **Phase 3** | Anthropic Migration      | Small  | 0.5        |
| **Phase 4** | Ollama Migration         | Small  | 0.5        |
| **Phase 5** | Cleanup + Docs           | Medium | 1.0        |
| **Testing** | Integration + Benchmarks | Medium | 0.5        |
| **Total**   |                          |        | **5 days** |

---

## Dependencies to Add

```bash
composer require symfony/http-client
```

**Size:** ~150KB (minimal)

**Version:** ^7.3 (matches existing Symfony deps)

---

## Post-Migration Benefits

### Immediate

- ✅ Comprehensive telemetry for all HTTP calls
- ✅ Easier testing with MockHttpClient
- ✅ Better error handling and messages
- ✅ Type-safe request/response DTOs

### Future

- ✅ Easy to add retry logic
- ✅ Circuit breaker support
- ✅ Request/response middleware
- ✅ HTTP/2 and HTTP/3 support
- ✅ PSR-18 compatibility when needed
- ✅ Centralized rate limiting
- ✅ Request caching layer

---

## References

- [Symfony HttpClient Docs](https://symfony.com/doc/current/http_client.html)
- [Symfony HttpClient Streaming](https://symfony.com/doc/current/http_client.html#streaming-responses)
- [OpenTelemetry Semantic Conventions](https://opentelemetry.io/docs/specs/semconv/http/)
- [PSR-18: HTTP Client](https://www.php-fig.org/psr/psr-18/)

---

## Next Steps

1. **Review this plan** with team/stakeholders
2. **Create composer.json PR** adding Symfony HttpClient
3. **Start Phase 1** - Build foundation
4. **Incremental PRs** - One provider per PR
5. **Monitor** - Track metrics after each migration

---

**Prepared by:** AI Analysis + Oracle Review  
**Status:** Ready for Implementation  
**Risk Level:** Medium (mitigated by gradual rollout)
