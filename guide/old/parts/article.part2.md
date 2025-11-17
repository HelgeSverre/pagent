# Chapter 2: Working with Providers

**Time Estimate:** 30 minutes
**Prerequisites:** Chapter 1 - Getting Started with Pagent
**What You'll Build:** A multi-provider weather bot with fallback strategies

## What You'll Learn

By the end of this chapter, you'll be able to:

- Configure and use Anthropic, OpenAI, and Ollama providers
- Understand each provider's unique features and limitations
- Implement provider fallback patterns for reliability
- Switch providers dynamically based on requirements
- Create mock providers for testing without API calls
- Handle provider-specific errors gracefully

## Overview

Pagent's provider system abstracts the complexity of different LLM APIs while preserving their unique capabilities. Think of providers as specialized drivers—each one knows how to communicate with a specific AI service, but they all present a consistent interface to your application.

```php
// Same interface, different providers
$claude = anthropic()->ask('What is the weather like?');
$gpt = openai()->ask('What is the weather like?');
$local = ollama()->ask('What is the weather like?');
```

## Core Concepts

### The Provider Contract

Every provider in Pagent implements the `Provider` interface, ensuring consistent behavior across different AI services:

```php
interface Provider {
    public function chat(Messages $messages, array $parameters = []): Response;
    public function stream(Messages $messages, array $parameters = []): StreamedResponse;
}
```

This contract guarantees that switching providers requires minimal code changes—often just changing the initialization method.

### Provider Capabilities

Not all providers are created equal. Each has unique strengths and limitations:

| Provider | Streaming | Tool Use | Vision | Context Window | Best For |
|----------|-----------|----------|--------|----------------|----------|
| Anthropic | ✓ | ✓ | ✓ | 200K | Complex reasoning, code generation |
| OpenAI | ✓ | ✓ | ✓ | 128K | General purpose, wide model selection |
| Ollama | ✓ | Limited | Model-dependent | Varies | Local deployment, privacy |
| Mock | ✓ | ✓ | N/A | Unlimited | Testing, development |

### Configuration Hierarchy

Pagent uses a flexible configuration system with clear precedence:

1. **Direct parameters** (highest priority)
2. **Agent-level configuration**
3. **Provider defaults**
4. **Environment variables** (lowest priority)

```php
// Priority demonstration
$agent = anthropic()
    ->model('claude-3-sonnet-20240229')  // Agent-level
    ->ask('Hello', [
        'model' => 'claude-3-opus-20240229'  // Direct parameter (wins)
    ]);
```

## Implementation

### Setting Up Providers

Let's start with a complete multi-provider weather bot that demonstrates real-world usage:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Pagent\Pagent;
use function Pagent\{anthropic, openai, ollama, mock};

// Configuration from environment with fallbacks
$config = [
    'anthropic_api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? null,
    'openai_api_key' => $_ENV['OPENAI_API_KEY'] ?? null,
    'ollama_host' => $_ENV['OLLAMA_HOST'] ?? 'http://localhost:11434',
];

class WeatherBot {
    private array $providers = [];
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
        $this->initializeProviders();
    }

    private function initializeProviders(): void {
        // Anthropic with explicit configuration
        if ($this->config['anthropic_api_key']) {
            $this->providers['anthropic'] = anthropic([
                'api_key' => $this->config['anthropic_api_key'],
                'model' => 'claude-3-haiku-20240307',  // Fast, cost-effective
                'max_tokens' => 500,
                'temperature' => 0.7,
            ]);
        }

        // OpenAI with environment fallback
        if ($this->config['openai_api_key']) {
            $this->providers['openai'] = openai([
                'api_key' => $this->config['openai_api_key'],
                'model' => 'gpt-4o-mini',
                'max_tokens' => 500,
            ]);
        }

        // Ollama for local inference
        try {
            $this->providers['ollama'] = ollama([
                'host' => $this->config['ollama_host'],
                'model' => 'llama3.2',
                'temperature' => 0.7,
            ]);

            // Test connection
            $this->providers['ollama']->ask('test', ['max_tokens' => 1]);
        } catch (\Exception $e) {
            unset($this->providers['ollama']);
            echo "Ollama not available: {$e->getMessage()}\n";
        }

        // Always include mock for testing
        $this->providers['mock'] = mock([
            'response' => 'Mock weather: Sunny, 72°F',
            'delay' => 100,  // Simulate API latency
        ]);
    }

    public function getWeather(string $location, ?string $preferredProvider = null): string {
        $prompt = $this->buildPrompt($location);

        // Try preferred provider first
        if ($preferredProvider && isset($this->providers[$preferredProvider])) {
            try {
                return $this->queryProvider($preferredProvider, $prompt);
            } catch (\Exception $e) {
                echo "Preferred provider {$preferredProvider} failed: {$e->getMessage()}\n";
            }
        }

        // Fallback chain
        $priorityOrder = ['anthropic', 'openai', 'ollama', 'mock'];

        foreach ($priorityOrder as $provider) {
            if (!isset($this->providers[$provider])) {
                continue;
            }

            try {
                return $this->queryProvider($provider, $prompt);
            } catch (\Exception $e) {
                echo "Provider {$provider} failed: {$e->getMessage()}\n";
                continue;
            }
        }

        throw new \RuntimeException('All providers failed');
    }

    private function queryProvider(string $name, string $prompt): string {
        $agent = $this->providers[$name];

        // Provider-specific adjustments
        $params = match($name) {
            'anthropic' => [
                'system' => 'You are a helpful weather assistant. Provide brief, accurate weather information.',
            ],
            'openai' => [
                'response_format' => ['type' => 'text'],
            ],
            'ollama' => [
                'stream' => false,  // Disable streaming for simpler handling
            ],
            default => [],
        };

        $response = $agent->ask($prompt, $params);

        return "[{$name}] {$response}";
    }

    private function buildPrompt(string $location): string {
        return "What's the current weather in {$location}? Include temperature and conditions.";
    }
}

// Usage demonstration
$bot = new WeatherBot($config);

// Use specific provider
echo $bot->getWeather('London', 'anthropic') . "\n";

// Let fallback chain decide
echo $bot->getWeather('Tokyo') . "\n";

// Test with mock when developing
$testBot = new WeatherBot(['ollama_host' => 'invalid']);
echo $testBot->getWeather('Paris') . "\n";  // Will use mock
```

### Provider-Specific Features

Each provider offers unique capabilities. Here's how to leverage them effectively:

```php
// Anthropic: Superior at following complex instructions
$codeReviewer = anthropic()
    ->system('You are a senior PHP developer reviewing code for security issues.')
    ->model('claude-3-opus-20240229')  // Most capable model
    ->temperature(0.2)  // Lower temperature for consistency
    ->ask(<<<PROMPT
    Review this code for security vulnerabilities:

    ```php
    \$username = \$_POST['username'];
    \$query = "SELECT * FROM users WHERE name = '\$username'";
    \$result = mysqli_query(\$conn, \$query);
    ```
    PROMPT);

// OpenAI: Excellent tool use and function calling
$assistant = openai()
    ->model('gpt-4-turbo-preview')
    ->tools([
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Get current weather',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'location' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ])
    ->toolChoice('auto')
    ->ask('What\'s the weather in Berlin?');

// Ollama: Local models for privacy-sensitive data
$documentProcessor = ollama()
    ->model('mixtral:8x7b')  // Powerful local model
    ->system('Extract personal information from documents.')
    ->options([
        'num_predict' => 500,
        'top_k' => 40,
        'top_p' => 0.9,
    ])
    ->ask($sensitiveDocument);
```

### Error Handling Patterns

Robust error handling is crucial when working with external APIs:

```php
class ResilientAgent {
    private array $providers;
    private int $maxRetries = 3;
    private int $retryDelay = 1000; // milliseconds

    public function query(string $prompt): string {
        $lastError = null;

        foreach ($this->providers as $provider) {
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                try {
                    return $this->executeQuery($provider, $prompt);
                } catch (\Pagent\Exceptions\RateLimitException $e) {
                    // Exponential backoff for rate limits
                    $delay = $this->retryDelay * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                    $lastError = $e;
                } catch (\Pagent\Exceptions\ApiException $e) {
                    // API errors might be temporary
                    if ($attempt < $this->maxRetries) {
                        usleep($this->retryDelay * 1000);
                    }
                    $lastError = $e;
                } catch (\Exception $e) {
                    // Unexpected errors, try next provider
                    $lastError = $e;
                    break;
                }
            }
        }

        throw new \RuntimeException(
            'All providers exhausted. Last error: ' . $lastError->getMessage(),
            0,
            $lastError
        );
    }

    private function executeQuery($provider, string $prompt): string {
        // Add timeout protection
        $response = $provider
            ->timeout(30)  // 30 second timeout
            ->ask($prompt);

        // Validate response
        if (empty($response)) {
            throw new \RuntimeException('Empty response received');
        }

        return $response;
    }
}
```

## Advanced Usage

### Dynamic Provider Selection

Choose providers based on task requirements:

```php
class IntelligentRouter {
    private array $taskProfiles = [
        'code_generation' => [
            'provider' => 'anthropic',
            'model' => 'claude-3-opus-20240229',
            'temperature' => 0.3,
        ],
        'creative_writing' => [
            'provider' => 'openai',
            'model' => 'gpt-4-turbo',
            'temperature' => 0.9,
        ],
        'summarization' => [
            'provider' => 'anthropic',
            'model' => 'claude-3-haiku-20240307',
            'temperature' => 0.1,
        ],
        'local_processing' => [
            'provider' => 'ollama',
            'model' => 'llama3.2',
            'temperature' => 0.5,
        ],
    ];

    public function route(string $taskType, string $prompt): string {
        $profile = $this->taskProfiles[$taskType] ?? $this->taskProfiles['summarization'];

        $provider = match($profile['provider']) {
            'anthropic' => anthropic(),
            'openai' => openai(),
            'ollama' => ollama(),
            default => mock(),
        };

        return $provider
            ->model($profile['model'])
            ->temperature($profile['temperature'])
            ->ask($prompt);
    }
}
```

### Testing with Mock Providers

Mock providers enable comprehensive testing without API calls:

```php
use PHPUnit\Framework\TestCase;

class WeatherBotTest extends TestCase {
    public function testWeatherResponse(): void {
        $bot = new WeatherBot([
            'mock_response' => 'London: Cloudy, 15°C',
        ]);

        $response = $bot->getWeather('London', 'mock');

        $this->assertStringContainsString('Cloudy', $response);
        $this->assertStringContainsString('15°C', $response);
    }

    public function testProviderFallback(): void {
        // Create mock that fails first time
        $failingMock = mock()
            ->failTimes(1)
            ->thenRespond('Fallback successful');

        $bot = new WeatherBot([]);
        $bot->addProvider('primary', $failingMock);
        $bot->addProvider('fallback', mock(['response' => 'Backup response']));

        $response = $bot->getWeather('Tokyo');

        $this->assertEquals('Backup response', $response);
    }
}
```

## Common Patterns

### Provider Middleware

Wrap providers with common functionality:

```php
class LoggingProvider {
    private $provider;
    private $logger;

    public function __construct($provider, $logger) {
        $this->provider = $provider;
        $this->logger = $logger;
    }

    public function ask(string $prompt, array $params = []): string {
        $start = microtime(true);

        try {
            $response = $this->provider->ask($prompt, $params);
            $duration = microtime(true) - $start;

            $this->logger->info('Provider request', [
                'provider' => get_class($this->provider),
                'prompt_length' => strlen($prompt),
                'response_length' => strlen($response),
                'duration' => $duration,
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Provider error', [
                'provider' => get_class($this->provider),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### Cost Optimization

Route requests based on cost considerations:

```php
class CostAwareRouter {
    private array $costPerToken = [
        'claude-3-haiku-20240307' => 0.00025,
        'gpt-4o-mini' => 0.00015,
        'gpt-3.5-turbo' => 0.0001,
        'ollama' => 0.0,  // Free local inference
    ];

    public function selectProvider(string $prompt, float $maxCost): string {
        $estimatedTokens = strlen($prompt) / 4;  // Rough estimate

        foreach ($this->costPerToken as $model => $cost) {
            if (($estimatedTokens * $cost) <= $maxCost) {
                return $model;
            }
        }

        return 'ollama';  // Always fall back to free option
    }
}
```

## Next Steps

You've mastered Pagent's provider system! You can now:

- Configure multiple providers with appropriate settings
- Implement robust fallback strategies
- Leverage provider-specific features
- Test effectively with mock providers

In Chapter 3, we'll explore **Advanced Conversations and Context Management**, where you'll learn to:

- Build multi-turn conversations with memory
- Manage context windows efficiently
- Implement conversation branching
- Create stateful agents with personality

Ready to dive deeper? Head to Chapter 3 to unlock the full potential of conversational AI agents.

## Quick Reference

```php
// Provider initialization
$claude = anthropic(['api_key' => $key, 'model' => $model]);
$gpt = openai(['api_key' => $key, 'model' => $model]);
$local = ollama(['host' => $host, 'model' => $model]);
$test = mock(['response' => $response]);

// Common parameters
->model(string $model)
->temperature(float $temp)  // 0.0 - 1.0
->maxTokens(int $tokens)
->timeout(int $seconds)
->system(string $message)

// Provider-specific
->tools(array $tools)  // OpenAI/Anthropic
->options(array $opts)  // Ollama
->failTimes(int $n)     // Mock
```