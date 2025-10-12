<?php

declare(strict_types=1);

use Pagent\Providers\Mock;

\it('returns predefined responses', function (): void {
    $mock = new Mock([
        'responses' => [
            'hello' => 'Hi there!',
            'goodbye' => 'See you later!',
        ],
    ]);

    $response1 = $mock->prompt('hello');
    \expect($response1->content)->toBe('Hi there!');

    $response2 = $mock->prompt('goodbye');
    \expect($response2->content)->toBe('See you later!');
});

\it('returns default response for unknown prompts', function (): void {
    $mock = new Mock();

    $response = $mock->prompt('unknown prompt');
    \expect($response->content)->toBe('Mock response to: unknown prompt');
});

\it('calculates token count', function (): void {
    $mock = new Mock();

    $response = $mock->prompt('test');
    \expect($response->tokens)->toBe(\mb_strlen('test') + \mb_strlen('Mock response to: test'));
});

\it('includes provider metadata', function (): void {
    $mock = new Mock();

    $response = $mock->prompt('test');
    \expect($response->model)->toBe('mock');
    \expect($response->provider)->toBe('mock');
});

\it('allows setting responses dynamically', function (): void {
    $mock = new Mock();

    $mock->setResponse('dynamic', 'Dynamic response!');

    $response = $mock->prompt('dynamic');
    \expect($response->content)->toBe('Dynamic response!');
});

\it('handles empty responses array', function (): void {
    $mock = new Mock(['responses' => []]);

    $response = $mock->prompt('anything');
    \expect($response->content)->toBe('Mock response to: anything');
});
