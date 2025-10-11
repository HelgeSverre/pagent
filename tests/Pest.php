<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Pagent\Agent;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
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

// Custom expectations for simplified Pagent
expect()->extend('toBeAgent', fn() => $this->toBeInstanceOf(Agent::class));

expect()->extend('toHaveProvider', function () {
    $agent = $this->value;
    if ( ! $agent instanceof Agent) {
        throw new InvalidArgumentException('Expected Agent instance');
    }
    // Since provider is private, we just check if we can call prompt
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
 * Clear agents before each test.
 */
beforeEach(function (): void {
    clearAgents();
});
