<?php

declare(strict_types=1);

use Pagent\Agent;
use Pagent\Middleware\LoggingMiddleware;
use Pagent\Middleware\MetricsMiddleware;
use Pagent\Middleware\RateLimitMiddleware;

test('it adds middleware to agent', function (): void {
    $agent = testAgent();
    $agent->middleware('logging');

    expect($agent->getMiddleware())->toHaveCount(1)
        ->and($agent->getMiddleware()[0])->toBeInstanceOf(LoggingMiddleware::class);
});

test('it adds middleware via class instance', function (): void {
    $metrics = new MetricsMiddleware;
    $agent = testAgent();
    $agent->middleware($metrics);

    expect($agent->getMiddleware())->toHaveCount(1)
        ->and($agent->getMiddleware()[0])->toBe($metrics);
});

test('it supports multiple middleware', function (): void {
    $agent = testAgent();
    $agent->middleware('logging')
        ->middleware('metrics')
        ->middleware('rateLimit');

    expect($agent->getMiddleware())->toHaveCount(3);
});

test('rate limit middleware tracks requests', function (): void {
    $rateLimit = new RateLimitMiddleware(maxRequests: 3, windowSeconds: 60);

    expect($rateLimit->getRemainingRequests())->toBe(3);

    $rateLimit->before('test', []);
    expect($rateLimit->getRemainingRequests())->toBe(2);

    $rateLimit->before('test', []);
    $rateLimit->before('test', []);
    expect($rateLimit->getRemainingRequests())->toBe(0);
});

test('rate limit middleware throws when exceeded', function (): void {
    $rateLimit = new RateLimitMiddleware(maxRequests: 2, windowSeconds: 60);

    $rateLimit->before('test 1', []);
    $rateLimit->before('test 2', []);

    expect(fn () => $rateLimit->before('test 3', []))
        ->toThrow(RuntimeException::class, 'Rate limit exceeded');
});

test('metrics middleware tracks duration', function (): void {
    $metrics = new MetricsMiddleware;

    $metrics->before('test', []);
    usleep(10000);
    $metrics->after((object) ['content' => 'test', 'tokens' => 100]);

    expect($metrics->getMetrics())->toHaveCount(1)
        ->and($metrics->getMetrics()[0]['duration_ms'])->toBeGreaterThan(0)
        ->and($metrics->getTotalTokens())->toBe(100);
});

test('metrics middleware calculates averages', function (): void {
    $metrics = new MetricsMiddleware;

    $metrics->before('test 1', []);
    usleep(1000);
    $metrics->after((object) ['content' => 'test', 'tokens' => 100]);

    $metrics->before('test 2', []);
    usleep(1000);
    $metrics->after((object) ['content' => 'test', 'tokens' => 200]);

    expect($metrics->getAverageDuration())->toBeGreaterThan(0)
        ->and($metrics->getTotalTokens())->toBe(300);
});

test('middleware can modify options', function (): void {
    $mockProvider = mock(['test' => 'response']);

    $agent = new Agent('middleware-test');
    $agent->provider($mockProvider);

    $customMiddleware = new class implements Pagent\Contracts\Middleware
    {
        public function before(string $message, array $options): array
        {
            $options['modified'] = true;

            return $options;
        }

        public function after(object $response): object
        {
            return $response;
        }
    };

    $agent->middleware($customMiddleware);

    $response = $agent->prompt('test');

    expect($response)->toBeObject();
});
