# Symfony Integration Guide

This guide shows how to integrate Pagent with [Symfony](https://symfony.com/) 6.x and 7.x, leveraging Symfony's service container, configuration system, and bundle architecture.

## Overview

We'll set up:

1. Symfony bundle for agent registration
2. YAML configuration for all agents
3. Service definitions and autowiring
4. Controllers using dependency injection
5. Console commands for testing
6. Event subscribers for logging

---

## Installation

```bash
# Install Pagent
composer require helgesverre/pagent

# Optional dependencies
composer require symfony/messenger  # For async processing
composer require symfony/cache      # For response caching
```

---

## Project Structure

```
your-symfony-app/
├── config/
│   ├── packages/
│   │   └── pagent.yaml        # Agent configuration
│   └── services.yaml
├── src/
│   ├── Agent/
│   │   ├── PagentBundle.php
│   │   └── DependencyInjection/
│   │       ├── Configuration.php
│   │       └── PagentExtension.php
│   ├── Controller/
│   │   ├── SupportController.php
│   │   └── ContentController.php
│   ├── Command/
│   │   └── AgentTestCommand.php
│   ├── EventSubscriber/
│   │   └── AgentLoggerSubscriber.php
│   └── Service/
│       ├── AgentRegistry.php
│       └── AgentFactory.php
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

**`config/packages/pagent.yaml`**:

```yaml
pagent:
  default_provider: "%env(AGENT_DEFAULT_PROVIDER)%"
  default_model: "%env(AGENT_DEFAULT_MODEL)%"
  timeout: "%env(int:AGENT_TIMEOUT)%"

  agents:
    support:
      provider: anthropic
      model: claude-3-haiku-20240307
      system: "You are a helpful customer support agent for our e-commerce platform. Be concise, professional, and helpful."
      temperature: 0.3
      tools:
        - search_orders
        - check_shipping
        - process_refund

    blog_writer:
      provider: openai
      model: gpt-4o-mini
      system: "You are a professional blog writer. Create engaging, SEO-optimized content with proper structure."
      temperature: 0.8

    code_reviewer:
      provider: anthropic
      model: claude-3-opus-20240229
      system: "You are a senior code reviewer. Provide constructive feedback on code quality, security, and best practices."
      temperature: 0.2

    data_analyst:
      provider: openai
      model: gpt-4
      system: "You are a data analyst. Analyze data, find insights, and create clear summaries."
      temperature: 0.3

    social_media:
      provider: openai
      model: gpt-4o-mini
      system: "Create engaging social media posts. Be concise, use emojis appropriately, include relevant hashtags."
      temperature: 0.9
```

---

## Step 3: Create Bundle Extension

**`src/Agent/DependencyInjection/Configuration.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Agent\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('pagent');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('default_provider')->defaultValue('anthropic')->end()
                ->scalarNode('default_model')->defaultValue('claude-3-haiku-20240307')->end()
                ->integerNode('timeout')->defaultValue(30)->end()
                ->arrayNode('agents')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('provider')->end()
                            ->scalarNode('model')->end()
                            ->scalarNode('system')->end()
                            ->floatNode('temperature')->defaultValue(0.5)->end()
                            ->arrayNode('tools')
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
```

**`src/Agent/DependencyInjection/PagentExtension.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Agent\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class PagentExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Store configuration as parameters
        $container->setParameter('pagent.config', $config);
        $container->setParameter('pagent.agents', $config['agents'] ?? []);
    }
}
```

---

## Step 4: Create Agent Factory Service

**`src/Service/AgentFactory.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Pagent\Agent;
use function Pagent\agent;

class AgentFactory
{
    private array $agents = [];

    public function __construct(
        private array $agentConfigs,
        private ToolRegistry $toolRegistry
    ) {}

    public function create(string $name): Agent
    {
        // Return cached instance if exists
        if (isset($this->agents[$name])) {
            return clone $this->agents[$name];
        }

        if (!isset($this->agentConfigs[$name])) {
            throw new \InvalidArgumentException("Agent '{$name}' is not configured");
        }

        $config = $this->agentConfigs[$name];

        $agent = agent($name)
            ->provider($config['provider'])
            ->model($config['model'])
            ->system($config['system'] ?? '')
            ->temperature($config['temperature'] ?? 0.5);

        // Register tools
        if (!empty($config['tools'])) {
            foreach ($config['tools'] as $toolName) {
                $tool = $this->toolRegistry->get($toolName);
                if ($tool) {
                    $agent->tool($toolName, $tool['description'], $tool['callable']);
                }
            }
        }

        // Cache the configured agent
        $this->agents[$name] = $agent;

        return clone $agent;
    }

    public function has(string $name): bool
    {
        return isset($this->agentConfigs[$name]);
    }

    public function all(): array
    {
        return array_keys($this->agentConfigs);
    }
}
```

**`src/Service/ToolRegistry.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Service;

class ToolRegistry
{
    private array $tools = [];

    public function __construct(
        private OrderService $orderService,
        private ShippingService $shippingService,
        private RefundService $refundService
    ) {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $this->register('search_orders', 'Search customer orders by email',
            fn(string $email) => $this->orderService->searchByEmail($email)
        );

        $this->register('check_shipping', 'Check shipping status by tracking number',
            fn(string $tracking) => $this->shippingService->checkStatus($tracking)
        );

        $this->register('process_refund', 'Process refund for an order',
            fn(string $orderId, string $reason) => $this->refundService->process($orderId, $reason)
        );
    }

    public function register(string $name, string $description, callable $callable): void
    {
        $this->tools[$name] = [
            'description' => $description,
            'callable' => $callable,
        ];
    }

    public function get(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }
}
```

---

## Step 5: Configure Services

**`config/services.yaml`**:

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true

  App\:
    resource: "../src/"
    exclude:
      - "../src/DependencyInjection/"
      - "../src/Kernel.php"

  # Agent Factory
  App\Service\AgentFactory:
    arguments:
      $agentConfigs: "%pagent.agents%"

  # Tool Registry
  App\Service\ToolRegistry:
    public: true

  # Make AgentFactory available via interface
  Pagent\Agent $supportAgent:
    factory: ['@App\Service\AgentFactory', "create"]
    arguments: ["support"]

  Pagent\Agent $blogWriterAgent:
    factory: ['@App\Service\AgentFactory', "create"]
    arguments: ["blog_writer"]

  Pagent\Agent $codeReviewerAgent:
    factory: ['@App\Service\AgentFactory', "create"]
    arguments: ["code_reviewer"]
```

---

## Step 6: Create Controllers

**`src/Controller/SupportController.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AgentFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/api/support')]
class SupportController extends AbstractController
{
    public function __construct(
        private AgentFactory $agentFactory,
        private CacheInterface $cache
    ) {}

    #[Route('/chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = $data['message'] ?? '';
        $sessionId = $data['session_id'] ?? null;

        if (empty($message)) {
            return $this->json(['error' => 'Message is required'], 400);
        }

        try {
            $agent = $this->agentFactory->create('support');

            // Load conversation history from cache
            if ($sessionId) {
                $history = $this->cache->get("agent_history_{$sessionId}", fn() => []);
                foreach ($history as $msg) {
                    $agent->messages[] = $msg;
                }
            }

            $response = $agent->prompt($message);

            // Save conversation history
            if ($sessionId) {
                $this->cache->delete("agent_history_{$sessionId}");
                $this->cache->get("agent_history_{$sessionId}", fn() => $agent->messages);
            } else {
                $sessionId = uniqid('support_');
                $this->cache->get("agent_history_{$sessionId}", fn() => $agent->messages);
            }

            return $this->json([
                'reply' => $response->content,
                'model' => $response->model,
                'tokens' => $response->tokens,
                'session_id' => $sessionId,
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Agent error', ['exception' => $e]);

            return $this->json([
                'error' => 'Failed to process request',
            ], 500);
        }
    }

    #[Route('/history/{sessionId}', methods: ['GET'])]
    public function history(string $sessionId): JsonResponse
    {
        $history = $this->cache->get("agent_history_{$sessionId}", fn() => []);

        return $this->json([
            'session_id' => $sessionId,
            'messages' => $history,
            'count' => count($history),
        ]);
    }

    #[Route('/reset/{sessionId}', methods: ['POST'])]
    public function reset(string $sessionId): JsonResponse
    {
        $this->cache->delete("agent_history_{$sessionId}");

        return $this->json([
            'message' => 'Conversation reset successfully',
        ]);
    }
}
```

**`src/Controller/ContentController.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AgentFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/content')]
class ContentController extends AbstractController
{
    public function __construct(
        private AgentFactory $agentFactory,
        private ValidatorInterface $validator
    ) {}

    #[Route('/blog', methods: ['POST'])]
    public function generateBlog(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $constraints = new Assert\Collection([
            'topic' => [new Assert\NotBlank(), new Assert\Length(['max' => 200])],
            'word_count' => [new Assert\Type('integer'), new Assert\Range(['min' => 100, 'max' => 2000])],
        ]);

        $violations = $this->validator->validate($data, $constraints);

        if (count($violations) > 0) {
            return $this->json(['errors' => (string) $violations], 400);
        }

        $topic = $data['topic'];
        $wordCount = $data['word_count'] ?? 500;

        $agent = $this->agentFactory->create('blog_writer');
        $response = $agent->prompt("Write a {$wordCount}-word blog post about: {$topic}");

        return $this->json([
            'article' => $response->content,
            'tokens_used' => $response->tokens,
        ]);
    }

    #[Route('/social', methods: ['POST'])]
    public function generateSocialPost(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $topic = $data['topic'] ?? '';
        $platform = $data['platform'] ?? 'twitter';

        if (empty($topic)) {
            return $this->json(['error' => 'Topic is required'], 400);
        }

        $agent = $this->agentFactory->create('social_media');
        $response = $agent->prompt("Create a {$platform} post about: {$topic}");

        return $this->json([
            'post' => $response->content,
            'tokens_used' => $response->tokens,
        ]);
    }
}
```

---

## Step 7: Create Console Command

**`src/Command/AgentTestCommand.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AgentFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'agent:test',
    description: 'Test an agent with a prompt'
)]
class AgentTestCommand extends Command
{
    public function __construct(
        private AgentFactory $agentFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('agent', InputArgument::REQUIRED, 'Agent name')
            ->addArgument('prompt', InputArgument::REQUIRED, 'Prompt to send');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $agentName = $input->getArgument('agent');
        $prompt = $input->getArgument('prompt');

        if (!$this->agentFactory->has($agentName)) {
            $io->error("Agent '{$agentName}' is not configured");
            $io->listing($this->agentFactory->all());
            return Command::FAILURE;
        }

        $io->title("Testing Agent: {$agentName}");
        $io->text("Prompt: {$prompt}");
        $io->newLine();

        try {
            $agent = $this->agentFactory->create($agentName);
            $response = $agent->prompt($prompt);

            $io->section('Response');
            $io->text($response->content);
            $io->newLine();

            $io->table(
                ['Property', 'Value'],
                [
                    ['Model', $response->model],
                    ['Tokens', $response->tokens],
                    ['Stop Reason', $response->stopReason ?? 'N/A'],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
```

**Usage**:

```bash
php bin/console agent:test support "I need help with my order"
php bin/console agent:test blog_writer "Write about Symfony 7"
```

---

## Step 8: Create Event Subscriber

**`src/EventSubscriber/AgentLoggerSubscriber.php`**:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class AgentLoggerSubscriber implements EventSubscriberInterface
{
    private ?float $startTime = null;

    public function __construct(
        private LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (str_starts_with($request->getPathInfo(), '/api/')) {
            $this->startTime = microtime(true);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($this->startTime === null) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $duration = microtime(true) - $this->startTime;

        $this->logger->info('Agent API request', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration' => round($duration, 3),
        ]);

        $this->startTime = null;
    }
}
```

---

## Step 9: Async Processing with Messenger (Optional)

**`src/Message/AgentPromptMessage.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Message;

class AgentPromptMessage
{
    public function __construct(
        private string $agentName,
        private string $prompt,
        private ?string $userId = null
    ) {}

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }
}
```

**`src/MessageHandler/AgentPromptMessageHandler.php`**:

```php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AgentPromptMessage;
use App\Service\AgentFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class AgentPromptMessageHandler
{
    public function __construct(
        private AgentFactory $agentFactory,
        private LoggerInterface $logger
    ) {}

    public function __invoke(AgentPromptMessage $message): void
    {
        $this->logger->info('Processing agent prompt', [
            'agent' => $message->getAgentName(),
            'user_id' => $message->getUserId(),
        ]);

        try {
            $agent = $this->agentFactory->create($message->getAgentName());
            $response = $agent->prompt($message->getPrompt());

            // Store result or dispatch event
            $this->logger->info('Agent response received', [
                'agent' => $message->getAgentName(),
                'response_length' => strlen($response->content),
                'tokens' => $response->tokens,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Agent processing failed', [
                'agent' => $message->getAgentName(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
```

**Usage**:

```php
use App\Message\AgentPromptMessage;
use Symfony\Component\Messenger\MessageBusInterface;

$this->messageBus->dispatch(
    new AgentPromptMessage('blog_writer', 'Write about Symfony', $userId)
);
```

---

## Usage Examples

### Start Development Server

```bash
symfony server:start
```

### Test Endpoints

```bash
# Support chat
curl -X POST http://localhost:8000/api/support/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "I need help"}'

# Generate blog
curl -X POST http://localhost:8000/api/content/blog \
  -H "Content-Type: application/json" \
  -d '{"topic": "Symfony 7", "word_count": 300}'
```

### Use in Services

```php
namespace App\Service;

use App\Service\AgentFactory;

class MyService
{
    public function __construct(
        private AgentFactory $agentFactory
    ) {}

    public function analyzeData(array $data): string
    {
        $agent = $this->agentFactory->create('data_analyst');
        $response = $agent->prompt('Analyze: ' . json_encode($data));

        return $response->content;
    }
}
```

---

## Testing

**`tests/Service/AgentFactoryTest.php`**:

```php
<?php

namespace App\Tests\Service;

use App\Service\AgentFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AgentFactoryTest extends KernelTestCase
{
    private AgentFactory $agentFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->agentFactory = static::getContainer()->get(AgentFactory::class);
    }

    public function testCreateSupportAgent(): void
    {
        $agent = $this->agentFactory->create('support');
        $this->assertNotNull($agent);
    }

    public function testHasAgent(): void
    {
        $this->assertTrue($this->agentFactory->has('support'));
        $this->assertFalse($this->agentFactory->has('nonexistent'));
    }

    public function testAllAgents(): void
    {
        $agents = $this->agentFactory->all();
        $this->assertContains('support', $agents);
        $this->assertContains('blog_writer', $agents);
    }
}
```

---

## Best Practices

### 1. Use Dependency Injection

```php
public function __construct(
    private AgentFactory $agentFactory
) {}
```

### 2. Cache Responses

```php
use Symfony\Contracts\Cache\CacheInterface;

$response = $this->cache->get(
    "agent_response_{$cacheKey}",
    fn() => $this->agentFactory->create('blog_writer')->prompt($topic)
);
```

### 3. Environment-Specific Config

```yaml
# config/packages/dev/pagent.yaml
pagent:
  agents:
    support:
      model: claude-3-haiku-20240307  # Cheaper model for dev

# config/packages/prod/pagent.yaml
pagent:
  agents:
    support:
      model: claude-3-opus-20240229  # Better model for prod
```

---

## Summary

This Symfony integration provides:

- ✅ **Bundle Architecture** - Clean, reusable bundle pattern
- ✅ **Service Container** - Full DI support
- ✅ **Configuration System** - YAML-based agent config
- ✅ **Console Commands** - CLI testing and management
- ✅ **Messenger Integration** - Async processing
- ✅ **Event System** - Track and log interactions
- ✅ **Validation** - Built-in input validation
- ✅ **Symfony Conventions** - Follows Symfony best practices

Your Symfony app now has AI superpowers! 🚀
