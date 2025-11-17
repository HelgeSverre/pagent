# Chapter 2: Working with Providers

**Learning Objectives:**
- Configure Anthropic, OpenAI, and Ollama providers
- Understand provider-specific features and limitations
- Switch between providers dynamically
- Handle provider errors gracefully
- Use mock providers for testing

---

## Understanding Provider Abstraction

At the heart of Pagent's flexibility is the **Provider** interface. Every LLM integration in Pagent—whether Anthropic's Claude, OpenAI's GPT models, or a local Ollama instance—implements a simple contract:

```php
interface Provider {
    public function prompt(string $message, array $options = []): object;
}
```

This minimal interface enables powerful abstractions. You can swap providers without changing your application logic, test with mock providers, or even build custom integrations that work seamlessly with Pagent's ecosystem.

The framework includes four built-in providers:

1. **Anthropic** - Claude models via API
2. **OpenAI** - GPT models via API
3. **Ollama** - Local models via Ollama server
4. **Mock** - Deterministic responses for testing

Each provider returns a consistent response object with these fields:

```php
$response = (object) [
    'content' => string,      // The generated text
    'model' => string,        // Model identifier
    'tokens' => int,          // Total token count
    'provider' => string,     // Provider name
    'usage' => array,         // Detailed usage stats (varies by provider)
];
```

This standardization means response handling code works identically across all providers.

## Configuring the Anthropic Provider

The Anthropic provider connects to Claude models through the Anthropic API. Configuration requires an API key, which can be provided explicitly or through environment variables:

```php
use function Pagent\anthropic;
use function Pagent\agent;

// Option 1: Environment variable (recommended)
// Set ANTHROPIC_API_KEY in your .env file
$provider = anthropic();

// Option 2: Explicit configuration
$provider = anthropic([
    'api_key' => 'sk-ant-...'
]);

// Use with an agent
agent('claude-assistant')
    ->provider($provider)
    ->model('claude-sonnet-4-20250514')
    ->system('You are a helpful assistant.');
```

The Anthropic provider automatically reads from `$_ENV['ANTHROPIC_API_KEY']` or `getenv('ANTHROPIC_API_KEY')` if no explicit key is provided. If neither is available, it throws a `RuntimeException` with a clear error message.

### Anthropic-Specific Features

The Anthropic provider implements several Claude-specific behaviors:

**Default Model**: If you don't specify a model, it defaults to `claude-sonnet-4-20250514`.

**System Messages**: Anthropic handles system prompts as a separate field in the API request, not as a message in the conversation history:

```php
agent('reviewer')
    ->provider(anthropic())
    ->system('You are an expert code reviewer.')  // Sent as 'system' parameter
    ->prompt('Review this function...');
```

**Token Usage**: The response includes detailed token breakdown:

```php
$response = $agent->prompt('Hello');
echo $response->usage['input_tokens'];   // Prompt tokens
echo $response->usage['output_tokens'];  // Completion tokens
echo $response->tokens;                   // Total
```

**Stop Reasons**: Anthropic provides a `stop_reason` field indicating why generation stopped:

```php
$response = $agent->prompt('Tell me a story');
// $response->stop_reason: 'end_turn', 'max_tokens', 'tool_use', etc.
```

### Error Handling

When API calls fail, the provider throws descriptive exceptions:

```php
try {
    $response = $agent->prompt('Hello');
} catch (\RuntimeException $e) {
    // "Anthropic API error: authentication_error Invalid API key"
    // "Anthropic API error: rate_limit_error Rate limit exceeded"
    echo $e->getMessage();
}
```

The provider extracts both the error type and message from Anthropic's error responses for easier debugging.

## Configuring the OpenAI Provider

The OpenAI provider works with GPT models through the OpenAI API. Configuration follows the same pattern as Anthropic:

```php
use function Pagent\openai;

// Environment variable: OPENAI_API_KEY
$provider = openai();

// Explicit configuration
$provider = openai([
    'api_key' => 'sk-...'
]);

agent('gpt-assistant')
    ->provider($provider)
    ->model('gpt-4o')
    ->temperature(0.7);
```

### OpenAI-Specific Behaviors

**Default Model**: Without an explicit model, OpenAI defaults to `gpt-3.5-turbo`.

**System Messages**: Unlike Anthropic, OpenAI handles system prompts as the first message in the conversation:

```php
// This system prompt becomes messages[0] with role: 'system'
agent('helper')
    ->provider(openai())
    ->system('You are a helpful assistant.');
```

**Token Usage**: OpenAI returns aggregated token counts:

```php
$response = $agent->prompt('Explain quantum computing');
echo $response->usage['prompt_tokens'];
echo $response->usage['completion_tokens'];
echo $response->usage['total_tokens'];
echo $response->tokens;  // Same as total_tokens
```

**Finish Reason**: OpenAI provides a `finish_reason` field:

```php
// $response->finish_reason: 'stop', 'length', 'tool_calls', etc.
```

### Provider-Specific Options

The OpenAI provider passes through any unrecognized options to the API, enabling access to OpenAI-specific features:

```php
$response = $agent->prompt('Generate JSON data', [
    'response_format' => ['type' => 'json_object'],
    'seed' => 12345,
    'top_p' => 0.9,
]);
```

These options are merged with the agent's configuration but aren't validated by Pagent—the OpenAI API will reject invalid parameters.

## Configuring the Ollama Provider

Ollama enables running models locally without API calls. This is ideal for development, privacy-sensitive applications, or offline environments:

```php
use function Pagent\ollama;

// Default: http://localhost:11434
$provider = ollama();

// Custom Ollama host
$provider = ollama([
    'base_url' => 'http://192.168.1.100:11434',
    'timeout' => 120,  // seconds
]);

agent('local-assistant')
    ->provider($provider)
    ->model('qwen3:8b');  // Use any locally-pulled model
```

### Ollama-Specific Features

**Default Model**: Ollama defaults to `qwen3:8b` if no model is specified.

**Host Configuration**: The base URL can be set via configuration, `$_ENV['OLLAMA_HOST']`, `getenv('OLLAMA_HOST')`, or defaults to `http://localhost:11434`.

**Token Limit Parameter**: Ollama uses a different parameter name for output length:

```php
agent('local')
    ->provider($provider)
    ->maxTokens(500);  // Pagent converts this to options.num_predict
```

The provider automatically translates `max_tokens` to Ollama's `options.num_predict` parameter.

**Token Counting**: Ollama reports tokens differently:

```php
$response = $agent->prompt('Hello');
echo $response->usage['prompt_tokens'];      // From prompt_eval_count
echo $response->usage['completion_tokens'];  // From eval_count
echo $response->tokens;                       // Sum of both
```

**No API Key**: Unlike cloud providers, Ollama doesn't require authentication (by default). This makes it ideal for local testing.

## Dynamic Provider Switching

Pagent's fluent API makes provider switching trivial. This enables powerful patterns like fallback chains or environment-based configuration:

```php
use function Pagent\agent;
use function Pagent\anthropic;
use function Pagent\openai;
use function Pagent\ollama;

// Development: use local Ollama
if (getenv('APP_ENV') === 'development') {
    $provider = ollama();
    $model = 'qwen3:8b';
}
// Production: use Anthropic
else {
    $provider = anthropic();
    $model = 'claude-sonnet-4-20250514';
}

agent('assistant')
    ->provider($provider)
    ->model($model);
```

You can also switch providers by name using strings:

```php
use function Pagent\agent;

agent('weather-bot')
    ->provider('anthropic')  // String shorthand
    ->model('claude-sonnet-4-20250514');

// Later, switch the same agent to OpenAI
agent('weather-bot')
    ->provider('openai')
    ->model('gpt-4o');
```

The `AgentBuilder` resolves string provider names to instances automatically.

### Provider Fallback Pattern

A common pattern is trying multiple providers for reliability:

```php
function getResponse(string $prompt): object
{
    try {
        // Try primary provider
        return agent('fallback-agent')
            ->provider(anthropic())
            ->prompt($prompt);
    } catch (\RuntimeException $e) {
        // Fall back to OpenAI on error
        return agent('fallback-agent')
            ->provider(openai())
            ->prompt($prompt);
    }
}
```

Since each provider implements the same interface, the fallback code remains clean and testable.

## Testing with the Mock Provider

The Mock provider returns predefined responses without making API calls. This is invaluable for unit testing:

```php
use function Pagent\mock;
use function Pagent\agent;

// Define exact responses for specific prompts
$provider = mock([
    'What is 2+2?' => '4',
    'Translate "hello" to Spanish' => 'hola',
]);

agent('test-agent')
    ->provider($provider);

$response = agent('test-agent')->prompt('What is 2+2?');
echo $response->content;  // "4"

$response = agent('test-agent')->prompt('Unknown prompt');
echo $response->content;  // "Mock response to: Unknown prompt"
```

### Dynamic Mock Responses

You can add responses at runtime using `setResponse()`:

```php
$provider = mock();
$provider->setResponse('ping', 'pong');

agent('test')->provider($provider);
$response = agent('test')->prompt('ping');
echo $response->content;  // "pong"
```

### Mock Response Structure

Mock responses return the same structure as real providers:

```php
$response = (object) [
    'content' => string,          // Your predefined response
    'model' => 'mock',
    'tokens' => int,              // Character count of prompt + response
    'provider' => 'mock',
];
```

The `tokens` field is calculated using `mb_strlen()` for both the prompt and response, giving you approximate token counts for testing.

### Testing Example

Here's a complete unit test using the Mock provider:

```php
use function Pagent\agent;
use function Pagent\mock;
use function Pagent\clearAgents;

function testWeatherAgent(): void
{
    clearAgents();  // Clean registry before test

    $mockProvider = mock([
        'Weather in London?' => 'Sunny, 22°C',
        'Weather in Tokyo?' => 'Rainy, 18°C',
    ]);

    agent('weather')
        ->provider($mockProvider)
        ->system('You provide weather information.');

    $response1 = agent('weather')->prompt('Weather in London?');
    assert($response1->content === 'Sunny, 22°C');

    $response2 = agent('weather')->prompt('Weather in Tokyo?');
    assert($response2->content === 'Rainy, 18°C');

    clearAgents();  // Clean up after test
}
```

The `clearAgents()` function removes all registered agents from the global registry, ensuring test isolation.

## Comparing Provider Capabilities

Different providers have different strengths. Here's a practical comparison:

| Feature | Anthropic | OpenAI | Ollama | Mock |
|---------|-----------|--------|--------|------|
| **API Key Required** | Yes | Yes | No | No |
| **Default Model** | claude-sonnet-4-20250514 | gpt-3.5-turbo | qwen3:8b | mock |
| **System Messages** | Separate field | First message | First message | N/A |
| **Streaming Support** | Yes | Yes | Yes | No |
| **Tool Calling** | Yes | Yes | Yes | No |
| **Token Usage Details** | Detailed | Detailed | Detailed | Simple |
| **Cost** | Paid | Paid | Free (local) | Free |
| **Latency** | Network | Network | Local | Instant |

### When to Use Each Provider

**Use Anthropic** when you need:
- Claude's strong reasoning and tool use capabilities
- Detailed control over system prompts
- High-quality long-form generation

**Use OpenAI** when you need:
- GPT-4's multimodal capabilities
- Structured outputs (JSON mode)
- Broader model selection (o1, o3, etc.)

**Use Ollama** when you need:
- Zero-cost local development
- Privacy (no data leaves your machine)
- Offline operation
- Fast iteration without API limits

**Use Mock** when you need:
- Deterministic testing
- CI/CD without API credentials
- Rapid prototyping without costs

## Provider Configuration Best Practices

### Environment Variables

Always use environment variables for API keys in production:

```bash
# .env
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
OLLAMA_HOST=http://localhost:11434
```

```php
// No hardcoded credentials
$provider = anthropic();  // Reads from environment
```

### Error Handling Strategy

Wrap provider calls in try-catch blocks for production systems:

```php
try {
    $response = agent('assistant')->prompt($userInput);
} catch (\RuntimeException $e) {
    // Log the error
    error_log("LLM Error: " . $e->getMessage());

    // Return user-friendly message
    return "I'm having trouble connecting right now. Please try again.";
}
```

### Configuration Validation

Validate that required providers are configured at application startup:

```php
function validateProviders(): void
{
    try {
        anthropic();  // Will throw if ANTHROPIC_API_KEY is missing
        echo "✓ Anthropic configured\n";
    } catch (\RuntimeException $e) {
        echo "✗ Anthropic not configured: {$e->getMessage()}\n";
    }

    try {
        openai();
        echo "✓ OpenAI configured\n";
    } catch (\RuntimeException $e) {
        echo "✗ OpenAI not configured: {$e->getMessage()}\n";
    }
}
```

### Custom HTTP Clients

All providers accept an optional `HttpClientInterface` implementation for advanced scenarios:

```php
use Pagent\Http\HttpClientInterface;
use Pagent\Providers\Anthropic;

class CustomHttpClient implements HttpClientInterface {
    // Custom implementation with logging, retries, etc.
}

$provider = new Anthropic(
    config: ['api_key' => '...'],
    httpClient: new CustomHttpClient()
);
```

This enables request/response logging, automatic retries, custom timeouts, or proxy configuration without modifying provider code.

## Practical Example: Multi-Provider Weather Bot

Let's build a practical example that demonstrates provider flexibility:

```php
use function Pagent\agent;
use function Pagent\anthropic;
use function Pagent\openai;
use function Pagent\ollama;

function createWeatherBot(string $preferredProvider): void
{
    // Select provider based on preference
    $provider = match($preferredProvider) {
        'anthropic' => anthropic(),
        'openai' => openai(),
        'ollama' => ollama(),
        default => throw new \InvalidArgumentException("Unknown provider: {$preferredProvider}")
    };

    // Configure the agent
    agent('weather-bot')
        ->provider($provider)
        ->system('You are a weather assistant. Provide concise, accurate weather information.')
        ->temperature(0.3);  // Lower temperature for factual responses
}

function getWeather(string $location): string
{
    try {
        $response = agent('weather-bot')->prompt(
            "What's the weather like in {$location}?"
        );

        return $response->content;
    } catch (\RuntimeException $e) {
        return "Weather service unavailable. Please try again later.";
    }
}

// Usage
createWeatherBot(getenv('LLM_PROVIDER') ?? 'ollama');
echo getWeather('San Francisco');
```

This pattern separates configuration from usage, making it easy to switch providers via environment variables or feature flags.

## Summary

Provider abstraction is one of Pagent's core strengths. By implementing a simple interface, all providers—cloud or local, paid or free—work identically in your application code. This design enables:

- **Flexibility**: Switch providers without changing application logic
- **Testability**: Use Mock providers for deterministic tests
- **Resilience**: Implement fallback chains for reliability
- **Cost Control**: Develop locally with Ollama, deploy with cloud providers

In the next chapter, we'll explore how to build multi-turn conversations by managing message history and context windows across all provider types.

---

**Key Takeaways:**
- All providers implement the `Provider` interface with a single `prompt()` method
- Anthropic, OpenAI, and Ollama each have provider-specific defaults and behaviors
- Mock providers enable testing without API calls or costs
- Environment variables are the recommended way to configure API keys
- Provider responses use a consistent object structure across all implementations
- Error handling should be provider-agnostic using try-catch blocks
