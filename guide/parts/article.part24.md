# Chapter 24: Laravel and Symfony Integration

Pagent is intentionally framework-agnostic - there are no built-in Laravel service providers, Symfony bundles, or framework-specific packages. This design choice keeps Pagent lightweight and portable, but it also means you'll need to integrate it manually with your framework of choice.

The good news? Pagent's simple architecture makes integration straightforward. This chapter demonstrates practical integration patterns for Laravel and Symfony, showing you how to leverage framework features like dependency injection, queue systems, middleware, and routing while maintaining clean separation of concerns.

You'll learn how to register agents as services, create API endpoints that expose agent capabilities, queue long-running agent tasks, implement rate limiting, and structure your codebase for maintainability.

## Laravel Integration

Laravel's service container, facades, and expressive syntax make it a natural fit for Pagent's fluent API. Let's explore several integration patterns, from basic setup to production-ready implementations.

### Service Provider Registration

The simplest way to integrate Pagent with Laravel is through a custom service provider. This makes agents available throughout your application via dependency injection.

Create a service provider:

```bash
php artisan make:provider PagentServiceProvider
```

Register the global Registry as a singleton:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Pagent\Registry;

class PagentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the global Registry
        $this->app->singleton(Registry::class, fn() => new Registry);

        // Make the agent() helper available
        require_once base_path('vendor/pagent/pagent/src/functions.php');
    }

    public function boot(): void
    {
        // Optional: Create commonly-used agents at boot time
        if ($this->app->environment('production')) {
            $this->registerProductionAgents();
        }
    }

    private function registerProductionAgents(): void
    {
        agent('support')
            ->provider('anthropic', [
                'api_key' => config('services.anthropic.key'),
            ])
            ->model('claude-sonnet-4-20250514')
            ->system('You are a helpful customer support assistant.')
            ->maxTokens(2048)
            ->build();
    }
}
```

Add to `config/app.php`:

```php
'providers' => ServiceProvider::defaultProviders()->merge([
    // ...
    App\Providers\PagentServiceProvider::class,
])->toArray(),
```

Now agents are available anywhere in your Laravel application:

```php
// In a controller, job, command, etc.
$agent = agent('support');
$response = $agent->prompt('How do I reset my password?');
```

### Configuration Management

Store provider credentials in `config/services.php`:

```php
return [
    // ...

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    ],
];
```

Create a dedicated config file for agent defaults at `config/pagent.php`:

```php
<?php

return [
    'default_provider' => env('PAGENT_PROVIDER', 'anthropic'),

    'defaults' => [
        'temperature' => 0.7,
        'max_tokens' => 4096,
    ],

    'agents' => [
        'support' => [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-20250514',
            'system' => 'You are a helpful customer support assistant.',
        ],

        'analyzer' => [
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'system' => 'You analyze data and provide insights.',
        ],
    ],
];
```

Load agents from configuration in your service provider:

```php
private function registerProductionAgents(): void
{
    foreach (config('pagent.agents', []) as $name => $config) {
        $builder = agent($name)
            ->provider($config['provider'], [
                'api_key' => config("services.{$config['provider']}.key"),
            ])
            ->model($config['model'])
            ->system($config['system']);

        if (isset($config['temperature'])) {
            $builder->temperature($config['temperature']);
        }

        if (isset($config['max_tokens'])) {
            $builder->maxTokens($config['max_tokens']);
        }

        $builder->build();
    }
}
```

### Controller Integration

Create a RESTful API endpoint for chat interactions:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function Pagent\agent;

class ChatController extends Controller
{
    public function respond(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:10000',
            'session_id' => 'sometimes|string',
        ]);

        $agent = agent('support')
            ->sessionId($validated['session_id'] ?? $request->user()->id)
            ->memory('Sqlite', [
                'path' => storage_path('conversations.db'),
            ]);

        try {
            $response = $agent->prompt($validated['message']);

            return response()->json([
                'success' => true,
                'reply' => $response->content,
                'tokens' => $response->tokens,
                'session_id' => $agent->sessionId(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process your request.',
            ], 500);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $agent = agent('support')
            ->sessionId($request->user()->id)
            ->memory('Sqlite', [
                'path' => storage_path('conversations.db'),
            ]);

        return response()->json([
            'messages' => $agent->history(),
        ]);
    }

    public function clearHistory(Request $request): JsonResponse
    {
        $agent = agent('support')
            ->sessionId($request->user()->id)
            ->memory('Sqlite', [
                'path' => storage_path('conversations.db'),
            ]);

        $agent->forget();

        return response()->json([
            'success' => true,
            'message' => 'Conversation history cleared.',
        ]);
    }
}
```

Define routes in `routes/api.php`:

```php
use App\Http\Controllers\ChatController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/chat', [ChatController::class, 'respond']);
    Route::get('/chat/history', [ChatController::class, 'history']);
    Route::delete('/chat/history', [ChatController::class, 'clearHistory']);
});
```

### Queue Integration

Long-running agent tasks should be queued to avoid blocking HTTP requests. Laravel's queue system integrates seamlessly with Pagent.

Create a job:

```bash
php artisan make:job ProcessAgentTask
```

Implement the job:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use function Pagent\agent;

class ProcessAgentTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    public function __construct(
        private string $agentName,
        private string $task,
        private ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $agent = agent($this->agentName);

        if ($this->userId) {
            $agent->sessionId((string) $this->userId);
        }

        try {
            $response = $agent->prompt($this->task);

            // Store result in database, send notification, etc.
            $this->storeResult($response->content);
        } catch (\Exception $e) {
            // Log error and optionally retry
            logger()->error('Agent task failed', [
                'agent' => $this->agentName,
                'task' => $this->task,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Triggers retry
        }
    }

    private function storeResult(string $content): void
    {
        // Implementation depends on your application
        // Could store in database, cache, send email, etc.
    }

    public function failed(\Throwable $exception): void
    {
        // Handle job failure after all retries exhausted
        logger()->critical('Agent task failed permanently', [
            'agent' => $this->agentName,
            'task' => $this->task,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

Dispatch the job from a controller:

```php
use App\Jobs\ProcessAgentTask;

public function analyze(Request $request): JsonResponse
{
    $validated = $request->validate([
        'data' => 'required|string',
    ]);

    ProcessAgentTask::dispatch(
        'analyzer',
        "Analyze this data: {$validated['data']}",
        $request->user()->id
    );

    return response()->json([
        'success' => true,
        'message' => 'Analysis queued. You will be notified when complete.',
    ]);
}
```

### Middleware for Rate Limiting

Laravel middleware can protect agent endpoints from abuse:

```bash
php artisan make:middleware AgentRateLimitMiddleware
```

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AgentRateLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'agent:' . ($request->user()?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'error' => 'Too many requests. Please try again later.',
            ], 429);
        }

        RateLimiter::hit($key, 60); // 10 requests per minute

        return $next($request);
    }
}
```

Apply to routes:

```php
Route::middleware(['auth:sanctum', AgentRateLimitMiddleware::class])->group(function () {
    Route::post('/chat', [ChatController::class, 'respond']);
});
```

### Artisan Commands

Create CLI commands for agent interactions:

```bash
php artisan make:command AgentPromptCommand
```

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use function Pagent\agent;

class AgentPromptCommand extends Command
{
    protected $signature = 'agent:prompt {name} {prompt}';
    protected $description = 'Send a prompt to an agent';

    public function handle(): int
    {
        $agent = agent($this->argument('name'));

        $this->info("Sending prompt to agent '{$this->argument('name')}'...");

        try {
            $response = $agent->prompt($this->argument('prompt'));

            $this->info('Response:');
            $this->line($response->content);
            $this->newLine();
            $this->comment("Tokens: {$response->tokens}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to get response: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

Use from the command line:

```bash
php artisan agent:prompt support "What are your business hours?"
```

## Symfony Integration

Symfony's dependency injection container and event system provide powerful integration points for Pagent. Let's explore how to leverage Symfony's components effectively.

### Service Configuration

Define Pagent services in `config/services.yaml`:

```yaml
services:
  # Register the Registry as a singleton
  Pagent\Registry:
    public: true
    shared: true

  # Factory for creating agents
  pagent.agent.factory:
    class: Closure
    factory: ['App\Factory\AgentFactory', "create"]
    arguments:
      - '@Pagent\Registry'

  # Pre-configured agents
  pagent.agent.support:
    class: Pagent\Agent
    factory: ['@App\Factory\AgentFactory', "createSupport"]
    shared: true

  pagent.agent.analyzer:
    class: Pagent\Agent
    factory: ['@App\Factory\AgentFactory', "createAnalyzer"]
    shared: true
```

Create an agent factory:

```php
<?php

namespace App\Factory;

use Pagent\Agent;
use Pagent\Registry;

use function Pagent\agent;

class AgentFactory
{
    public function __construct(
        private Registry $registry,
        private string $anthropicKey,
        private string $openaiKey,
    ) {}

    public function createSupport(): Agent
    {
        return agent('support')
            ->provider('anthropic', ['api_key' => $this->anthropicKey])
            ->model('claude-sonnet-4-20250514')
            ->system('You are a helpful customer support assistant.')
            ->build();
    }

    public function createAnalyzer(): Agent
    {
        return agent('analyzer')
            ->provider('openai', ['api_key' => $this->openaiKey])
            ->model('gpt-4o')
            ->system('You analyze data and provide insights.')
            ->build();
    }

    public function create(
        string $name,
        string $provider,
        array $config = []
    ): Agent {
        return agent($name)
            ->provider($provider, $config)
            ->build();
    }
}
```

Register the factory in `services.yaml`:

```yaml
services:
  App\Factory\AgentFactory:
    arguments:
      $anthropicKey: "%env(ANTHROPIC_API_KEY)%"
      $openaiKey: "%env(OPENAI_API_KEY)%"
```

### Controller Integration

Inject agents directly into Symfony controllers:

```php
<?php

namespace App\Controller;

use Pagent\Agent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use function Pagent\agent;

class ChatController extends AbstractController
{
    public function __construct(
        private Agent $supportAgent,
    ) {}

    #[Route('/api/chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['message'])) {
            return $this->json(['error' => 'Message required'], 400);
        }

        $userId = $this->getUser()?->getId();

        $agent = $this->supportAgent
            ->sessionId((string) $userId);

        try {
            $response = $agent->prompt($data['message']);

            return $this->json([
                'success' => true,
                'reply' => $response->content,
                'tokens' => $response->tokens,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to process request',
            ], 500);
        }
    }

    #[Route('/api/chat/history', methods: ['GET'])]
    public function history(): JsonResponse
    {
        $userId = $this->getUser()?->getId();

        $agent = $this->supportAgent
            ->sessionId((string) $userId);

        return $this->json([
            'messages' => $agent->history(),
        ]);
    }
}
```

### Console Commands

Create Symfony console commands for agent interactions:

```php
<?php

namespace App\Command;

use Pagent\Agent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Pagent\agent;

#[AsCommand(
    name: 'agent:prompt',
    description: 'Send a prompt to an agent',
)]
class AgentPromptCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Agent name')
            ->addArgument('prompt', InputArgument::REQUIRED, 'Prompt to send');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $prompt = $input->getArgument('prompt');

        $agent = agent($name);

        $io->info("Sending prompt to agent '$name'...");

        try {
            $response = $agent->prompt($prompt);

            $io->section('Response');
            $io->writeln($response->content);
            $io->newLine();
            $io->comment("Tokens: {$response->tokens}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to get response: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

Run from the command line:

```bash
php bin/console agent:prompt support "What are your business hours?"
```

### Messenger Integration

Symfony Messenger can handle asynchronous agent tasks:

```php
<?php

namespace App\Message;

class ProcessAgentTask
{
    public function __construct(
        public readonly string $agentName,
        public readonly string $task,
        public readonly ?int $userId = null,
    ) {}
}
```

Create a message handler:

```php
<?php

namespace App\MessageHandler;

use App\Message\ProcessAgentTask;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function Pagent\agent;

#[AsMessageHandler]
class ProcessAgentTaskHandler
{
    public function __invoke(ProcessAgentTask $message): void
    {
        $agent = agent($message->agentName);

        if ($message->userId) {
            $agent->sessionId((string) $message->userId);
        }

        try {
            $response = $agent->prompt($message->task);

            // Store result, send notification, etc.
            $this->storeResult($response->content);
        } catch (\Exception $e) {
            // Log error
            throw $e; // Will be retried by Messenger
        }
    }

    private function storeResult(string $content): void
    {
        // Implementation depends on your application
    }
}
```

Dispatch messages from controllers:

```php
use App\Message\ProcessAgentTask;
use Symfony\Component\Messenger\MessageBusInterface;

class AnalysisController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {}

    #[Route('/api/analyze', methods: ['POST'])]
    public function analyze(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $this->messageBus->dispatch(new ProcessAgentTask(
            'analyzer',
            "Analyze this data: {$data['data']}",
            $this->getUser()?->getId()
        ));

        return $this->json([
            'success' => true,
            'message' => 'Analysis queued.',
        ]);
    }
}
```

Configure async transport in `config/packages/messenger.yaml`:

```yaml
framework:
  messenger:
    transports:
      async: "%env(MESSENGER_TRANSPORT_DSN)%"

    routing:
      App\Message\ProcessAgentTask: async
```

### Event Subscribers

Listen to agent events using Symfony's event system:

```php
<?php

namespace App\EventSubscriber;

use Pagent\Agent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AgentEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Define custom events as needed
        ];
    }

    // Note: Pagent doesn't have built-in events
    // This example shows how you could implement custom event tracking
    public function onAgentPrompt(): void
    {
        // Log, track metrics, etc.
    }
}
```

## Best Practices for Framework Integration

Regardless of which framework you're using, these patterns ensure clean, maintainable integrations:

**1. Centralize Agent Configuration**

Don't scatter agent creation throughout your codebase. Use service providers, factories, or configuration files to define agents in one place.

**2. Leverage Dependency Injection**

Inject pre-configured agents into controllers and services rather than creating them inline. This improves testability and consistency.

**3. Queue Long-Running Tasks**

Agent prompts can take seconds to complete. Queue them to avoid blocking HTTP requests and provide better user experience.

**4. Implement Rate Limiting**

Protect your API keys and budget by rate-limiting agent requests. Use framework middleware or custom guards.

**5. Handle Errors Gracefully**

Agent calls can fail due to network issues, API limits, or invalid prompts. Wrap calls in try-catch blocks and provide meaningful error messages.

**6. Secure API Keys**

Never commit API keys to version control. Use environment variables and framework configuration systems to manage credentials securely.

**7. Monitor and Log**

Track agent usage, response times, and errors. This helps identify issues and optimize costs.

**8. Test with Mock Providers**

Use Pagent's mock provider for testing. Don't make real API calls in your test suite.

```php
// In tests
use function Pagent\mock;

$agent = agent('test')
    ->provider(mock(['Hello from mock!']))
    ->build();

$response = $agent->prompt('Hello');
// Returns "Hello from mock!" without API calls
```

## What's Next

You've learned how to integrate Pagent with Laravel and Symfony, creating clean, maintainable architectures that leverage framework strengths while keeping agent logic portable.

In the next chapter, we'll explore custom middleware - building your own middleware to add rate limiting, caching, logging, and other cross-cutting concerns to your agent interactions.
