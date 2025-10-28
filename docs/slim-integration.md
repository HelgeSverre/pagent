# Slim Framework Integration Guide

This guide shows how to integrate Pagent with [Slim Framework](https://www.slimframework.com/) 4.x, creating a clean pattern for using AI agents in your Slim application.

## Overview

We'll set up:

1. Centralized agent configuration
2. Dependency injection integration
3. Route handlers using agents
4. Middleware for logging agent interactions
5. Error handling

---

## Installation

```bash
# Install Slim and Pagent
composer require slim/slim:"^4.0"
composer require slim/psr7
composer require helgesverre/pagent

# Optional: for better DI container
composer require php-di/php-di
```

---

## Project Structure

```
your-app/
├── config/
│   ├── agents.php          # Agent configurations
│   └── container.php       # DI container setup
├── src/
│   ├── Middleware/
│   │   └── AgentLogging.php
│   └── Controllers/
│       ├── SupportController.php
│       └── ContentController.php
├── public/
│   └── index.php           # Application entry point
├── .env                    # API keys
└── composer.json
```

---

## Step 1: Configure Environment

**`.env`**:

```env
# API Keys
ANTHROPIC_API_KEY=your-key-here
OPENAI_API_KEY=your-key-here

# App Config
APP_ENV=production
APP_DEBUG=false
```

---

## Step 2: Create Agent Configuration

**`config/agents.php`**:

```php
<?php

declare(strict_types=1);

use function Pagent\agent;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// =============================================================================
// CUSTOMER SUPPORT
// =============================================================================

agent('support')
    ->provider('anthropic')
    ->model('claude-3-haiku-20240307')
    ->system('You are a helpful customer support agent. Be concise and professional.')
    ->temperature(0.3)
    ->tool('search_orders', 'Search customer orders by email', function (string $email) {
        // In real app, query your database
        return [
            'orders' => [
                ['id' => '12345', 'status' => 'shipped', 'total' => 99.99],
                ['id' => '12346', 'status' => 'processing', 'total' => 149.99],
            ],
        ];
    })
    ->tool('get_order_status', 'Get order status by ID', function (string $orderId) {
        // In real app, query your database
        return [
            'order_id' => $orderId,
            'status' => 'shipped',
            'tracking' => 'ABC123456',
            'eta' => '2025-10-30',
        ];
    });

// =============================================================================
// CONTENT GENERATION
// =============================================================================

agent('blog-writer')
    ->provider('openai')
    ->model('gpt-4o-mini')
    ->system('You are a professional blog writer. Create engaging, SEO-optimized content.')
    ->temperature(0.8);

agent('product-descriptions')
    ->provider('anthropic')
    ->model('claude-3-sonnet-20240229')
    ->system('Write compelling product descriptions that highlight benefits and features.')
    ->temperature(0.7);

// =============================================================================
// DATA PROCESSING
// =============================================================================

agent('data-analyst')
    ->provider('openai')
    ->model('gpt-4')
    ->system('Analyze data and provide clear, actionable insights.')
    ->temperature(0.3);

// =============================================================================
// HELPER FUNCTION
// =============================================================================

/**
 * Get a configured agent by name.
 */
function pagent(string $name): \Pagent\Agent
{
    return agent($name);
}
```

---

## Step 3: Setup Dependency Injection Container

**`config/container.php`**:

```php
<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

$containerBuilder = new ContainerBuilder();

// Register services
$containerBuilder->addDefinitions([
    // Response factory for Slim
    ResponseFactoryInterface::class => function () {
        return new ResponseFactory();
    },

    // Pagent agents - lazy loaded
    'agent.support' => function () {
        return pagent('support');
    },

    'agent.blog-writer' => function () {
        return pagent('blog-writer');
    },

    'agent.product-descriptions' => function () {
        return pagent('product-descriptions');
    },

    'agent.data-analyst' => function () {
        return pagent('data-analyst');
    },

    // Logger (optional, for middleware)
    'logger' => function () {
        $logger = new \Monolog\Logger('app');
        $logger->pushHandler(
            new \Monolog\Handler\StreamHandler(__DIR__ . '/../logs/app.log', \Monolog\Level::Debug)
        );
        return $logger;
    },
]);

return $containerBuilder->build();
```

---

## Step 4: Create Controllers

**`src/Controllers/SupportController.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Pagent\Agent;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SupportController
{
    public function __construct(
        private Agent $supportAgent
    ) {}

    /**
     * Handle support chat message.
     */
    public function chat(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $message = $data['message'] ?? '';

        if (empty($message)) {
            $response->getBody()->write(json_encode([
                'error' => 'Message is required',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            // Get agent response
            $agentResponse = $this->supportAgent->prompt($message);

            $response->getBody()->write(json_encode([
                'reply' => $agentResponse->content,
                'model' => $agentResponse->model,
                'tokens' => $agentResponse->tokens,
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => 'Failed to process request',
                'message' => $e->getMessage(),
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get conversation history.
     */
    public function history(Request $request, Response $response): Response
    {
        $history = array_map(function ($message) {
            return [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }, $this->supportAgent->messages);

        $response->getBody()->write(json_encode([
            'history' => $history,
            'count' => count($history),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Reset conversation.
     */
    public function reset(Request $request, Response $response): Response
    {
        $this->supportAgent->reset();

        $response->getBody()->write(json_encode([
            'message' => 'Conversation reset successfully',
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

**`src/Controllers/ContentController.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Pagent\Agent;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ContentController
{
    public function __construct(
        private Agent $blogWriter,
        private Agent $productDescriptions
    ) {}

    /**
     * Generate blog post.
     */
    public function generateBlog(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $topic = $data['topic'] ?? '';
        $wordCount = $data['word_count'] ?? 500;

        if (empty($topic)) {
            $response->getBody()->write(json_encode([
                'error' => 'Topic is required',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $prompt = "Write a {$wordCount}-word blog post about: {$topic}";
        $result = $this->blogWriter->prompt($prompt);

        $response->getBody()->write(json_encode([
            'article' => $result->content,
            'tokens_used' => $result->tokens,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Generate product description.
     */
    public function generateProductDescription(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $productName = $data['product_name'] ?? '';
        $features = $data['features'] ?? [];

        if (empty($productName)) {
            $response->getBody()->write(json_encode([
                'error' => 'Product name is required',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $featuresText = implode(', ', $features);
        $prompt = "Write a compelling product description for: {$productName}. Features: {$featuresText}";

        $result = $this->productDescriptions->prompt($prompt);

        $response->getBody()->write(json_encode([
            'description' => $result->content,
            'tokens_used' => $result->tokens,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

---

## Step 5: Create Middleware (Optional)

**`src/Middleware/AgentLogging.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class AgentLogging implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $startTime = microtime(true);

        // Process request
        $response = $handler->handle($request);

        $duration = microtime(true) - $startTime;

        // Log agent interactions
        if (str_contains($request->getUri()->getPath(), '/api/')) {
            $this->logger->info('Agent API call', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'duration' => round($duration, 3),
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}
```

---

## Step 6: Create Application Entry Point

**`public/index.php`**:

```php
<?php

declare(strict_types=1);

use App\Controllers\ContentController;
use App\Controllers\SupportController;
use App\Middleware\AgentLogging;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load agent configurations
require __DIR__ . '/../config/agents.php';

// Load DI container
$container = require __DIR__ . '/../config/container.php';
AppFactory::setContainer($container);

// Create app
$app = AppFactory::create();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Add custom middleware (optional)
if ($container->has('logger')) {
    $app->add(new AgentLogging($container->get('logger')));
}

// =============================================================================
// ROUTES
// =============================================================================

// Support routes
$app->post('/api/support/chat', function ($request, $response) use ($container) {
    $controller = new SupportController($container->get('agent.support'));
    return $controller->chat($request, $response);
});

$app->get('/api/support/history', function ($request, $response) use ($container) {
    $controller = new SupportController($container->get('agent.support'));
    return $controller->history($request, $response);
});

$app->post('/api/support/reset', function ($request, $response) use ($container) {
    $controller = new SupportController($container->get('agent.support'));
    return $controller->reset($request, $response);
});

// Content generation routes
$app->post('/api/content/blog', function ($request, $response) use ($container) {
    $controller = new ContentController(
        $container->get('agent.blog-writer'),
        $container->get('agent.product-descriptions')
    );
    return $controller->generateBlog($request, $response);
});

$app->post('/api/content/product-description', function ($request, $response) use ($container) {
    $controller = new ContentController(
        $container->get('agent.blog-writer'),
        $container->get('agent.product-descriptions')
    );
    return $controller->generateProductDescription($request, $response);
});

// Simple helper function for quick agent access (alternative to DI)
$app->post('/api/quick-chat', function ($request, $response) {
    $data = $request->getParsedBody();
    $agentName = $data['agent'] ?? 'support';
    $message = $data['message'] ?? '';

    // Use the pagent() helper
    $result = pagent($agentName)->prompt($message);

    $response->getBody()->write(json_encode([
        'reply' => $result->content,
    ]));

    return $response->withHeader('Content-Type', 'application/json');
});

// Health check
$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'status' => 'ok',
        'agents' => ['support', 'blog-writer', 'product-descriptions'],
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Run app
$app->run();
```

---

## Step 7: Update Composer Autoload

**`composer.json`**:

```json
{
  "name": "yourname/slim-pagent-app",
  "require": {
    "php": "^8.3",
    "slim/slim": "^4.0",
    "slim/psr7": "^1.6",
    "php-di/php-di": "^7.0",
    "helgesverre/pagent": "^1.0",
    "vlucas/phpdotenv": "^5.6",
    "monolog/monolog": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    },
    "files": ["config/agents.php"]
  }
}
```

Run:

```bash
composer dump-autoload
```

---

## Usage Examples

### Start the Server

```bash
php -S localhost:8000 -t public
```

### Test Support Chat

```bash
curl -X POST http://localhost:8000/api/support/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "I need help with my order #12345"}'
```

Response:

```json
{
  "reply": "I'd be happy to help you with order #12345. Let me check the status for you...",
  "model": "claude-3-haiku-20240307",
  "tokens": 42
}
```

### Generate Blog Post

```bash
curl -X POST http://localhost:8000/api/content/blog \
  -H "Content-Type: application/json" \
  -d '{"topic": "The Future of AI", "word_count": 300}'
```

### Quick Chat (Any Agent)

```bash
curl -X POST http://localhost:8000/api/quick-chat \
  -H "Content-Type: application/json" \
  -d '{"agent": "data-analyst", "message": "Analyze sales data: Q1: 10k, Q2: 15k, Q3: 12k"}'
```

---

## Advanced: Multi-Agent Workflow Route

Add to `public/index.php`:

```php
use function Pagent\Orchestration\pipeline;

$app->post('/api/workflow/content-pipeline', function ($request, $response) {
    $data = $request->getParsedBody();
    $topic = $data['topic'] ?? '';

    // Pipeline: Write → Review → Optimize
    $result = pipeline('content-creation')
        ->agent('blog-writer', function ($topic) {
            return "Write a blog post about: {$topic}";
        })
        ->agent('data-analyst', function ($article) {
            return "Review this article for factual accuracy:\n{$article}";
        })
        ->agent('product-descriptions', function ($review) {
            return "Create a social media summary of this review:\n{$review}";
        })
        ->run($topic);

    $response->getBody()->write(json_encode([
        'final_output' => $result,
    ]));

    return $response->withHeader('Content-Type', 'application/json');
});
```

---

## Best Practices

### 1. **Use Dependency Injection**

Inject agents via constructor for testability:

```php
class MyController
{
    public function __construct(private Agent $agent) {}
}
```

### 2. **Handle Errors Gracefully**

```php
try {
    $result = pagent('support')->prompt($message);
} catch (\RuntimeException $e) {
    // Log and return user-friendly error
    $logger->error('Agent failed', ['error' => $e->getMessage()]);
    return $response->withStatus(503);
}
```

### 3. **Rate Limiting** (Optional)

```php
use Slim\Middleware\RateLimiter;

$app->add(new RateLimiter([
    'requests' => 100,
    'interval' => '1 hour',
]));
```

### 4. **Environment-Based Config**

```php
$model = $_ENV['APP_ENV'] === 'production'
    ? 'claude-3-opus-20240229'
    : 'claude-3-haiku-20240307';

agent('support')->model($model);
```

---

## Complete Example Application

See [`examples/slim-app/`](../examples/slim-app/) for a complete working Slim application with:

- ✅ Full CRUD API using Pagent
- ✅ Multi-agent workflows
- ✅ Conversation persistence
- ✅ Error handling
- ✅ Logging middleware
- ✅ Docker setup

---

## Summary

This integration provides:

- ✅ Clean separation of concerns
- ✅ Dependency injection support
- ✅ PSR-7/PSR-15 compliance
- ✅ Easy testing and mocking
- ✅ Production-ready patterns

Your Slim app now has AI superpowers! 🚀
