# Laravel Integration Guide

This guide shows how to integrate Pagent with [Laravel](https://laravel.com/) 10.x and 11.x, leveraging Laravel's service container, facades, and configuration system.

## Overview

We'll set up:

1. Laravel service provider for agent registration
2. Configuration file for all agents
3. Facade for easy access
4. Artisan commands for testing agents
5. Queue integration for async agent tasks
6. Event/listener integration

---

## Installation

```bash
# Install Pagent
composer require helgesverre/pagent

# Publish configuration (we'll create this)
php artisan vendor:publish --tag=pagent-config
```

---

## Project Structure

```
your-laravel-app/
├── app/
│   ├── Agents/
│   │   ├── SupportAgent.php
│   │   └── ContentAgent.php
│   ├── Console/
│   │   └── Commands/
│   │       └── TestAgentCommand.php
│   ├── Http/
│   │   └── Controllers/
│   │       ├── AgentController.php
│   │       └── SupportController.php
│   ├── Jobs/
│   │   └── ProcessAgentRequest.php
│   └── Providers/
│       └── AgentServiceProvider.php
├── config/
│   └── agents.php
├── routes/
│   ├── web.php
│   └── api.php
└── .env
```

---

## Step 1: Configure Environment

**`.env`**:

```env
# API Keys
ANTHROPIC_API_KEY=your-key-here
OPENAI_API_KEY=your-key-here

# Agent Configuration
AGENT_DEFAULT_PROVIDER=anthropic
AGENT_DEFAULT_MODEL=claude-3-haiku-20240307
AGENT_TIMEOUT=30
```

---

## Step 2: Create Configuration File

**`config/agents.php`**:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | Default AI provider to use when not specified.
    | Options: 'anthropic', 'openai'
    |
    */
    'default_provider' => env('AGENT_DEFAULT_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | Default model to use when not specified.
    |
    */
    'default_model' => env('AGENT_DEFAULT_MODEL', 'claude-3-haiku-20240307'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Request timeout in seconds.
    |
    */
    'timeout' => env('AGENT_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Agent Definitions
    |--------------------------------------------------------------------------
    |
    | Define your application's AI agents here.
    |
    */
    'agents' => [
        'support' => [
            'provider' => 'anthropic',
            'model' => 'claude-3-haiku-20240307',
            'system' => 'You are a helpful customer support agent for our e-commerce platform. Be concise, professional, and helpful.',
            'temperature' => 0.3,
            'tools' => [
                'search_orders',
                'check_shipping',
                'process_refund',
            ],
        ],

        'blog-writer' => [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'system' => 'You are a professional blog writer. Create engaging, SEO-optimized content with proper structure.',
            'temperature' => 0.8,
        ],

        'code-reviewer' => [
            'provider' => 'anthropic',
            'model' => 'claude-3-opus-20240229',
            'system' => 'You are a senior code reviewer. Provide constructive feedback on code quality, security, and best practices.',
            'temperature' => 0.2,
        ],

        'data-analyst' => [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'system' => 'You are a data analyst. Analyze data, find insights, and create clear summaries.',
            'temperature' => 0.3,
        ],

        'social-media' => [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'system' => 'Create engaging social media posts. Be concise, use emojis appropriately, include relevant hashtags.',
            'temperature' => 0.9,
        ],
    ],
];
```

---

## Step 3: Create Service Provider

**`app/Providers/AgentServiceProvider.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Pagent\Agent;
use function Pagent\agent;

class AgentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register agents as singletons in the container
        $agents = config('agents.agents', []);

        foreach ($agents as $name => $config) {
            $this->app->singleton("agent.{$name}", function ($app) use ($name, $config) {
                $agent = agent($name)
                    ->provider($config['provider'] ?? config('agents.default_provider'))
                    ->model($config['model'] ?? config('agents.default_model'))
                    ->system($config['system'] ?? '');

                if (isset($config['temperature'])) {
                    $agent->temperature($config['temperature']);
                }

                // Register tools if defined
                if (isset($config['tools'])) {
                    foreach ($config['tools'] as $toolName) {
                        $this->registerTool($agent, $toolName);
                    }
                }

                return $agent;
            });
        }

        // Register helper for accessing any agent
        $this->app->singleton('pagent', function ($app) {
            return new class($app) {
                public function __construct(private $app) {}

                public function __invoke(string $name): Agent
                {
                    return $this->app->make("agent.{$name}");
                }
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../../config/agents.php' => config_path('agents.php'),
        ], 'pagent-config');
    }

    /**
     * Register tool functions for agents.
     */
    private function registerTool(Agent $agent, string $toolName): void
    {
        match ($toolName) {
            'search_orders' => $agent->tool(
                'search_orders',
                'Search customer orders by email',
                function (string $email) {
                    return app(\App\Services\OrderService::class)->searchByEmail($email);
                }
            ),
            'check_shipping' => $agent->tool(
                'check_shipping',
                'Check shipping status by tracking number',
                function (string $tracking) {
                    return app(\App\Services\ShippingService::class)->checkStatus($tracking);
                }
            ),
            'process_refund' => $agent->tool(
                'process_refund',
                'Process refund for an order',
                function (string $orderId, string $reason) {
                    return app(\App\Services\RefundService::class)->process($orderId, $reason);
                }
            ),
            default => null,
        };
    }
}
```

**Register the provider in `config/app.php`**:

```php
'providers' => [
    // ...
    App\Providers\AgentServiceProvider::class,
],
```

---

## Step 4: Create Facade (Optional)

**`app/Facades/Pagent.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Pagent\Agent __invoke(string $name)
 */
class Pagent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'pagent';
    }
}
```

**Register the alias in `config/app.php`**:

```php
'aliases' => [
    // ...
    'Pagent' => App\Facades\Pagent::class,
],
```

---

## Step 5: Create Controllers

**`app/Http/Controllers/SupportController.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facades\Pagent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    /**
     * Handle support chat message.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'session_id' => 'nullable|string',
        ]);

        try {
            $agent = Pagent('support');

            // Load conversation history if session exists
            if ($sessionId = $validated['session_id'] ?? null) {
                $this->loadConversationHistory($agent, $sessionId);
            }

            $response = $agent->prompt($validated['message']);

            // Save conversation history
            if ($sessionId) {
                $this->saveConversationHistory($agent, $sessionId);
            }

            return response()->json([
                'reply' => $response->content,
                'model' => $response->model,
                'tokens' => $response->tokens,
                'session_id' => $sessionId ?? uniqid('support_'),
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'error' => 'Failed to process your request. Please try again.',
            ], 500);
        }
    }

    /**
     * Get conversation history.
     */
    public function history(Request $request, string $sessionId): JsonResponse
    {
        $history = cache()->get("agent_history:{$sessionId}", []);

        return response()->json([
            'session_id' => $sessionId,
            'messages' => $history,
            'count' => count($history),
        ]);
    }

    /**
     * Reset conversation.
     */
    public function reset(string $sessionId): JsonResponse
    {
        cache()->forget("agent_history:{$sessionId}");

        return response()->json([
            'message' => 'Conversation reset successfully',
        ]);
    }

    private function loadConversationHistory($agent, string $sessionId): void
    {
        $history = cache()->get("agent_history:{$sessionId}", []);

        foreach ($history as $message) {
            $agent->messages[] = $message;
        }
    }

    private function saveConversationHistory($agent, string $sessionId): void
    {
        cache()->put(
            "agent_history:{$sessionId}",
            $agent->messages,
            now()->addHours(24)
        );
    }
}
```

**`app/Http/Controllers/ContentController.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facades\Pagent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    /**
     * Generate blog post.
     */
    public function generateBlog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => 'required|string|max:200',
            'word_count' => 'nullable|integer|min:100|max:2000',
        ]);

        $wordCount = $validated['word_count'] ?? 500;
        $prompt = "Write a {$wordCount}-word blog post about: {$validated['topic']}";

        $response = Pagent('blog-writer')->prompt($prompt);

        return response()->json([
            'article' => $response->content,
            'tokens_used' => $response->tokens,
        ]);
    }

    /**
     * Generate social media post.
     */
    public function generateSocialPost(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => 'required|string|max:200',
            'platform' => 'required|in:twitter,linkedin,facebook',
        ]);

        $prompt = "Create a {$validated['platform']} post about: {$validated['topic']}";
        $response = Pagent('social-media')->prompt($prompt);

        return response()->json([
            'post' => $response->content,
            'tokens_used' => $response->tokens,
        ]);
    }
}
```

---

## Step 6: Define Routes

**`routes/api.php`**:

```php
<?php

use App\Http\Controllers\ContentController;
use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

// Support routes
Route::prefix('support')->group(function () {
    Route::post('/chat', [SupportController::class, 'chat']);
    Route::get('/history/{sessionId}', [SupportController::class, 'history']);
    Route::post('/reset/{sessionId}', [SupportController::class, 'reset']);
});

// Content generation routes
Route::prefix('content')->group(function () {
    Route::post('/blog', [ContentController::class, 'generateBlog']);
    Route::post('/social', [ContentController::class, 'generateSocialPost']);
});
```

---

## Step 7: Create Queue Job (Optional)

**`app/Jobs/ProcessAgentRequest.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Facades\Pagent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAgentRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $agentName,
        private string $prompt,
        private ?string $userId = null
    ) {}

    public function handle(): void
    {
        $response = Pagent($this->agentName)->prompt($this->prompt);

        // Store result or dispatch event
        event(new \App\Events\AgentResponseReceived(
            $this->agentName,
            $this->prompt,
            $response->content,
            $this->userId
        ));
    }
}
```

**Usage**:

```php
use App\Jobs\ProcessAgentRequest;

// Dispatch to queue
ProcessAgentRequest::dispatch('blog-writer', 'Write about Laravel', auth()->id());
```

---

## Step 8: Create Artisan Command

**`app/Console/Commands/TestAgentCommand.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Pagent;
use Illuminate\Console\Command;

class TestAgentCommand extends Command
{
    protected $signature = 'agent:test {agent} {prompt}';
    protected $description = 'Test an agent with a prompt';

    public function handle(): int
    {
        $agentName = $this->argument('agent');
        $prompt = $this->argument('prompt');

        $this->info("Testing agent: {$agentName}");
        $this->info("Prompt: {$prompt}");
        $this->newLine();

        try {
            $response = Pagent($agentName)->prompt($prompt);

            $this->info('Response:');
            $this->line($response->content);
            $this->newLine();
            $this->comment("Model: {$response->model}");
            $this->comment("Tokens: {$response->tokens}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
```

**Usage**:

```bash
php artisan agent:test support "I need help with my order"
php artisan agent:test blog-writer "Write about PHP 8.3 features"
```

---

## Usage Examples

### Controller Injection

```php
use App\Facades\Pagent;

class OrderController extends Controller
{
    public function analyze(Order $order)
    {
        $analysis = Pagent('data-analyst')->prompt(
            "Analyze this order data: " . json_encode($order->toArray())
        );

        return response()->json(['analysis' => $analysis->content]);
    }
}
```

### Blade Templates

```blade
<!-- resources/views/support/chat.blade.php -->
<div>
    @foreach($messages as $message)
        <div class="message {{ $message['role'] }}">
            {{ $message['content'] }}
        </div>
    @endforeach
</div>
```

### Multi-Agent Workflow

```php
use function Pagent\Orchestration\pipeline;

$article = pipeline('content-creation')
    ->agent('blog-writer', fn($topic) => "Write about: {$topic}")
    ->agent('code-reviewer', fn($article) => "Review this article: {$article}")
    ->agent('social-media', fn($review) => "Create social post from: {$review}")
    ->run('Laravel 11 Features');
```

### Service Class

```php
namespace App\Services;

use App\Facades\Pagent;

class ContentGenerationService
{
    public function generateBlogPost(string $topic, int $wordCount = 500): string
    {
        $response = Pagent('blog-writer')->prompt(
            "Write a {$wordCount}-word blog post about: {$topic}"
        );

        return $response->content;
    }

    public function generateProductDescription(array $product): string
    {
        $response = Pagent('product-descriptions')->prompt(
            "Write a product description for: " . json_encode($product)
        );

        return $response->content;
    }
}
```

---

## Testing

**`tests/Feature/AgentTest.php`**:

```php
<?php

namespace Tests\Feature;

use App\Facades\Pagent;
use Tests\TestCase;

class AgentTest extends TestCase
{
    public function test_support_agent_responds(): void
    {
        $response = Pagent('support')->prompt('Hello');

        $this->assertNotEmpty($response->content);
        $this->assertIsString($response->content);
    }

    public function test_blog_writer_generates_content(): void
    {
        $response = Pagent('blog-writer')->prompt('Write about PHP');

        $this->assertNotEmpty($response->content);
        $this->assertGreaterThan(100, strlen($response->content));
    }

    public function test_support_endpoint(): void
    {
        $response = $this->postJson('/api/support/chat', [
            'message' => 'I need help',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'reply',
                'model',
                'tokens',
                'session_id',
            ]);
    }
}
```

---

## Best Practices

### 1. Use Dependency Injection

```php
use Pagent\Agent;

class MyController extends Controller
{
    public function __construct(
        private Agent $supportAgent
    ) {}
}
```

### 2. Cache Agent Responses

```php
$response = cache()->remember(
    "agent_response:{$cacheKey}",
    now()->addHours(1),
    fn() => Pagent('blog-writer')->prompt($topic)
);
```

### 3. Log Agent Interactions

```php
use Illuminate\Support\Facades\Log;

$response = Pagent('support')->prompt($message);

Log::info('Agent interaction', [
    'agent' => 'support',
    'prompt' => $message,
    'response_length' => strlen($response->content),
    'tokens' => $response->tokens,
]);
```

### 4. Handle Errors Gracefully

```php
try {
    $response = Pagent('support')->prompt($message);
} catch (\Exception $e) {
    report($e);
    return response()->json(['error' => 'Service temporarily unavailable'], 503);
}
```

### 5. Use Events for Agent Responses

```php
// app/Events/AgentResponseReceived.php
class AgentResponseReceived
{
    public function __construct(
        public string $agentName,
        public string $prompt,
        public string $response,
        public ?string $userId = null
    ) {}
}

// app/Listeners/LogAgentResponse.php
class LogAgentResponse
{
    public function handle(AgentResponseReceived $event): void
    {
        Log::info('Agent response', [
            'agent' => $event->agentName,
            'user_id' => $event->userId,
            'response_length' => strlen($event->response),
        ]);
    }
}
```

---

## Environment-Specific Configuration

```php
// config/agents.php

'agents' => [
    'support' => [
        'provider' => 'anthropic',
        'model' => app()->environment('production')
            ? 'claude-3-opus-20240229'
            : 'claude-3-haiku-20240307',
        'temperature' => app()->environment('production') ? 0.3 : 0.5,
    ],
],
```

---

## Summary

This Laravel integration provides:

- ✅ **Service Container Integration** - Agents as singletons
- ✅ **Configuration Management** - Centralized `config/agents.php`
- ✅ **Facade Support** - Clean `Pagent('name')` syntax
- ✅ **Queue Integration** - Async agent processing
- ✅ **Artisan Commands** - CLI testing and management
- ✅ **Event System** - Track agent interactions
- ✅ **Testing Support** - Easy to mock and test
- ✅ **Laravel Conventions** - Follows Laravel patterns

Your Laravel app now has AI superpowers! 🚀
