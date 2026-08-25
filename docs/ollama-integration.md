# Ollama Integration

Pagent supports local LLM inference through Ollama, enabling privacy-focused, cost-effective AI agents that run entirely on your infrastructure. Perfect for development, testing, and production environments where data sovereignty is critical.

## Table of Contents

- [Quick Start](#quick-start)
- [Installation](#installation)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Streaming](#streaming)
- [Tool Calling](#tool-calling)
- [Multi-Agent Workflows](#multi-agent-workflows)
- [Model Recommendations](#model-recommendations)
- [Performance Tuning](#performance-tuning)
- [Troubleshooting](#troubleshooting)

## Quick Start

```php
use function Pagent\agent;
use function Pagent\ollama;

// Create an agent with Ollama
$agent = agent('assistant')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a helpful AI assistant.');

$response = $agent->prompt('Tell me about PHP frameworks');

echo $response->content;
```

## Installation

### 1. Install Ollama Server

Download and install Ollama from [ollama.com](https://ollama.com):

**macOS:**

```bash
brew install ollama
ollama serve
```

**Linux:**

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama serve
```

**Windows:**
Download the installer from [ollama.com](https://ollama.com)

### 2. Pull Models

```bash
# Recommended for tool calling and general use
ollama pull qwen3:8b

# Alternative: larger model for complex tasks
ollama pull gpt-oss:20b

# Other options
ollama pull llama3.1
ollama pull mistral
```

### 3. Install Pagent

```bash
composer require helgew/pagent
```

## Configuration

### Basic Configuration

The Ollama provider connects to `http://localhost:11434` by default:

```php
use function Pagent\ollama;

$provider = ollama();
```

### Custom Configuration

Configure base URL, timeout, and other options:

```php
$provider = ollama([
    'base_url' => 'http://192.168.1.100:11434',
    'timeout' => 180, // Longer timeout for larger models
]);
```

### Environment Variables

Set the Ollama host via environment:

```bash
export OLLAMA_HOST=http://localhost:11434
```

```php
// Automatically uses OLLAMA_HOST if set
$provider = ollama();
```

### Docker Configuration

If running Ollama in Docker:

```yaml
# docker-compose.yml
services:
  ollama:
    image: ollama/ollama
    ports:
      - "11434:11434"
    volumes:
      - ollama_data:/root/.ollama
```

```php
$provider = ollama([
    'base_url' => 'http://ollama:11434',
]);
```

## Basic Usage

### Simple Prompt

```php
use function Pagent\agent;
use function Pagent\ollama;

$agent = agent('assistant')
    ->provider(ollama())
    ->model('qwen3:8b');

$response = $agent->prompt('What is dependency injection?');

echo $response->content;
echo "Tokens used: " . $response->tokens . "\n";
```

### With System Prompt

```php
$agent = agent('code-reviewer')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are an expert code reviewer. Focus on best practices and potential bugs.');

$response = $agent->prompt('Review this function: ...');
```

### Temperature Control

```php
// More deterministic (temperature = 0)
$agent->temperature(0);

// More creative (temperature = 1)
$agent->temperature(1);
```

### Token Limits

```php
// Limit response length
$agent->maxTokens(500);
```

### Multi-Turn Conversations

```php
$agent = agent('chatbot')
    ->provider(ollama())
    ->model('qwen3:8b');

$response1 = $agent->prompt('My name is Alice.');
echo $response1->content . "\n";

$response2 = $agent->prompt('What is my name?');
echo $response2->content . "\n"; // Should mention "Alice"
```

## Streaming

Ollama supports real-time streaming using NDJSON (newline-delimited JSON), enabling token-by-token response delivery:

### Stream with Callback

```php
$agent = agent('writer')
    ->provider(ollama())
    ->model('qwen3:8b');

$fullContent = $agent->streamTo('Write a short poem about coding', function ($chunk) {
    if ($chunk->isText()) {
        echo $chunk->content;
        flush();
    }

    if ($chunk->isEnd()) {
        $usage = $chunk->getMetadata('usage');
        echo "\n\nTokens: " . $usage['total_tokens'];
    }
});
```

### Manual Stream Control

```php
$streamResponse = $agent->stream('Explain machine learning');

foreach ($streamResponse->getStream() as $chunk) {
    if ($chunk->isStart()) {
        echo "[Started]\n";
    }

    if ($chunk->isText()) {
        echo $chunk->content;
    }

    if ($chunk->isEnd()) {
        echo "\n[Complete]";
    }
}
```

### Server-Sent Events (SSE)

Stream to a web browser using SSE:

```php
// routes/web.php
Route::get('/chat/stream', function () {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    $agent = agent('assistant')
        ->provider(ollama())
        ->model('qwen3:8b');

    $agent->streamTo(request('message'), function ($chunk) {
        if ($chunk->isText()) {
            echo "data: " . json_encode(['content' => $chunk->content]) . "\n\n";
            flush();
        }
    });

    echo "data: [DONE]\n\n";
});
```

## Tool Calling

Ollama supports OpenAI-compatible function calling with compatible models (qwen3, llama3.1, mistral):

### Simple Tool

```php
$agent = agent('calculator')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b);

$response = $agent->prompt('What is 25 + 17?');
echo $response->content; // "42" or "The sum is 42"
```

### Multiple Tools

```php
$agent = agent('math-helper')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->tool('add', 'Add two numbers', fn (int $a, int $b): int => $a + $b)
    ->tool('multiply', 'Multiply two numbers', fn (int $a, int $b): int => $a * $b)
    ->tool('divide', 'Divide two numbers', function (int $a, int $b): float {
        if ($b === 0) {
            throw new \RuntimeException('Division by zero');
        }
        return $a / $b;
    });

$response = $agent->prompt('Calculate (15 + 25) * 2');
```

### Built-in Tools

```php
use Pagent\Tools\FileRead;
use Pagent\Tools\WebFetch;
use Pagent\Tools\Bash;

// Bash is optional: composer require symfony/process

$agent = agent('assistant')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->tool(new FileRead)
    ->tool(new WebFetch)
    ->tool(new Bash);

$response = $agent->prompt('Read the contents of config.php and summarize it');
```

### Class-Based Tools

```php
use Pagent\Tools\Tool;

class WeatherTool extends Tool
{
    public string $name = 'get_weather';
    public string $description = 'Get current weather for a city';

    public function execute(string $city, bool $forecast = false): array
    {
        // Call weather API
        return [
            'city' => $city,
            'temperature' => 72,
            'condition' => 'Sunny',
            'forecast' => $forecast ? ['Tomorrow: 75F', 'Next day: 70F'] : null,
        ];
    }
}

$agent->tool(new WeatherTool);
```

## Multi-Agent Workflows

### Pipeline

Chain multiple Ollama agents together:

```php
use function Pagent\pipeline;

$result = pipeline('content-creation')
    ->step(
        agent('writer')
            ->provider(ollama())
            ->model('qwen3:8b')
            ->system('You are a creative writer.')
    )
    ->step(
        agent('editor')
            ->provider(ollama())
            ->model('gpt-oss:20b')
            ->system('You are an editor. Improve the text for clarity and style.')
    )
    ->run('Write a short paragraph about PHP');

echo $result->finalContent();
```

### Handoff

Transfer conversation between specialized agents:

```php
use Pagent\Orchestration\Handoff;

$handoff = new Handoff;

$initialAgent = agent('greeter')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are a friendly greeter. Determine if user needs technical or sales help.');

$techAgent = agent('tech-support')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are technical support. Help with technical issues.');

$salesAgent = agent('sales')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You are sales support. Help with pricing and features.');

$result = $handoff
    ->start($initialAgent, 'I need help with installation errors')
    ->to($techAgent)
    ->run();
```

### Delegation

Manager agent delegates to specialist agents:

```php
use Pagent\Orchestration\Delegation;

$manager = agent('manager')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You coordinate tasks between specialists.');

$codeAgent = agent('coder')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You write code.');

$testAgent = agent('tester')
    ->provider(ollama())
    ->model('qwen3:8b')
    ->system('You write tests.');

$delegation = new Delegation($manager);
$delegation->delegate($codeAgent, 'Write a User model');
$delegation->delegate($testAgent, 'Write tests for User model');

$result = $delegation->run();
```

## Model Recommendations

### For Tool Calling (Recommended)

**Recommended: qwen3:8b**

- Excellent function calling support
- Fast inference
- Good balance of size/performance
- Recommended for production

```bash
ollama pull qwen3:8b
```

**llama3.1** - Alternative option

- Native tool support
- Widely used
- Good performance

```bash
ollama pull llama3.1
```

### For General Use

**gpt-oss:20b** - Larger, more capable

- Better for complex reasoning
- Slower inference
- Higher memory requirements

```bash
ollama pull gpt-oss:20b
```

**mistral** - Balanced option

- Good performance
- Tool support
- Moderate size

```bash
ollama pull mistral
```

### Model Selection in Code

```php
// Development: fast, lightweight
$agent->model('qwen3:8b');

// Production: more capable
$agent->model('gpt-oss:20b');

// Dynamic selection
$model = app()->environment('production') ? 'gpt-oss:20b' : 'qwen3:8b';
$agent->model($model);
```

## Performance Tuning

### Hardware Requirements

**Minimum (qwen3:8b):**

- 8GB RAM
- 4 CPU cores
- ~5GB disk space

**Recommended (gpt-oss:20b):**

- 24GB RAM
- 8 CPU cores
- ~12GB disk space

### GPU Acceleration

Ollama automatically uses GPU if available:

**Check GPU usage:**

```bash
ollama list
nvidia-smi  # For NVIDIA GPUs
```

**Force CPU mode:**

```bash
CUDA_VISIBLE_DEVICES="" ollama serve
```

### Timeout Configuration

Adjust timeouts for larger models or slower hardware:

```php
$provider = ollama([
    'timeout' => 180, // 3 minutes
]);
```

### Concurrent Requests

Ollama handles multiple requests by loading/unloading models:

```php
// Process requests in parallel
$promises = [];
foreach ($tasks as $task) {
    $promises[] = async(fn() => $agent->prompt($task));
}

$results = await($promises);
```

### Memory Management

**Keep model loaded:**

```bash
ollama run qwen3:8b
# Keep terminal open to keep model in memory
```

**Preload multiple models:**

```bash
ollama run qwen3:8b &
ollama run gpt-oss:20b &
```

## Troubleshooting

### Common Issues

#### Connection Refused

**Problem:** `Ollama API request failed. Is the Ollama server running?`

**Solution:**

```bash
# Start Ollama server
ollama serve

# Or check if already running
ps aux | grep ollama

# Test connection
curl http://localhost:11434/api/version
```

#### Model Not Found

**Problem:** `Ollama API error: model 'qwen3:8b' not found`

**Solution:**

```bash
# List available models
ollama list

# Pull missing model
ollama pull qwen3:8b
```

#### Slow Responses

**Problem:** Responses take too long

**Solutions:**

1. Use smaller model: `qwen3:8b` instead of `gpt-oss:20b`
2. Enable GPU acceleration
3. Increase timeout: `'timeout' => 300`
4. Reduce `max_tokens`: `$agent->maxTokens(500)`
5. Keep model loaded in memory

#### Timeout Errors

**Problem:** `Ollama API request failed` after long wait

**Solutions:**

```php
// Increase timeout
$provider = ollama(['timeout' => 300]);

// Reduce token limit
$agent->maxTokens(200);

// Use smaller model
$agent->model('qwen3:8b');
```

#### Tool Calling Not Working

**Problem:** Agent doesn't use tools

**Solutions:**

1. Use tool-compatible model (`qwen3:8b`, `llama3.1`)
2. Add clear system prompt:
   ```php
   $agent->system('You are a helpful assistant. Use the provided tools to answer questions.');
   ```
3. Test with simple calculator tool first
4. Check Ollama version: `ollama --version` (need v0.1.0+)

### Testing Connection

```php
function testOllamaConnection(string $baseUrl = 'http://localhost:11434'): bool
{
    $ch = curl_init($baseUrl . '/api/version');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $response !== false && $httpCode === 200;
}

if (!testOllamaConnection()) {
    die("Ollama server not available!\n");
}
```

### Debugging

Enable verbose logging:

```php
use Psr\Log\LoggerInterface;

class OllamaLogger {
    public function logRequest(array $body): void
    {
        error_log("Ollama Request: " . json_encode($body));
    }

    public function logResponse(object $response): void
    {
        error_log("Ollama Response: " . json_encode($response));
    }
}
```

## Best Practices

### 1. Model Selection Strategy

```php
// Use fast models for development
if (app()->environment('local')) {
    $provider = ollama(['timeout' => 60]);
    $defaultModel = 'qwen3:8b';
} else {
    $provider = ollama(['timeout' => 180]);
    $defaultModel = 'gpt-oss:20b';
}
```

### 2. Error Handling

```php
try {
    $response = $agent->prompt($question);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'not found')) {
        // Model not available
        Log::error('Ollama model not found', ['model' => $agent->model]);
        // Fallback to different model or return error
    } elseif (str_contains($e->getMessage(), 'not running')) {
        // Server not available
        Log::error('Ollama server not running');
        // Queue for retry or use fallback provider
    } else {
        throw $e;
    }
}
```

### 3. Graceful Fallbacks

```php
function getAgent(): Agent
{
    // Try Ollama first (free, local)
    if (hasOllamaAvailable()) {
        return agent('assistant')
            ->provider(ollama())
            ->model('qwen3:8b');
    }

    // Fallback to cloud provider
    return agent('assistant')
        ->provider(anthropic())
        ->model('claude-sonnet-4-6');
}
```

### 4. Resource Monitoring

```php
function getModelStats(string $model): array
{
    $ch = curl_init('http://localhost:11434/api/show');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['name' => $model]),
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}
```

## Security Considerations

### Network Access

Since Ollama runs locally, be careful about exposing it:

```nginx
# Nginx - restrict access
location /ollama/ {
    allow 127.0.0.1;
    deny all;
    proxy_pass http://localhost:11434/;
}
```

### Tool Permissions

Restrict tool access in production:

```php
// Development
$agent->tool(new Bash);

// Production - restricted tools only
if (!app()->environment('production')) {
    $agent->tool(new Bash);
}
```

### Input Validation

Always validate user input before passing to agents:

```php
$userInput = request('message');

// Validate length
if (strlen($userInput) > 10000) {
    throw new \InvalidArgumentException('Input too long');
}

// Sanitize for XSS if displaying response
$response = $agent->prompt($userInput);
$safeContent = htmlspecialchars($response->content);
```

## Comparison with Cloud Providers

| Feature          | Ollama                | Anthropic | OpenAI    |
| ---------------- | --------------------- | --------- | --------- |
| **Cost**         | Free                  | $$        | $$$       |
| **Privacy**      | 100% local            | Cloud     | Cloud     |
| **Latency**      | Low (local)           | Medium    | Medium    |
| **Models**       | Open source           | Claude    | GPT       |
| **Setup**        | Server required       | API key   | API key   |
| **Scaling**      | Manual                | Automatic | Automatic |
| **Tool Calling** | Yes (qwen3, llama3.1) | Yes       | Yes       |
| **Streaming**    | NDJSON                | SSE       | SSE       |

## Next Steps

- [Multi-Agent Workflows](orchestration-workflows.md)
- [Streaming Guide](streaming.md)
- [Memory & Persistence](memory-persistence.md)
- [Tool Development](../README.md#tools)

## Resources

- [Ollama Official Docs](https://github.com/ollama/ollama/tree/main/docs)
- [Ollama Model Library](https://ollama.com/library)
- [Pagent Examples](../examples/)
