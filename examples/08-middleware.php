<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Pagent\Middleware\MetricsMiddleware;
use Pagent\Middleware\RateLimitMiddleware;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "🔧 Pagent Middleware System Demo\n";
echo "=================================\n\n";

echo "=== Example 1: Metrics Middleware ===\n\n";

$metrics = new MetricsMiddleware();

agent('metrics-bot')
    ->provider('openai')
    ->system('You are a helpful assistant. Be very concise.')
    ->middleware($metrics);

$bot = agent('metrics-bot');

echo "Making 3 requests...\n";
$bot->prompt('Say hi');
$bot->prompt('What is 2+2?');
$bot->prompt('Goodbye');

echo "\nMetrics:\n";
echo "  Total requests: " . count($metrics->getMetrics()) . "\n";
echo "  Average duration: " . round($metrics->getAverageDuration(), 2) . "ms\n";
echo "  Total tokens: " . $metrics->getTotalTokens() . "\n\n";

echo "=== Example 2: Rate Limiting ===\n\n";

$rateLimit = new RateLimitMiddleware(maxRequests: 3, windowSeconds: 60);

agent('limited-bot')
    ->provider('openai')
    ->system('You are a helpful assistant.')
    ->middleware($rateLimit);

$limitedBot = agent('limited-bot');

echo "Rate limit: 3 requests per 60 seconds\n";
echo "Remaining: " . $rateLimit->getRemainingRequests() . "\n\n";

for ($i = 1; $i <= 3; $i++) {
    echo "Request {$i}... ";
    $limitedBot->prompt("Request {$i}");
    echo "OK (Remaining: {$rateLimit->getRemainingRequests()})\n";
}

echo "\nTrying request 4... ";
try {
    $limitedBot->prompt('Request 4');
    echo "OK\n";
} catch (RuntimeException $e) {
    echo "BLOCKED\n";
    echo "  Reason: " . mb_substr($e->getMessage(), 0, 60) . "...\n";
}

echo "\n";

echo "=== Example 3: Multiple Middleware ===\n\n";

$metricsMulti = new MetricsMiddleware();
$rateLimitMulti = new RateLimitMiddleware(maxRequests: 5);

agent('multi-middleware-bot')
    ->provider('openai')
    ->system('You are a helpful assistant.')
    ->middleware($metricsMulti)
    ->middleware($rateLimitMulti)
    ->middleware('logging');

$multiBot = agent('multi-middleware-bot');

echo "Bot has " . count($multiBot->getMiddleware()) . " middleware layers\n";

$multiBot->prompt('Test request');

echo "After 1 request:\n";
echo "  Metrics collected: " . count($metricsMulti->getMetrics()) . "\n";
echo "  Rate limit remaining: " . $rateLimitMulti->getRemainingRequests() . "/5\n\n";

echo "=== Example 4: Custom Middleware ===\n\n";

$customMiddleware = new class () implements Pagent\Contracts\Middleware {
    public int $callCount = 0;

    public function before(string $message, array $options): array
    {
        $this->callCount++;
        echo "  [Before] Call #{$this->callCount}: {$message}\n";

        return $options;
    }

    public function after(object $response): object
    {
        echo "  [After] Response received: " . mb_substr($response->content, 0, 40) . "...\n";

        return $response;
    }
};

agent('custom-middleware-bot')
    ->provider('openai')
    ->system('You are a helpful assistant.')
    ->middleware($customMiddleware);

$customBot = agent('custom-middleware-bot');

echo "Making request with custom middleware:\n";
$customBot->prompt('Hello');

echo "\nTotal calls tracked: {$customMiddleware->callCount}\n\n";

echo "=== Example 5: Middleware Inspection ===\n\n";

$inspectBot = agent('multi-middleware-bot');

echo "Middleware stack:\n";
foreach ($inspectBot->getMiddleware() as $i => $mw) {
    $class = get_class($mw);
    $shortName = mb_substr($class, mb_strrpos($class, '\\') + 1);
    echo "  " . ($i + 1) . ". {$shortName}\n";
}

echo "\n✅ All middleware examples completed!\n";
echo "\n🔧 Middleware system enables powerful extensibility!\n";
