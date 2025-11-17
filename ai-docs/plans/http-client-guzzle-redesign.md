# HTTP Client Architecture - Guzzle Implementation

**Date:** October 30, 2025  
**Status:** Design  
**Approach:** Guzzle + Decorator Pattern + PSR Standards

---

## Design Principles

1. **Use Standards** - PSR-7 (HTTP Messages) + PSR-18 (HTTP Client)
2. **Separation of Concerns** - Telemetry as decorator, not coupled to transport
3. **Real Streaming** - Incremental chunk processing with Guzzle streams
4. **Easy Testing** - Guzzle MockHandler
5. **Simple API** - Convenience methods without lock-in

---

## Architecture

```
┌─────────────────────────────────────┐
│         Provider Layer              │
│  (OpenAI, Anthropic, Ollama)        │
└────────────┬────────────────────────┘
             │ uses
             ▼
┌─────────────────────────────────────┐
│    HttpClient (thin wrapper)        │
│  - requestJson()                    │
│  - streamJson()                     │
│  - Wraps PSR-18 ClientInterface     │
└────────────┬────────────────────────┘
             │ delegates to
             ▼
┌─────────────────────────────────────┐
│   TelemetryDecorator                │
│  - Wraps any PSR-18 client          │
│  - Starts/ends spans                │
│  - Records timing, status, errors   │
└────────────┬────────────────────────┘
             │ delegates to
             ▼
┌─────────────────────────────────────┐
│    Guzzle\Client                    │
│  - PSR-7/PSR-18 implementation      │
│  - Handles HTTP protocol            │
│  - Streaming, redirects, TLS, etc   │
└─────────────────────────────────────┘
```

---

## Class Design

### 1. HttpClient (Convenience Wrapper)

```php
namespace Pagent\Http;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Thin convenience wrapper around PSR-18 client for LLM use cases.
 * Provides JSON and streaming helpers.
 */
final class HttpClient
{
    public function __construct(
        private ClientInterface $client
    ) {}

    /**
     * Make a JSON POST request and return decoded array.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $options Guzzle options
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $json, array $options = []): array
    {
        $response = $this->client->request('POST', $url, array_merge([
            'json' => $json,
        ], $options));

        $this->throwIfError($response);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Make a streaming JSON POST request.
     * Returns a StreamInterface that yields chunks as they arrive.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $options Guzzle options
     * @return StreamInterface Stream that can be read incrementally
     */
    public function postJsonStream(string $url, array $json, array $options = []): StreamInterface
    {
        $response = $this->client->request('POST', $url, array_merge([
            'json' => $json,
            'stream' => true, // Important: enable streaming
        ], $options));

        $this->throwIfError($response);

        return $response->getBody();
    }

    /**
     * Get the underlying Guzzle client for advanced use cases.
     */
    public function client(): ClientInterface
    {
        return $this->client;
    }

    private function throwIfError(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $body = (string) $response->getBody();
            throw new HttpException("HTTP {$status}: {$body}", $status);
        }
    }
}
```

### 2. TelemetryDecorator (Observability)

```php
namespace Pagent\Http;

use GuzzleHttp\ClientInterface;
use Pagent\Observability\TelemetryManager;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Decorates any Guzzle client with telemetry tracking.
 */
final class TelemetryDecorator implements ClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private ?TelemetryManager $telemetry = null,
    ) {
        $this->telemetry = $telemetry ?? TelemetryManager::instance();
    }

    public function request($method, $uri = '', array $options = []): ResponseInterface
    {
        // Extract model from JSON body for span naming
        $model = 'unknown';
        if (isset($options['json']['model'])) {
            $model = $options['json']['model'];
        }

        // Start telemetry span
        $span = $this->telemetry->startLLMSpan(
            provider: $this->extractProvider((string) $uri),
            model: $model,
            attributes: [
                'http.method' => $method,
                'http.url' => $this->sanitizeUrl((string) $uri),
                'pagent.stream' => isset($options['stream']) && $options['stream'],
            ]
        );

        $startTime = microtime(true);

        try {
            // Make the actual request
            $response = $this->client->request($method, $uri, $options);

            $duration = microtime(true) - $startTime;

            // Record success metrics
            $span->setAttribute('http.status_code', $response->getStatusCode());
            $span->setAttribute('http.duration', $duration);

            if ($response->getStatusCode() >= 400) {
                $span->setStatus('error', "HTTP {$response->getStatusCode()}");
            }

            return $response;
        } catch (\Throwable $e) {
            // Record error
            $span->setStatus('error', $e->getMessage());
            $span->setAttribute('error.type', get_class($e));
            throw $e;
        } finally {
            $span->end();
        }
    }

    // Implement other ClientInterface methods by delegating...
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->request($request->getMethod(), (string) $request->getUri(), $options);
    }

    public function requestAsync($method, $uri = '', array $options = []): PromiseInterface
    {
        return $this->client->requestAsync($method, $uri, $options);
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->client->sendAsync($request, $options);
    }

    public function getConfig(?string $option = null)
    {
        return $this->client->getConfig($option);
    }

    private function extractProvider(string $url): string
    {
        if (str_contains($url, 'api.openai.com')) return 'openai';
        if (str_contains($url, 'api.anthropic.com')) return 'anthropic';
        if (str_contains($url, '/api/chat') || str_contains($url, ':11434')) return 'ollama';
        return 'unknown';
    }

    private function sanitizeUrl(string $url): string
    {
        return preg_replace('/([?&])(api_key|key|token)=[^&]+/', '$1$2=***', $url) ?? $url;
    }
}
```

### 3. Factory for Easy Setup

```php
namespace Pagent\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Pagent\Observability\TelemetryManager;

final class HttpClientFactory
{
    public static function create(array $config = []): HttpClient
    {
        $stack = HandlerStack::create();

        // Add retry middleware if configured
        if ($config['retry'] ?? true) {
            $stack->push(RetryMiddleware::create());
        }

        $guzzle = new Client([
            'handler' => $stack,
            'timeout' => $config['timeout'] ?? 30,
            'connect_timeout' => $config['connect_timeout'] ?? 10,
            'http_errors' => false, // Don't throw on 4xx/5xx, let us handle
        ]);

        // Wrap with telemetry if enabled
        if (TelemetryManager::instance()->isEnabled()) {
            $guzzle = new TelemetryDecorator($guzzle);
        }

        return new HttpClient($guzzle);
    }

    public static function createForTesting(): HttpClient
    {
        $guzzle = new Client([
            'handler' => HandlerStack::create(),
            'http_errors' => false,
        ]);

        return new HttpClient($guzzle);
    }
}
```

---

## Provider Integration

### Clean Provider Design

```php
final class OpenAI implements Provider
{
    private HttpClient $http;

    public function __construct(array $config = [], ?HttpClient $http = null)
    {
        $this->apiKey = $config['api_key'] ?? getenv('OPENAI_API_KEY') ?: '';
        $this->http = $http ?? HttpClientFactory::create();
    }

    public function prompt(string $message, array $options = []): object
    {
        // Build request body
        $body = [
            'model' => $options['model'] ?? 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $message]],
        ];

        // Make request (telemetry automatic via decorator)
        $data = $this->http->postJson(
            url: 'https://api.openai.com/v1/chat/completions',
            json: $body,
            options: [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                ],
                'timeout' => 30,
            ]
        );

        // Parse response
        return $this->parseResponse($data);
    }

    public function streamPrompt(string $message, array $options = []): StreamResponse
    {
        $body = [
            'model' => $options['model'] ?? 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $message]],
            'stream' => true,
        ];

        // Get streaming response
        $stream = $this->http->postJsonStream(
            url: 'https://api.openai.com/v1/chat/completions',
            json: $body,
            options: [
                'headers' => ['Authorization' => "Bearer {$this->apiKey}"],
                'timeout' => 0, // No timeout for streaming
            ]
        );

        // Parse SSE stream
        $parser = new OpenAIStreamParser();
        $generator = $parser->parseStream($stream);

        return new StreamResponse($generator, 'openai', $body['model']);
    }
}
```

---

## Real Streaming Implementation

### SSE Stream Parser (Proper)

```php
namespace Pagent\Streaming;

use Generator;
use Psr\Http\Message\StreamInterface;

final class OpenAIStreamParser
{
    /**
     * Parse SSE stream incrementally as chunks arrive.
     *
     * @return Generator<int, StreamChunk>
     */
    public function parseStream(StreamInterface $stream): Generator
    {
        $buffer = '';

        // Read stream incrementally (this is REAL streaming)
        while (!$stream->eof()) {
            $chunk = $stream->read(8192); // Read in chunks
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            // Process complete SSE messages
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $message = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                // Parse SSE message
                if (str_starts_with($message, 'data: ')) {
                    $data = substr($message, 6);

                    if ($data === '[DONE]') {
                        return;
                    }

                    $decoded = json_decode($data, true);
                    if ($decoded && isset($decoded['choices'][0]['delta'])) {
                        $content = $decoded['choices'][0]['delta']['content'] ?? '';
                        if ($content !== '') {
                            yield new StreamChunk($content, 'text');
                        }
                    }
                }
            }
        }

        $stream->close();
    }
}
```

---

## Testing with Guzzle

### Unit Tests with MockHandler

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

test('OpenAI provider makes correct API call', function () {
    // Setup mock
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'choices' => [
                ['message' => ['content' => 'Hello!']],
            ],
            'model' => 'gpt-4o-mini',
            'usage' => ['total_tokens' => 10],
        ])),
    ]);

    $stack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $stack, 'http_errors' => false]);
    $http = new HttpClient($guzzle);

    // Test
    $provider = new OpenAI(['api_key' => 'test-key'], $http);
    $response = $provider->prompt('Hello');

    // Assertions
    expect($response->content)->toBe('Hello!');
    expect($response->model)->toBe('gpt-4o-mini');
});

test('handles API errors gracefully', function () {
    $mock = new MockHandler([
        new Response(401, [], json_encode([
            'error' => ['message' => 'Invalid API key'],
        ])),
    ]);

    $stack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $stack, 'http_errors' => false]);
    $http = new HttpClient($guzzle);

    $provider = new OpenAI(['api_key' => 'bad-key'], $http);

    expect(fn() => $provider->prompt('Hello'))
        ->toThrow(RuntimeException::class, 'Invalid API key');
});
```

### Streaming Tests

```php
test('streams SSE events incrementally', function () {
    $sseData = "data: " . json_encode(['choices' => [['delta' => ['content' => 'Hello']]]]) . "\n\n"
             . "data: " . json_encode(['choices' => [['delta' => ['content' => ' World']]]]) . "\n\n"
             . "data: [DONE]\n\n";

    $mock = new MockHandler([
        new Response(200, [], $sseData),
    ]);

    $stack = HandlerStack::create($mock);
    $guzzle = new Client(['handler' => $stack, 'http_errors' => false]);
    $http = new HttpClient($guzzle);

    $provider = new OpenAI(['api_key' => 'test-key'], $http);
    $streamResponse = $provider->streamPrompt('Test');

    $chunks = [];
    foreach ($streamResponse->stream as $chunk) {
        $chunks[] = $chunk->content;
    }

    expect($chunks)->toBe(['Hello', ' World']);
});
```

---

## Migration Plan

### Step 1: Create Core Classes

```bash
src/Http/
├── HttpClient.php              # Convenience wrapper
├── TelemetryDecorator.php      # Observability decorator
├── HttpClientFactory.php       # Easy instantiation
└── Exceptions/
    └── HttpException.php       # HTTP errors
```

### Step 2: Update Providers

Each provider:

1. Accept `HttpClient` in constructor
2. Replace curl calls with `$this->http->postJson()` or `$this->http->postJsonStream()`
3. Update stream parsers to use `StreamInterface`

### Step 3: Update Stream Parsers

```php
// Before: resource handle
public function parse($resource, string $model): Generator

// After: PSR-7 StreamInterface
public function parseStream(StreamInterface $stream): Generator
```

### Step 4: Update Tests

Replace custom fakes with Guzzle MockHandler throughout.

---

## Complete Implementation Example

### HttpClient.php

```php
<?php

declare(strict_types=1);

namespace Pagent\Http;

use GuzzleHttp\ClientInterface;
use Pagent\Http\Exceptions\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class HttpClient
{
    public function __construct(
        private ClientInterface $client
    ) {}

    /**
     * POST JSON and return decoded response.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function postJson(string $url, array $json, array $options = []): array
    {
        $response = $this->client->request('POST', $url, array_merge([
            'json' => $json,
        ], $options));

        $this->throwIfError($response);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * POST JSON and return streaming response.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $options
     * @return StreamInterface
     * @throws HttpException
     */
    public function postJsonStream(string $url, array $json, array $options = []): StreamInterface
    {
        $response = $this->client->request('POST', $url, array_merge([
            'json' => $json,
            'stream' => true,
        ], $options));

        $this->throwIfError($response);

        return $response->getBody();
    }

    /**
     * Access underlying client for advanced use.
     */
    public function client(): ClientInterface
    {
        return $this->client;
    }

    /**
     * @throws HttpException
     */
    private function throwIfError(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $body = (string) $response->getBody();
            throw new HttpException("HTTP {$status}: {$body}", $status);
        }
    }
}
```

### TelemetryDecorator.php

```php
<?php

declare(strict_types=1);

namespace Pagent\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Pagent\Observability\TelemetryManager;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function microtime;
use function str_contains;

final class TelemetryDecorator implements ClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private ?TelemetryManager $telemetry = null,
    ) {
        $this->telemetry = $telemetry ?? TelemetryManager::instance();
    }

    public function request($method, $uri = '', array $options = []): ResponseInterface
    {
        if (!$this->telemetry->isEnabled()) {
            return $this->client->request($method, $uri, $options);
        }

        $model = 'unknown';
        if (isset($options['json']['model'])) {
            $model = $options['json']['model'];
        }

        $span = $this->telemetry->startLLMSpan(
            provider: $this->extractProvider((string) $uri),
            model: $model,
            attributes: [
                'http.method' => $method,
                'http.url' => $this->sanitizeUrl((string) $uri),
                'pagent.stream' => isset($options['stream']) && $options['stream'],
            ]
        );

        $startTime = microtime(true);

        try {
            $response = $this->client->request($method, $uri, $options);
            $duration = microtime(true) - $startTime;

            $span->setAttribute('http.status_code', $response->getStatusCode());
            $span->setAttribute('http.duration', $duration);

            if ($response->getStatusCode() >= 400) {
                $span->setStatus('error', "HTTP {$response->getStatusCode()}");
            }

            return $response;
        } catch (\Throwable $e) {
            $span->setStatus('error', $e->getMessage());
            $span->setAttribute('error.type', get_class($e));
            throw $e;
        } finally {
            $span->end();
        }
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->request($request->getMethod(), (string) $request->getUri(), $options);
    }

    public function requestAsync($method, $uri = '', array $options = []): PromiseInterface
    {
        return $this->client->requestAsync($method, $uri, $options);
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->client->sendAsync($request, $options);
    }

    public function getConfig(?string $option = null)
    {
        return $this->client->getConfig($option);
    }

    private function extractProvider(string $url): string
    {
        if (str_contains($url, 'api.openai.com')) {
            return 'openai';
        }

        if (str_contains($url, 'api.anthropic.com')) {
            return 'anthropic';
        }

        if (str_contains($url, '/api/chat') || str_contains($url, ':11434')) {
            return 'ollama';
        }

        return 'unknown';
    }

    private function sanitizeUrl(string $url): string
    {
        return preg_replace('/([?&])(api_key|key|token)=[^&]+/', '$1$2=***', $url) ?? $url;
    }
}
```

---

## Benefits of This Design

### vs. Custom Implementation

| Feature        | Custom         | Guzzle + Decorator   |
| -------------- | -------------- | -------------------- |
| Standards      | ❌ Custom      | ✅ PSR-7/PSR-18      |
| Streaming      | ❌ Buffered    | ✅ Real incremental  |
| Testing        | ⚠️ Custom fake | ✅ MockHandler       |
| Error Handling | ⚠️ Basic       | ✅ Rich exceptions   |
| Features       | ❌ JSON only   | ✅ All HTTP features |
| Redirects      | ❌ Manual      | ✅ Automatic         |
| Retries        | ❌ None        | ✅ Built-in          |
| Middleware     | ❌ None        | ✅ HandlerStack      |
| Telemetry      | ❌ Coupled     | ✅ Decorator         |
| Maintenance    | ❌ Our problem | ✅ Community         |

### Specific Improvements

1. **Real Streaming**

   ```php
   // Guzzle StreamInterface reads incrementally
   while (!$stream->eof()) {
       $chunk = $stream->read(8192); // Real-time chunks
       // Process immediately
   }
   ```

2. **Proper Headers**

   ```php
   $response->getHeader('Set-Cookie'); // Multi-value support
   $response->getHeaders(); // All headers normalized
   ```

3. **Rich Error Context**

   ```php
   catch (ConnectException $e) // DNS/connection
   catch (RequestException $e) // HTTP errors with response
   catch (TransferException $e) // Transfer failures
   ```

4. **Easy Retry**
   ```php
   $stack->push(Middleware::retry(
       RetryMiddleware::exponentialDelay(),
       RetryMiddleware::defaultDecider()
   ));
   ```

---

## Migration Effort

### Estimated Timeline

```
Day 1:
- Create HttpClient wrapper (2h)
- Create TelemetryDecorator (2h)
- Create HttpClientFactory (1h)
- Unit tests (3h)

Day 2:
- Migrate OpenAI provider (2h)
- Migrate Anthropic provider (2h)
- Migrate Ollama provider (2h)
- Update stream parsers (2h)

Day 3:
- Fix all tests (4h)
- Integration tests (2h)
- Documentation (2h)

Total: ~3 days
```

---

## Next Steps

1. Create `HttpClient` wrapper
2. Create `TelemetryDecorator`
3. Create `HttpClientFactory`
4. Migrate providers one by one
5. Update stream parsers to use `StreamInterface`
6. Update all tests to use Guzzle `MockHandler`
7. Remove old curl code

---

**Recommendation:** Proceed with Guzzle implementation using decorator pattern.
