<?php

declare(strict_types=1);

namespace Tests\Integration;

use Pagent\Contracts\Provider;

final class MockOpenAIWithTools implements Provider
{
    private int $callCount = 0;

    public function prompt(string $message, array $options = []): object
    {
        $this->callCount++;

        // First call: return tool call
        if ($this->callCount === 1) {
            return (object) [
                'content' => '',
                'model' => 'mock',
                'tokens' => 50,
                'provider' => 'mock',
                'tool_calls' => [
                    [
                        'id' => 'call_123',
                        'name' => 'get_weather',
                        'arguments' => ['city' => 'London'],
                    ],
                ],
            ];
        }

        // Second call: final response after tool execution
        return (object) [
            'content' => 'The weather in London is sunny',
            'model' => 'mock',
            'tokens' => 50,
            'provider' => 'mock',
            'tool_calls' => [],
        ];
    }
}
