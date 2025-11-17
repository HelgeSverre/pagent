# Chapter 24: Laravel and Symfony Integration

## What You'll Learn

By the end of this chapter, you'll be able to:
- Set up Pagent as a Laravel service provider with proper dependency injection
- Create Symfony console commands that leverage AI capabilities
- Build queue workers for asynchronous AI processing
- Implement RESTful API endpoints for agent interactions
- Configure middleware for rate limiting and authentication

## Prerequisites

- Completed Chapters 1-15 of the Pagent tutorial
- Basic understanding of Laravel's service container
- Familiarity with Symfony's console component
- Working knowledge of PSR-4 autoloading
- Composer-based PHP project setup

## Time Estimate

45-60 minutes for complete implementation and testing

## Final Result

You'll have a fully integrated AI-powered application with:
- Laravel service provider for Pagent configuration
- Queue jobs for background AI processing
- RESTful API for chat interactions
- Symfony console commands for batch operations
- Proper error handling and logging

---

## Part 1: Laravel Integration Fundamentals

### Setting Up the Service Provider

Laravel's service container provides an elegant way to configure and inject Pagent throughout your application. Let's start by creating a comprehensive service provider.

```php
<?php
// app/Providers/PagentServiceProvider.php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Pagent\Agent;
use Pagent\AgentBuilder;
use Pagent\Providers\Anthropic;
use Pagent\Providers\OpenAI;
use Pagent\Registry;

final class PagentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/pagent.php',
            'pagent'
        );

        // Register the registry as a singleton
        $this->app->singleton(Registry::class, function ($app) {
            $registry = new Registry();

            // Register providers based on configuration
            if ($apiKey = config('pagent.providers.anthropic.api_key')) {
                $registry->register(
                    'anthropic',
                    new Anthropic($apiKey)
                );
            }

            if ($apiKey = config('pagent.providers.openai.api_key')) {
                $registry->register(
                    'openai',
                    new OpenAI($apiKey)
                );
            }

            return $registry;
        });

        // Register agent builder
        $this->app->bind(AgentBuilder::class, function ($app) {
            $builder = new AgentBuilder();

            // Apply default configuration
            $builder->withProvider(config('pagent.default_provider', 'anthropic'))
                    ->withModel(config('pagent.default_model'))
                    ->withMaxTokens(config('pagent.max_tokens', 1024));

            if ($systemPrompt = config('pagent.system_prompt')) {
                $builder->withSystemPrompt($systemPrompt);
            }

            return $builder;
        });

        // Register a factory for creating agents
        $this->app->bind('pagent.agent', function ($app) {
            return $app->make(AgentBuilder::class)->build();
        });
    }

    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../../config/pagent.php' => config_path('pagent.php'),
        ], 'pagent-config');

        // Register console commands if running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\ChatCommand::class,
                \App\Console\Commands\AnalyzeCodeCommand::class,
            ]);
        }
    }
}
```

### Configuration File

Create a comprehensive configuration file that supports multiple providers and environments:

```php
<?php
// config/pagent.php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI provider that will be used
    | when creating new agents without specifying a provider.
    |
    */
    'default_provider' => env('PAGENT_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | The default model to use for the selected provider.
    |
    */
    'default_model' => env('PAGENT_MODEL', 'claude-3-sonnet-20240229'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure the API keys and settings for each provider.
    |
    */
    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'timeout' => env('ANTHROPIC_TIMEOUT', 30),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'timeout' => env('OPENAI_TIMEOUT', 30),
        ],

        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'timeout' => env('OLLAMA_TIMEOUT', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for all agents created through the service provider.
    |
    */
    'max_tokens' => env('PAGENT_MAX_TOKENS', 1024),
    'temperature' => env('PAGENT_TEMPERATURE', 0.7),
    'system_prompt' => env('PAGENT_SYSTEM_PROMPT'),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for queue-based AI processing.
    |
    */
    'queue' => [
        'connection' => env('PAGENT_QUEUE_CONNECTION', 'redis'),
        'queue' => env('PAGENT_QUEUE_NAME', 'ai-processing'),
        'retry_after' => 90,
        'max_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for AI requests.
    |
    */
    'rate_limit' => [
        'enabled' => env('PAGENT_RATE_LIMIT', true),
        'requests_per_minute' => env('PAGENT_RATE_LIMIT_RPM', 60),
        'requests_per_day' => env('PAGENT_RATE_LIMIT_RPD', 1000),
    ],
];
```

---

## Part 2: Building a Chat Application

### The Chat Controller

Let's create a RESTful controller that handles chat interactions:

```php
<?php
// app/Http/Controllers/ChatController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ChatRequest;
use App\Jobs\ProcessChatMessage;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pagent\AgentBuilder;

final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly AgentBuilder $agentBuilder
    ) {}

    /**
     * Handle a synchronous chat message.
     */
    public function chat(ChatRequest $request): JsonResponse
    {
        try {
            // Get or create conversation
            $conversation = $this->getOrCreateConversation($request);

            // Build agent with conversation context
            $agent = $this->buildAgentWithContext($conversation);

            // Process the message
            $response = $agent->prompt($request->input('message'));

            // Store the interaction
            $conversation->messages()->createMany([
                ['role' => 'user', 'content' => $request->input('message')],
                ['role' => 'assistant', 'content' => $response],
            ]);

            return response()->json([
                'success' => true,
                'response' => $response,
                'conversation_id' => $conversation->id,
                'message_count' => $conversation->messages()->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Chat processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process chat message',
            ], 500);
        }
    }

    /**
     * Queue a chat message for asynchronous processing.
     */
    public function queueChat(ChatRequest $request): JsonResponse
    {
        $conversation = $this->getOrCreateConversation($request);

        // Dispatch job to queue
        $job = ProcessChatMessage::dispatch(
            $conversation->id,
            $request->input('message'),
            $request->input('options', [])
        )->onQueue(config('pagent.queue.queue'));

        return response()->json([
            'success' => true,
            'message' => 'Message queued for processing',
            'conversation_id' => $conversation->id,
            'job_id' => $job->getJobId(),
        ], 202);
    }

    /**
     * Stream a chat response using Server-Sent Events.
     */
    public function streamChat(ChatRequest $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->stream(function () use ($request) {
            $conversation = $this->getOrCreateConversation($request);
            $agent = $this->buildAgentWithContext($conversation);

            // Enable streaming mode
            $agent = $agent->withStreaming(true);

            echo "data: " . json_encode(['type' => 'start']) . "\n\n";
            ob_flush();
            flush();

            $fullResponse = '';

            // Stream the response
            $agent->prompt($request->input('message'), function ($chunk) use (&$fullResponse) {
                $fullResponse .= $chunk;
                echo "data: " . json_encode([
                    'type' => 'chunk',
                    'content' => $chunk,
                ]) . "\n\n";
                ob_flush();
                flush();
            });

            // Store the complete interaction
            $conversation->messages()->createMany([
                ['role' => 'user', 'content' => $request->input('message')],
                ['role' => 'assistant', 'content' => $fullResponse],
            ]);

            echo "data: " . json_encode(['type' => 'end']) . "\n\n";
            ob_flush();
            flush();

        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function getOrCreateConversation(ChatRequest $request): Conversation
    {
        if ($id = $request->input('conversation_id')) {
            return Conversation::findOrFail($id);
        }

        return Conversation::create([
            'user_id' => $request->user()->id,
            'title' => $request->input('title', 'New Conversation'),
            'metadata' => $request->input('metadata', []),
        ]);
    }

    private function buildAgentWithContext(Conversation $conversation): Agent
    {
        $agent = $this->agentBuilder->build();

        // Load conversation history into agent context
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->limit(10) // Keep last 10 messages for context
            ->get();

        foreach ($messages as $message) {
            if ($message->role === 'user') {
                $agent->addUserMessage($message->content);
            } else {
                $agent->addAssistantMessage($message->content);
            }
        }

        return $agent;
    }
}
```

### The Queue Job

Implement asynchronous processing with proper error handling:

```php
<?php
// app/Jobs/ProcessChatMessage.php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Conversation;
use App\Notifications\ChatResponseReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Pagent\AgentBuilder;

final class ProcessChatMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly int $conversationId,
        private readonly string $message,
        private readonly array $options = []
    ) {}

    public function handle(AgentBuilder $agentBuilder): void
    {
        try {
            $conversation = Conversation::findOrFail($this->conversationId);

            // Build agent with options
            $agent = $agentBuilder
                ->withMaxTokens($this->options['max_tokens'] ?? 1024)
                ->withTemperature($this->options['temperature'] ?? 0.7)
                ->build();

            // Load conversation context
            $this->loadConversationContext($agent, $conversation);

            // Process the message
            $response = $agent->prompt($this->message);

            // Store the interaction
            $conversation->messages()->createMany([
                ['role' => 'user', 'content' => $this->message],
                ['role' => 'assistant', 'content' => $response],
            ]);

            // Notify user if configured
            if ($conversation->user->notify_on_response) {
                $conversation->user->notify(
                    new ChatResponseReady($conversation, $response)
                );
            }

            Log::info('Chat message processed successfully', [
                'conversation_id' => $this->conversationId,
                'response_length' => strlen($response),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process chat message', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                // Mark conversation as failed after max attempts
                $conversation = Conversation::find($this->conversationId);
                if ($conversation) {
                    $conversation->update(['status' => 'failed']);
                }
            }

            throw $e; // Re-throw to trigger retry
        }
    }

    private function loadConversationContext($agent, Conversation $conversation): void
    {
        $messages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        foreach ($messages as $message) {
            if ($message->role === 'user') {
                $agent->addUserMessage($message->content);
            } else {
                $agent->addAssistantMessage($message->content);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Chat job permanently failed', [
            'conversation_id' => $this->conversationId,
            'message' => $this->message,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

## Part 3: Symfony Console Commands

### Batch Processing Command

Create a Symfony console command for batch text processing:

```php
<?php
// src/Command/BatchAnalyzeCommand.php

declare(strict_types=1);

namespace App\Command;

use Pagent\AgentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:batch-analyze',
    description: 'Analyze multiple files using AI',
)]
final class BatchAnalyzeCommand extends Command
{
    public function __construct(
        private readonly AgentBuilder $agentBuilder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'directory',
                InputArgument::REQUIRED,
                'Directory containing files to analyze'
            )
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'File pattern to match (e.g., *.txt)',
                '*.txt'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for results'
            )
            ->addOption(
                'prompt',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom analysis prompt',
                'Analyze this text and provide a summary'
            )
            ->addOption(
                'parallel',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of parallel workers',
                '1'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('directory');
        $pattern = $input->getOption('pattern');
        $outputDir = $input->getOption('output') ?? $directory . '/analysis';
        $prompt = $input->getOption('prompt');

        // Find files matching pattern
        $files = $this->findFiles($directory, $pattern);

        if (empty($files)) {
            $io->warning('No files found matching pattern: ' . $pattern);
            return Command::FAILURE;
        }

        $io->info(sprintf('Found %d files to analyze', count($files)));

        // Create output directory
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        // Create progress bar
        $progressBar = new ProgressBar($output, count($files));
        $progressBar->start();

        $agent = $this->agentBuilder
            ->withSystemPrompt('You are a document analyzer. Provide clear, concise analysis.')
            ->build();

        $results = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $content = file_get_contents($file);
                $analysis = $agent->prompt(
                    $prompt . "\n\nContent:\n" . $content
                );

                // Save analysis to file
                $outputFile = $outputDir . '/' . basename($file, '.txt') . '_analysis.md';
                file_put_contents($outputFile, $this->formatAnalysis($file, $analysis));

                $results[] = [
                    'file' => $file,
                    'output' => $outputFile,
                    'tokens' => strlen($content),
                ];

            } catch (\Exception $e) {
                $errors[] = [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        // Display results
        $this->displayResults($io, $results, $errors);

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }

    private function findFiles(string $directory, string $pattern): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function formatAnalysis(string $filename, string $analysis): string
    {
        return sprintf(
            "# Analysis Report\n\n" .
            "**File:** %s\n" .
            "**Date:** %s\n\n" .
            "## Analysis\n\n%s\n",
            basename($filename),
            date('Y-m-d H:i:s'),
            $analysis
        );
    }

    private function displayResults(SymfonyStyle $io, array $results, array $errors): void
    {
        if (!empty($results)) {
            $io->success(sprintf('Successfully analyzed %d files', count($results)));

            $tableData = array_map(function ($result) {
                return [
                    basename($result['file']),
                    basename($result['output']),
                    $result['tokens'] . ' chars',
                ];
            }, $results);

            $io->table(
                ['File', 'Output', 'Size'],
                $tableData
            );
        }

        if (!empty($errors)) {
            $io->error(sprintf('Failed to analyze %d files', count($errors)));

            foreach ($errors as $error) {
                $io->text(sprintf(
                    '  - %s: %s',
                    basename($error['file']),
                    $error['error']
                ));
            }
        }
    }
}
```

### Interactive Chat Command

Build an interactive console chat interface:

```php
<?php
// src/Command/InteractiveChatCommand.php

declare(strict_types=1);

namespace App\Command;

use Pagent\Agent;
use Pagent\AgentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:chat',
    description: 'Start an interactive AI chat session',
)]
final class InteractiveChatCommand extends Command
{
    private Agent $agent;
    private array $history = [];

    public function __construct(
        private readonly AgentBuilder $agentBuilder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'model',
                'm',
                InputOption::VALUE_REQUIRED,
                'AI model to use'
            )
            ->addOption(
                'system',
                's',
                InputOption::VALUE_REQUIRED,
                'System prompt for the agent'
            )
            ->addOption(
                'export',
                'e',
                InputOption::VALUE_REQUIRED,
                'Export conversation to file'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Display welcome message
        $io->title('Pagent Interactive Chat');
        $io->text('Type your message and press Enter. Type "exit" to quit.');
        $io->newLine();

        // Configure agent
        $builder = $this->agentBuilder;

        if ($model = $input->getOption('model')) {
            $builder->withModel($model);
        }

        if ($systemPrompt = $input->getOption('system')) {
            $builder->withSystemPrompt($systemPrompt);
        }

        $this->agent = $builder->build();

        // Start chat loop
        $helper = $this->getHelper('question');

        while (true) {
            $question = new Question('<info>You:</info> ');
            $message = $helper->ask($input, $output, $question);

            if (in_array(strtolower($message), ['exit', 'quit', 'bye'])) {
                break;
            }

            if (empty(trim($message))) {
                continue;
            }

            // Special commands
            if ($this->handleCommand($message, $io)) {
                continue;
            }

            // Process with AI
            $io->write('<comment>AI:</comment> ');

            try {
                $response = $this->agent->prompt($message);
                $io->writeln($response);

                // Store in history
                $this->history[] = [
                    'role' => 'user',
                    'content' => $message,
                    'timestamp' => time(),
                ];
                $this->history[] = [
                    'role' => 'assistant',
                    'content' => $response,
                    'timestamp' => time(),
                ];

            } catch (\Exception $e) {
                $io->error('Failed to get response: ' . $e->getMessage());
            }

            $io->newLine();
        }

        // Export conversation if requested
        if ($exportFile = $input->getOption('export')) {
            $this->exportConversation($exportFile, $io);
        }

        $io->success('Chat session ended. Goodbye!');

        return Command::SUCCESS;
    }

    private function handleCommand(string $message, SymfonyStyle $io): bool
    {
        if (!str_starts_with($message, '/')) {
            return false;
        }

        $parts = explode(' ', $message, 2);
        $command = $parts[0];

        switch ($command) {
            case '/help':
                $io->listing([
                    '/help - Show this help message',
                    '/clear - Clear conversation history',
                    '/stats - Show conversation statistics',
                    '/export <file> - Export conversation to file',
                    '/system <prompt> - Change system prompt',
                ]);
                return true;

            case '/clear':
                $this->agent = $this->agentBuilder->build();
                $this->history = [];
                $io->success('Conversation history cleared');
                return true;

            case '/stats':
                $io->table(
                    ['Metric', 'Value'],
                    [
                        ['Messages', count($this->history)],
                        ['User messages', count(array_filter($this->history, fn($m) => $m['role'] === 'user'))],
                        ['AI responses', count(array_filter($this->history, fn($m) => $m['role'] === 'assistant'))],
                        ['Session duration', $this->getSessionDuration()],
                    ]
                );
                return true;

            case '/export':
                $file = $parts[1] ?? 'conversation.json';
                $this->exportConversation($file, $io);
                return true;

            case '/system':
                if (isset($parts[1])) {
                    $this->agent = $this->agentBuilder
                        ->withSystemPrompt($parts[1])
                        ->build();
                    $io->success('System prompt updated');
                }
                return true;

            default:
                $io->warning('Unknown command: ' . $command);
                return true;
        }
    }

    private function exportConversation(string $filename, SymfonyStyle $io): void
    {
        $export = [
            'session_start' => $this->history[0]['timestamp'] ?? time(),
            'session_end' => time(),
            'messages' => $this->history,
        ];

        file_put_contents($filename, json_encode($export, JSON_PRETTY_PRINT));
        $io->success('Conversation exported to: ' . $filename);
    }

    private function getSessionDuration(): string
    {
        if (empty($this->history)) {
            return '0 minutes';
        }

        $start = $this->history[0]['timestamp'];
        $duration = time() - $start;

        if ($duration < 60) {
            return $duration . ' seconds';
        } elseif ($duration < 3600) {
            return round($duration / 60) . ' minutes';
        } else {
            return round($duration / 3600, 1) . ' hours';
        }
    }
}
```

---

## Part 4: Middleware and Rate Limiting

### Custom Middleware for AI Requests

Protect your AI endpoints with intelligent rate limiting:

```php
<?php
// app/Http/Middleware/PagentRateLimiter.php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class PagentRateLimiter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('pagent.rate_limit.enabled')) {
            return $next($request);
        }

        $key = $this->resolveRequestKey($request);

        // Check per-minute limit
        $minuteKey = $key . ':minute';
        $minuteLimit = config('pagent.rate_limit.requests_per_minute', 60);

        if (!RateLimiter::attempt($minuteKey, $minuteLimit, function() {}, 60)) {
            return $this->tooManyRequestsResponse($request, $minuteKey);
        }

        // Check per-day limit
        $dayKey = $key . ':day';
        $dayLimit = config('pagent.rate_limit.requests_per_day', 1000);

        if (!RateLimiter::attempt($dayKey, $dayLimit, function() {}, 86400)) {
            return $this->tooManyRequestsResponse($request, $dayKey);
        }

        $response = $next($request);

        // Add rate limit headers
        $response->headers->add([
            'X-RateLimit-Limit-Minute' => $minuteLimit,
            'X-RateLimit-Remaining-Minute' => RateLimiter::remaining($minuteKey, $minuteLimit),
            'X-RateLimit-Limit-Day' => $dayLimit,
            'X-RateLimit-Remaining-Day' => RateLimiter::remaining($dayKey, $dayLimit),
        ]);

        return $response;
    }

    private function resolveRequestKey(Request $request): string
    {
        if ($user = $request->user()) {
            return 'pagent:user:' . $user->id;
        }

        return 'pagent:ip:' . $request->ip();
    }

    private function tooManyRequestsResponse(Request $request, string $key): Response
    {
        $seconds = RateLimiter::availableIn($key);

        return response()->json([
            'error' => 'Too many requests',
            'retry_after' => $seconds,
        ], 429)->withHeaders([
            'Retry-After' => $seconds,
            'X-RateLimit-Reset' => time() + $seconds,
        ]);
    }
}
```

---

## Summary

You've successfully learned how to integrate Pagent with Laravel and Symfony frameworks. Key accomplishments include:

- **Service Provider Setup**: Created a comprehensive Laravel service provider with dependency injection
- **Queue Integration**: Built asynchronous AI processing with Laravel queues
- **RESTful API**: Implemented chat endpoints with streaming support
- **Symfony Commands**: Developed batch processing and interactive chat commands
- **Middleware Protection**: Added rate limiting and authentication for AI endpoints

## Next Steps

1. **Add WebSocket Support**: Implement real-time chat with Laravel Echo
2. **Create Admin Dashboard**: Build monitoring interface for AI usage
3. **Implement Caching**: Add Redis caching for repeated queries
4. **Add Multi-tenancy**: Support multiple organizations with separate quotas
5. **Create API Documentation**: Generate OpenAPI specs for your endpoints

## Troubleshooting Guide

### Common Integration Issues

**Provider Not Found**
```bash
# Register service provider in config/app.php
'providers' => [
    // ...
    App\Providers\PagentServiceProvider::class,
],
```

**Queue Jobs Failing**
```bash
# Check queue worker is running
php artisan queue:work --queue=ai-processing

# Monitor failed jobs
php artisan queue:failed
```

**Rate Limiting Not Working**
```bash
# Clear rate limiter cache
php artisan cache:clear

# Verify middleware registration
php artisan route:list
```

**Symfony Command Not Found**
```yaml
# Register in services.yaml
services:
    App\Command\:
        resource: '../src/Command/'
        tags: ['console.command']
```

## Additional Resources

- [Laravel Service Container Documentation](https://laravel.com/docs/container)
- [Symfony Console Component](https://symfony.com/doc/current/console.html)
- [Queue Workers Best Practices](https://laravel.com/docs/queues#supervisor-configuration)
- [API Rate Limiting Strategies](https://laravel.com/docs/rate-limiting)

You're now ready to build production-ready AI applications with Pagent in your favorite PHP framework!