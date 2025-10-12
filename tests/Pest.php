<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Pagent\Agent;

if (\file_exists(__DIR__.'/../.env')) {
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

\expect()->extend('toBeAgent', fn () => $this->toBeInstanceOf(Agent::class));

\expect()->extend('toHaveProvider', function () {
    $agent = $this->value;
    if (! $agent instanceof Agent) {
        throw new InvalidArgumentException('Expected Agent instance');
    }

    try {
        $agent->prompt('test');

        return $this->toBeTrue(); // Has provider
    } catch (RuntimeException $e) {
        if (\str_contains($e->getMessage(), 'No provider set')) {
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
    $agent->provider(\mock());

    return $agent;
}

/**
 * Check if an environment variable is set.
 */
function hasEnv(string $key): bool
{
    return ! empty($_ENV[$key] ?? \getenv($key));
}

/**
 * Skip test if an environment variable is not set.
 */
function skipIfMissingEnv(string $key, ?string $message = null): void
{
    if (! \hasEnv($key)) {
        $message ??= "{$key} not set";
        \test()->markTestSkipped($message);
    }
}

/**
 * Skip test if an environment variable IS set.
 */
function skipIfHasEnv(string $key, ?string $message = null): void
{
    if (\hasEnv($key)) {
        $message ??= "{$key} is set in environment";
        \test()->markTestSkipped($message);
    }
}

/**
 * Check if Anthropic API key is available.
 */
function hasAnthropicKey(): bool
{
    return \hasEnv('ANTHROPIC_API_KEY');
}

/**
 * Check if OpenAI API key is available.
 */
function hasOpenAiKey(): bool
{
    return \hasEnv('OPENAI_API_KEY');
}

/**
 * Skip test if Anthropic API key is not set.
 */
function skipIfMissingAnthropicKey(): void
{
    \skipIfMissingEnv('ANTHROPIC_API_KEY');
}

/**
 * Skip test if OpenAI API key is not set.
 */
function skipIfMissingOpenAiKey(): void
{
    \skipIfMissingEnv('OPENAI_API_KEY');
}

/**
 * Skip test if Anthropic API key IS set.
 */
function skipIfHasAnthropicKey(): void
{
    \skipIfHasEnv('ANTHROPIC_API_KEY');
}

/**
 * Skip test if OpenAI API key IS set.
 */
function skipIfHasOpenAiKey(): void
{
    \skipIfHasEnv('OPENAI_API_KEY');
}

/**
 * Clear agents before each test.
 */
\beforeEach(function (): void {
    \clearAgents();
});
