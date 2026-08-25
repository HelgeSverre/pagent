<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Pagent\Agent;

/*
|--------------------------------------------------------------------------
| Test execution groups
|--------------------------------------------------------------------------
|
| Integration tests are normally local and deterministic. Tests which make
| paid network calls or require a running observability backend are opt-in,
| even when credentials or local services happen to be available.
|
*/

pest()->group('integration')->in('Integration');

pest()->group('live')->in(
    'Integration/ProviderFeaturesTest.php',
    'Integration/RealAPITest.php',
    'Integration/ToolCallingTest.php',
    'Integration/OllamaProviderTest.php',
    'Integration/OllamaToolCallingTest.php',
    'Integration/Streaming',
);

pest()->group('external')->in(
    'Integration/Observability',
    'Integration/Mcp/McpHttpIntegrationTest.php',
    'Unit/Http/CurlTransportTest.php',
    'Unit/Tools/WebFetchTest.php',
);

if (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeAgent', fn () => $this->toBeInstanceOf(Agent::class));

expect()->extend('toHaveProvider', function () {
    $agent = $this->value;
    if (! $agent instanceof Agent) {
        throw new InvalidArgumentException('Expected Agent instance');
    }

    try {
        $agent->prompt('test');

        return $this->toBeTrue(); // Has provider
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'No provider set')) {
            return $this->toBeFalse();
        }

        throw $e;
    }
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a test agent with mock provider.
 */
function testAgent(string $name = 'test-agent'): Agent
{
    $agent = new Agent($name);
    $agent->provider(mock());

    return $agent;
}

/**
 * Check if an environment variable is set.
 */
function hasEnv(string $key): bool
{
    return ! empty($_ENV[$key] ?? getenv($key));
}

/**
 * Skip test if an environment variable is not set.
 */
function skipIfMissingEnv(string $key, ?string $message = null): void
{
    if (! hasEnv($key)) {
        $message ??= "{$key} not set";
        test()->markTestSkipped($message);
    }
}

/**
 * Skip test if an environment variable IS set.
 */
function skipIfHasEnv(string $key, ?string $message = null): void
{
    if (hasEnv($key)) {
        $message ??= "{$key} is set in environment";
        test()->markTestSkipped($message);
    }
}

/**
 * Check if Anthropic API key is available.
 */
function hasAnthropicKey(): bool
{
    return hasEnv('ANTHROPIC_API_KEY');
}

/**
 * Check if OpenAI API key is available.
 */
function hasOpenAiKey(): bool
{
    return hasEnv('OPENAI_API_KEY');
}

/**
 * Skip test if Anthropic API key is not set.
 */
function skipIfMissingAnthropicKey(): void
{
    skipIfMissingEnv('ANTHROPIC_API_KEY');
}

/**
 * Skip test if OpenAI API key is not set.
 */
function skipIfMissingOpenAiKey(): void
{
    skipIfMissingEnv('OPENAI_API_KEY');
}

/**
 * Skip test if Anthropic API key IS set.
 */
function skipIfHasAnthropicKey(): void
{
    skipIfHasEnv('ANTHROPIC_API_KEY');
}

/**
 * Skip test if OpenAI API key IS set.
 */
function skipIfHasOpenAiKey(): void
{
    skipIfHasEnv('OPENAI_API_KEY');
}

/**
 * Check if Ollama server is available.
 */
function hasOllamaAvailable(): bool
{
    $baseUrl = $_ENV['OLLAMA_HOST'] ?? getenv('OLLAMA_HOST') ?: 'http://localhost:11434';
    $baseUrl = rtrim($baseUrl, '/');

    // Try to get server version to check if Ollama is running
    $ch = curl_init($baseUrl.'/api/version');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    ray($response)->green()->label('Ollama /api/version response');

    return $response !== false && $httpCode === 200;
}

/**
 * Check if a specific Ollama model is available.
 */
function hasOllamaModel(string $model): bool
{
    if (! hasOllamaAvailable()) {
        return false;
    }

    $baseUrl = $_ENV['OLLAMA_HOST'] ?? getenv('OLLAMA_HOST') ?: 'http://localhost:11434';
    $baseUrl = rtrim($baseUrl, '/');

    // Use the /api/tags endpoint to list available models
    $ch = curl_init($baseUrl.'/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    ray()->json($response)->green()->label('Ollama /api/tags response');

    if ($response === false || $httpCode !== 200) {
        return false;
    }

    $data = json_decode($response, true);

    // Check if the model exists in the list
    if (! isset($data['models']) || ! is_array($data['models'])) {
        return false;
    }

    foreach ($data['models'] as $modelInfo) {
        if (isset($modelInfo['name']) && $modelInfo['name'] === $model) {
            return true;
        }
    }

    return false;
}

/**
 * Get Ollama test models.
 */
function getOllamaTestModels(): array
{
    return ['qwen3:8b', 'gpt-oss:20b'];
}

/**
 * Skip test if Ollama server is not available.
 */
function skipIfMissingOllama(): void
{
    if (! hasOllamaAvailable()) {
        test()->markTestSkipped('Ollama server not available at '.getOllamaBaseUrl());
    }
}

/**
 * Skip test if a specific Ollama model is not available.
 */
function skipIfMissingOllamaModel(string $model): void
{
    if (! hasOllamaModel($model)) {
        test()->markTestSkipped("Ollama model '{$model}' not available");
    }
}

/**
 * Get Ollama base URL for tests.
 */
function getOllamaBaseUrl(): string
{
    return $_ENV['OLLAMA_HOST'] ?? getenv('OLLAMA_HOST') ?: 'http://localhost:11434';
}

/**
 * Clear agents before each test.
 */
beforeEach(function (): void {
    clearAgents();
});
