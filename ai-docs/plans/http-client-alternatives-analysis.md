# HTTP Client Alternatives Analysis

**Date:** October 30, 2025  
**Purpose:** Comprehensive evaluation of ALL viable HTTP client options for Pagent  
**Requirements:** Streaming SSE/NDJSON, telemetry, resource handles, sync execution

---

## Requirements Summary

### Must Have
- ✅ **Streaming callbacks** - SSE (Anthropic/OpenAI) and NDJSON (Ollama)
- ✅ **Resource handle output** - For existing stream parsers
- ✅ **Timeout control** - 0 for unlimited streaming, 30s for normal
- ✅ **Synchronous execution** - No event loops in user code
- ✅ **Telemetry hooks** - Easy integration with TelemetryManager
- ✅ **PHP 8.3+ compatible**
- ✅ **Easy testing** - Mock responses, simulate streaming

### Nice to Have
- 🔷 PSR-7/PSR-18 compatibility
- 🔷 HTTP/2 support
- 🔷 Low dependency count
- 🔷 Active maintenance
- 🔷 Rich timing data (DNS, connect, TLS, transfer)

---

## The Contenders

### 1. Custom cURL Wrapper ⭐ RECOMMENDED

**What is it?** Build a minimal HTTP client wrapping `ext-curl` with exactly the features you need.

#### Pros
- ✅ **Perfect streaming** - `CURLOPT_WRITEFUNCTION` for chunk-by-chunk callbacks
- ✅ **Native resource handles** - Write to `php://temp`, expose to parsers
- ✅ **Full timeout control** - `CURLOPT_TIMEOUT=0` for unlimited streaming
- ✅ **Rich telemetry** - `curl_getinfo()` provides DNS, connect, TLS, transfer timing
- ✅ **Zero dependencies** - `ext-curl` is ubiquitous
- ✅ **Synchronous** - No event loops
- ✅ **Easy testing** - Interface + FakeTransport, or local test server
- ✅ **Complete control** - Build exactly what you need
- ✅ **HTTP/2 support** - If curl compiled with it

#### Cons
- ❌ Need to write own wrapper (but small effort)
- ❌ No PSR-18 out of box (but easy to add facade)
- ❌ Direct testing requires interface abstraction

#### Streaming Implementation
```php
class CurlTransport implements HttpClientInterface
{
    public function streamJson(string $method, string $url, array $headers, array $json): StreamTransport
    {
        $stream = fopen('php://temp', 'w+b');
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_POSTFIELDS => json_encode($json),
            CURLOPT_TIMEOUT => 0, // Unlimited
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($stream) {
                fwrite($stream, $data);
                return strlen($data);
            },
        ]);
        
        // Execute and gather telemetry
        curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        rewind($stream);
        return new StreamTransport($stream, $info);
    }
}
```

#### Testing
```php
// Unit tests with interface
$fake = new FakeHttpClient();
$fake->addStreamResponse(['status' => 200, 'chunks' => ['a', 'b', 'c']]);

// Integration tests with local server
$server = new TestStreamServer(); // Tiny SSE/NDJSON server
$server->start();
```

#### Telemetry Integration
```php
// Rich timing data from curl_getinfo()
[
    'namelookup_time' => 0.001234,
    'connect_time' => 0.012345,
    'starttransfer_time' => 0.045678,
    'total_time' => 1.234567,
    'size_download' => 12345,
    'speed_download' => 10000,
    'http_code' => 200,
]
```

**Effort:** Small (0.5-1 day)  
**Maintenance:** Low  
**Overall Score:** 9.5/10

---

### 2. Symfony HttpClient

**What is it?** Modern HTTP client from Symfony ecosystem with native streaming support.

#### Pros
- ✅ **Good streaming** - Native chunk iteration with `$client->stream()`
- ✅ **Clean API** - Fluent, modern interface
- ✅ **Excellent testing** - `MockHttpClient` is superb
- ✅ **Timeout control** - 0.0 for unlimited works well
- ✅ **Small footprint** - ~150KB
- ✅ **Active maintenance** - Symfony core component
- ✅ **PSR-18 adapter** - Available via bridge package
- ✅ **HTTP/2 support** - When curl available

#### Cons
- ❌ Not native resource handles - Need to write chunks to `php://temp`
- ❌ Less rich telemetry than raw curl
- ❌ Another dependency
- ⚠️ Returns `ResponseInterface`, not resource (minor adapter needed)

#### Streaming Implementation
```php
$response = $client->request('POST', $url, [
    'json' => $body,
    'timeout' => 0.0,
]);

$stream = fopen('php://temp', 'w+');
foreach ($client->stream($response, 0.0) as $chunk) {
    if ($chunk->isTimeout()) continue;
    $data = $chunk->getContent(false);
    if ($data !== '') {
        fwrite($stream, $data);
        yield $data; // Can yield for callbacks
    }
}
rewind($stream);
```

#### Testing
```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$client = new MockHttpClient([
    new MockResponse('{"status":"ok"}', ['http_code' => 200]),
]);
```

**Effort:** Small (1-2 days with wrapper)  
**Maintenance:** Low  
**Overall Score:** 9/10

---

### 3. Guzzle

**What is it?** The most popular PHP HTTP client, feature-rich with middleware.

#### Pros
- ✅ **Industry standard** - Widely known
- ✅ **Rich middleware** - HandlerStack for plugins
- ✅ **PSR-7/PSR-18 native**
- ✅ **Active community**
- ✅ **Built-in retry**
- ✅ **Promise-based async**

#### Cons
- ❌ **Streaming is clunky** - Need to manually read PSR-7 StreamInterface
- ❌ **Larger dependencies** - guzzlehttp/psr7, guzzlehttp/promises
- ❌ **Middleware overhead** - Not designed for per-chunk telemetry
- ❌ **Resource handles awkward** - PSR-7 streams don't guarantee detachable resources

#### Streaming Implementation
```php
$response = $client->request('POST', $url, [
    'stream' => true,
    'json' => $body,
]);

$body = $response->getBody(); // PSR-7 StreamInterface
$stream = fopen('php://temp', 'w+');

// Manual reading loop
while (!$body->eof()) {
    $chunk = $body->read(8192);
    fwrite($stream, $chunk);
}
rewind($stream);
```

**Effort:** Medium (2-3 days)  
**Maintenance:** Low  
**Overall Score:** 7/10 for this use case

---

### 4. Native PHP Streams (file_get_contents/fopen)

**What is it?** Use PHP's built-in stream functions with stream contexts.

#### Pros
- ✅ **Zero dependencies** - Pure PHP
- ✅ **Native resource handles** - Direct resource access
- ✅ **Simple** - Minimal abstraction
- ✅ **Easy testing** - stream_wrapper_register for mocks
- ✅ **Good streaming** - fgets/fread for incremental

#### Cons
- ❌ **Manual everything** - Redirects, proxies, HTTP/2, compression
- ❌ **Weak telemetry** - No timing breakdown
- ❌ **Edge cases** - HTTPS, chunked encoding, etc.
- ❌ **Limited protocol support**

#### Implementation
```php
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($body),
        'timeout' => 0, // Unlimited
    ],
]);

$stream = fopen($url, 'r', false, $context);

// Read incrementally
while (!feof($stream)) {
    $line = fgets($stream);
    // Process SSE/NDJSON line
}
```

#### Testing
```php
// Register custom stream wrapper
stream_wrapper_register('mock', MockStreamWrapper::class);
$stream = fopen('mock://test', 'r');
```

**Effort:** Small (1-2 days)  
**Maintenance:** Medium (handle edge cases)  
**Overall Score:** 7.5/10

---

### 5. Amp HTTP Client (Fiber-based async)

**What is it?** Modern async HTTP client using PHP 8.1+ fibers.

#### Pros
- ✅ **Robust streaming** - ByteStream with backpressure
- ✅ **Modern async** - Fibers, not callbacks
- ✅ **Good middleware**
- ✅ **HTTP/2 native**
- ✅ **Active development**

#### Cons
- ❌ **Async runtime** - Adds complexity to sync framework
- ❌ **Not native resources** - ByteStream needs adapter
- ❌ **Multiple dependencies** - amp/byte-stream, amp/socket, revolt
- ❌ **Learning curve**
- ❌ **Overkill** - Unless planning async pivot

#### Implementation
```php
// Requires event loop (even with fibers)
$client = new HttpClientBuilder();
$response = $client->request(new Request($url, 'POST'));

$body = $response->getBody();
while (null !== $chunk = $body->read()) {
    // Process chunk
}
```

**Effort:** Large (3-5 days + learning)  
**Maintenance:** Medium (async ecosystem)  
**Overall Score:** 6/10 for sync framework

---

### 6. ReactPHP HTTP Client

**What is it?** Event-loop based async HTTP client.

#### Pros
- ✅ **Good streaming** - React streams
- ✅ **Mature ecosystem**
- ✅ **Event-driven**

#### Cons
- ❌ **Requires event loop** - Fundamentally async
- ❌ **Complex for sync** - Blocking defeats streaming elegance
- ❌ **Multiple dependencies**
- ❌ **Not aligned** - Framework is synchronous
- ❌ **No native resources**

**Effort:** Large (4-5 days)  
**Maintenance:** High  
**Overall Score:** 4/10 for sync framework

---

### 7. Buzz (Lightweight PSR-18)

**What is it?** Simple, lightweight PSR-7/PSR-18 HTTP client.

#### Pros
- ✅ **Lightweight**
- ✅ **PSR-18 compliant**
- ✅ **Simple API**
- ✅ **Easy testing**

#### Cons
- ❌ **Poor streaming** - PSR-18 often buffers
- ❌ **No callback support** - Must manually read StreamInterface
- ❌ **Resource detach unreliable** - Not guaranteed across implementations
- ❌ **Limited telemetry**

**Effort:** Small (1-2 days)  
**Maintenance:** Low  
**Overall Score:** 5/10 for streaming use case

---

### 8. HTTPlug (Abstraction Layer)

**What is it?** Abstraction over multiple HTTP clients (Guzzle, Symfony, Buzz, etc).

#### Pros
- ✅ **Swap implementations** - Abstraction flexibility
- ✅ **Good plugin system**
- ✅ **PSR-18 aligned**
- ✅ **Easy testing** - php-http/mock-client

#### Cons
- ❌ **Doesn't solve streaming** - Depends on underlying client
- ❌ **Extra layer** - Adds indirection
- ❌ **Still need a real client** - Just wraps something else
- ❌ **Callback streaming** - Not first-class

**Effort:** Medium (2-3 days)  
**Maintenance:** Low/Medium  
**Overall Score:** 6/10

---

### 9. ext-http (pecl_http)

**What is it?** PECL extension providing advanced HTTP client via libcurl.

#### Pros
- ✅ **Very powerful** - Full HTTP/2, streaming callbacks
- ✅ **Resource support** - Can direct to streams
- ✅ **Rich telemetry**
- ✅ **Full timeout control**

#### Cons
- ❌ **PECL dependency** - Requires C extension installation
- ❌ **Operational friction** - Dev, CI, production environments
- ❌ **Less common** - Fewer environments have it
- ❌ **Setup complexity**

#### Installation
```bash
pecl install pecl_http
```

**Effort:** Medium (ops setup)  
**Maintenance:** Medium (extension management)  
**Overall Score:** 7/10 (technical 9/10, ops 5/10)

---

### 10. PSR-18 + Adapter Pattern

**What is it?** Use any PSR-18 client behind your own interface.

#### Pros
- ✅ **Interop** - Standard interface
- ✅ **Swappable** - Easy to change implementations
- ✅ **Testing** - Standard mocks
- ✅ **Framework integration**

#### Cons
- ❌ **No streaming callbacks** - PSR-18 doesn't define them
- ❌ **Buffering risk** - Implementation dependent
- ❌ **Resource handles** - Not guaranteed
- ❌ **Need fallback API** - For streaming use cases

**Effort:** Small (add facade to existing solution)  
**Maintenance:** Low  
**Overall Score:** 7/10 as supplement, 4/10 as primary

---

## Comparison Matrix

| Solution | Streaming | Resources | Telemetry | Testing | Dependencies | Sync | Score |
|----------|-----------|-----------|-----------|---------|--------------|------|-------|
| **Custom cURL** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅ | **9.5** |
| **Symfony** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ✅ | **9.0** |
| **PHP Streams** | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅ | **7.5** |
| **Guzzle** | ⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐ | ✅ | **7.0** |
| **ext-http** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ | ✅ | **7.0** |
| **HTTPlug** | ⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ | ✅ | **6.0** |
| **Amp** | ⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ | ⚠️ | **6.0** |
| **Buzz** | ⭐⭐ | ⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ✅ | **5.0** |
| **ReactPHP** | ⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐ | ⭐⭐ | ⭐⭐ | ❌ | **4.0** |
| **PSR-18 only** | ⭐⭐ | ⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ✅ | **4.0** |

---

## Final Recommendations

### 🥇 First Choice: Custom cURL Wrapper

**Why:**
- Perfect match for all requirements
- Zero dependencies
- Best telemetry
- Complete control
- Minimal code

**Next Steps:**
1. Build `Pagent\Http\HttpClientInterface`
2. Implement `Pagent\Http\CurlTransport`
3. Create `Pagent\Http\FakeTransport` for testing
4. Add telemetry hooks
5. Migrate providers

**Effort:** 0.5-1 day

---

### 🥈 Second Choice: Symfony HttpClient

**Why:**
- Excellent streaming API
- Great testing with MockHttpClient
- Mature, maintained
- Small dependency

**When to choose:**
- You prefer established libraries over custom code
- Want PSR-18 bridge option
- Value community support

**Effort:** 1-2 days

---

### 🥉 Third Choice: PHP Streams + cURL Wrapper Hybrid

**Why:**
- Zero dependencies for simple cases
- Fall back to cURL for advanced features
- Ultimate simplicity

**When to choose:**
- Minimal dependencies paramount
- Simple use cases only
- No complex telemetry needed

**Effort:** 1-2 days

---

## Decision Tree

```
Do you need the absolute best streaming control?
├─ Yes → Custom cURL Wrapper ⭐
└─ No → Continue
    │
    Do you want to avoid writing any HTTP code?
    ├─ Yes → Symfony HttpClient
    └─ No → Continue
        │
        Is zero dependencies critical?
        ├─ Yes → PHP Streams
        └─ No → Symfony HttpClient
```

---

## Hybrid Approach: Best of Both Worlds

**Strategy:** Build your own interface, implement with cURL, add PSR-18 facade

```php
// Your core interface
interface HttpClientInterface {
    public function requestJson(/* ... */): HttpResponse;
    public function streamJson(/* ... */): StreamTransport;
}

// Primary implementation
class CurlTransport implements HttpClientInterface {
    // Streaming, telemetry, resources - everything
}

// Testing implementation
class FakeHttpClient implements HttpClientInterface {
    // In-memory responses
}

// PSR-18 facade (optional, for interop)
class Psr18Adapter implements ClientInterface {
    public function __construct(
        private HttpClientInterface $client
    ) {}
    
    public function sendRequest(RequestInterface $request): ResponseInterface {
        // Adapt to PSR-18
    }
}
```

**Benefits:**
- ✅ Full control over streaming
- ✅ Rich telemetry
- ✅ Easy testing
- ✅ PSR-18 when needed
- ✅ Can swap implementations later

---

## Migration Path

### Option A: Custom cURL First
```
1. Build CurlTransport (0.5 days)
2. Build FakeTransport (0.25 days)
3. Migrate OpenAI (0.5 days)
4. Migrate Anthropic (0.25 days)
5. Migrate Ollama (0.25 days)

Total: ~2 days
```

### Option B: Symfony First
```
1. Add Symfony HttpClient (0.5 days)
2. Build wrapper with telemetry (1 day)
3. Migrate providers (1 day)

Total: ~2.5 days
```

### Option C: Dual Implementation
```
1. Build interface (0.25 days)
2. Build CurlTransport (0.5 days)
3. Build SymfonyTransport (0.5 days)
4. Feature flag switching (0.25 days)
5. Migrate providers (1 day)

Total: ~2.5 days
```

---

## Recommendation Summary

**For Pagent specifically:**

**Go with Custom cURL Wrapper** because:
1. You already use curl effectively
2. Streaming is mission-critical
3. Telemetry depth matters
4. Zero dependency increase
5. Complete control for future features
6. Small effort (~1 day)

**Add PSR-18 facade later** if you need framework interop.

**Alternative:** If you strongly prefer not writing HTTP code, **Symfony HttpClient** is an excellent choice with minimal tradeoffs.

---

## Proof of Concept: Minimal cURL Client

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
            headers: $this->parseHeaders($info),
            body: $body,
            info: $info,
        );
    }
    
    public function streamJson(/* ... */): StreamTransport
    {
        $stream = fopen('php://temp', 'w+b');
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($stream) {
                fwrite($stream, $data);
                return strlen($data);
            },
            CURLOPT_TIMEOUT => 0,
            // ... other options
        ]);
        
        curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        rewind($stream);
        
        return new StreamTransport($stream, $info);
    }
    
    private function formatHeaders(array $headers): array
    {
        return array_map(
            fn($key, $value) => "{$key}: {$value}",
            array_keys($headers),
            $headers
        );
    }
}
```

**This is ~50 lines of code** for everything you need.

---

**Created by:** Oracle Analysis + Comprehensive Research  
**Confidence:** Very High  
**Recommendation:** Custom cURL Wrapper (9.5/10) or Symfony (9/10)
