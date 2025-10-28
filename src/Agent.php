<?php

declare(strict_types=1);

namespace Pagent;

use Closure;
use Pagent\Contracts\Guard;
use Pagent\Contracts\Memory;
use Pagent\Contracts\Middleware;
use Pagent\Contracts\Provider;
use Pagent\Contracts\ToolInterface;
use Pagent\Exceptions\GuardException;
use Pagent\Memory\ContextManager;
use Pagent\Streaming\StreamResponse;
use Pagent\Tool\Tool;
use RuntimeException;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function asort;
use function class_exists;
use function count;
use function date;
use function get_class;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function levenshtein;
use function sprintf;
use function str_contains;
use function ucfirst;

final class Agent
{
    public array $messages = [];

    private array $config = [];

    private ?Provider $provider = null;

    /** @var ToolInterface[] */
    private array $tools = [];

    /** @var Guard[] */
    private array $guards = [];

    /** @var Middleware[] */
    private array $middleware = [];

    private ?Closure $fallback = null;

    private ?Memory $memory = null;

    private ?string $sessionId = null;

    private ?ContextManager $contextManager = null;

    public function __construct(
        private readonly string $name,
    ) {}

    public function provider(Provider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function config(array $config): self
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    public function system(string $prompt): self
    {
        $this->config['system'] = $prompt;

        return $this;
    }

    public function model(string $model): self
    {
        $this->config['model'] = $model;

        return $this;
    }

    public function temperature(float $temperature): self
    {
        $this->config['temperature'] = $temperature;

        return $this;
    }

    public function maxTokens(int $maxTokens): self
    {
        $this->config['max_tokens'] = $maxTokens;

        return $this;
    }

    public function memory(string|Memory $adapter, array $config = []): self
    {
        if ($adapter instanceof Memory) {
            $this->memory = $adapter;

            return $this;
        }

        // Resolve string to adapter class
        $adapterClass = "Pagent\\Memory\\Adapters\\{$adapter}Adapter";

        if (! class_exists($adapterClass)) {
            throw new RuntimeException("Memory adapter class '{$adapterClass}' not found");
        }

        $this->memory = new $adapterClass($config);

        return $this;
    }

    public function sessionId(string $id): self
    {
        $this->sessionId = $id;

        return $this;
    }

    public function contextWindow(int $maxTokens, string $strategy = 'oldest'): self
    {
        $this->contextManager = new ContextManager($maxTokens, $strategy);

        return $this;
    }

    public function prompt(string $message, array $options = []): object
    {
        if (! $this->provider) {
            throw new RuntimeException("No provider set for agent '{$this->name}'");
        }

        // Auto-load from memory if configured and messages are empty
        if ($this->memory && $this->sessionId && empty($this->messages)) {
            $this->messages = $this->memory->load($this->sessionId);
        }

        // Add to message history
        $this->messages[] = ['role' => 'user', 'content' => $message];

        // Merge agent config with prompt options
        $mergedOptions = array_merge($this->config, $options);

        // Apply context pruning if configured
        $messagesToSend = $this->messages;
        if ($this->contextManager) {
            $messagesToSend = $this->contextManager->prune($this->messages);
        }

        // If we have message history, pass it along
        if (! empty($messagesToSend)) {
            $mergedOptions['messages'] = $messagesToSend;
        }

        // Add tool schemas if we have tools
        if (! empty($this->tools)) {
            $mergedOptions['tools'] = $this->getToolSchemas();
        }

        // Run before middleware
        foreach ($this->middleware as $mw) {
            $mergedOptions = $mw->before($message, $mergedOptions);
        }

        // Call provider
        $response = $this->provider->prompt($message, $mergedOptions);

        // Run after middleware
        foreach ($this->middleware as $mw) {
            $response = $mw->after($response);
        }

        // Handle tool calls automatically
        while (! empty($response->tool_calls)) {
            $response = $this->handleToolCalls($response);
        }

        // Run guards on the response
        if (! empty($this->guards)) {
            try {
                $this->runGuards($message, $response->content ?? '');
            } catch (GuardException $e) {
                if ($this->fallback) {
                    $fallbackContent = ($this->fallback)($e);

                    return (object) [
                        'content' => $fallbackContent,
                        'model' => $response->model ?? 'fallback',
                        'tokens' => 0,
                        'provider' => $response->provider ?? 'fallback',
                        'guard_triggered' => $e->guardName,
                    ];
                }

                throw $e;
            }
        }

        // Add final response to history
        if (isset($response->content) && ! empty($response->content)) {
            $this->messages[] = ['role' => 'assistant', 'content' => $response->content];
        }

        // Auto-save to memory if configured
        if ($this->memory && $this->sessionId) {
            $this->memory->save($this->sessionId, $this->messages);
        }

        return $response;
    }

    /**
     * Stream a prompt to the LLM and get a streaming response
     */
    public function stream(string $message, array $options = []): StreamResponse
    {
        if (! $this->provider) {
            throw new RuntimeException("No provider set for agent '{$this->name}'");
        }

        // Check if provider supports streaming
        if (! method_exists($this->provider, 'streamPrompt')) {
            throw new RuntimeException(
                'Provider '.get_class($this->provider).' does not support streaming. '.
                'Use the prompt() method instead.'
            );
        }

        // Add to message history
        $this->messages[] = ['role' => 'user', 'content' => $message];

        // Merge agent config with prompt options
        $mergedOptions = array_merge($this->config, $options);

        // If we have message history, pass it along
        if (! empty($this->messages)) {
            $mergedOptions['messages'] = $this->messages;
        }

        // Add tool schemas if we have tools
        if (! empty($this->tools)) {
            $mergedOptions['tools'] = $this->getToolSchemas();
        }

        // Run before middleware
        foreach ($this->middleware as $mw) {
            $mergedOptions = $mw->before($message, $mergedOptions);
        }

        // Call provider's streaming method
        $streamResponse = $this->provider->streamPrompt($message, $mergedOptions);

        return $streamResponse;
    }

    /**
     * Stream a prompt and send each chunk to a callback
     */
    public function streamTo(string $message, callable $callback, array $options = []): string
    {
        // Auto-load from memory if configured and messages are empty
        if ($this->memory && $this->sessionId && empty($this->messages)) {
            $this->messages = $this->memory->load($this->sessionId);
        }

        $streamResponse = $this->stream($message, $options);

        // Stream to callback and collect full content
        $streamResponse->streamTo($callback);

        $fullContent = $streamResponse->getFullContent();

        // Add assistant response to history
        if (! empty($fullContent)) {
            $this->messages[] = ['role' => 'assistant', 'content' => $fullContent];
        }

        // Run guards on the full response
        if (! empty($this->guards)) {
            try {
                $this->runGuards($message, $fullContent);
            } catch (GuardException $e) {
                if ($this->fallback) {
                    $fallbackContent = ($this->fallback)($e);

                    return $fallbackContent;
                }

                throw $e;
            }
        }

        // Auto-save to memory if configured
        if ($this->memory && $this->sessionId) {
            $this->memory->save($this->sessionId, $this->messages);
        }

        return $fullContent;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function tool(string|ToolInterface $nameOrTool, ?string $description = null, ?Closure $callable = null): self
    {
        if ($nameOrTool instanceof ToolInterface) {
            $this->tools[] = $nameOrTool;

            return $this;
        }

        if ($description === null || $callable === null) {
            throw new RuntimeException('Description and callable are required when providing tool name as string');
        }

        $this->tools[] = Tool::fromClosure($nameOrTool, $description, $callable);

        return $this;
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function executeTool(string $name, array $arguments): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool->execute($arguments);
            }
        }

        // Better error message with suggestions
        $available = array_map(fn ($t) => $t->name(), $this->tools);
        $suggestions = $this->findSimilarToolNames($name, $available);

        $message = "Tool '{$name}' not found";

        if (! empty($suggestions)) {
            $message .= '. Did you mean: '.implode(', ', $suggestions).'?';
        }

        if (! empty($available)) {
            $message .= ' Available tools: '.implode(', ', $available);
        }

        throw new RuntimeException($message);
    }

    public function guard(string|Guard $guard, ?Closure $check = null): self
    {
        if ($guard instanceof Guard) {
            $this->guards[] = $guard;

            return $this;
        }

        if ($check instanceof Closure) {
            $name = is_string($guard) ? $guard : 'closure';

            $anonymousGuard = new class($name, $check) implements Guard
            {
                public function __construct(
                    private readonly string $name,
                    private readonly Closure $check,
                ) {}

                public function check(string $input, string $output): bool
                {
                    $fn = $this->check;

                    return (bool) $fn($input, $output);
                }

                public function getName(): string
                {
                    return ucfirst($this->name);
                }

                public function getViolationMessage(): string
                {
                    return sprintf('Guard %s failed', $this->getName());
                }
            };

            $this->guards[] = $anonymousGuard;

            return $this;
        }

        $fqcn = 'Pagent\\Guards\\'.ucfirst($guard).'Guard';
        if (! class_exists($fqcn)) {
            throw new RuntimeException("Guard class '{$fqcn}' not found");
        }

        $this->guards[] = new $fqcn;

        return $this;
    }

    public function fallback(Closure $callback): self
    {
        $this->fallback = $callback;

        return $this;
    }

    /**
     * @return Guard[]
     */
    public function getGuards(): array
    {
        return $this->guards;
    }

    public function middleware(string|Middleware $middleware): self
    {
        if ($middleware instanceof Middleware) {
            $this->middleware[] = $middleware;
        } else {
            $middlewareClass = 'Pagent\\Middleware\\'.ucfirst($middleware).'Middleware';

            if (! class_exists($middlewareClass)) {
                throw new RuntimeException("Middleware class '{$middlewareClass}' not found");
            }

            $this->middleware[] = new $middlewareClass;
        }

        return $this;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function handoff(string|Agent $targetAgent, ?string $reason = null): Agent
    {
        $handoff = new Orchestration\Handoff($this);
        $handoff->to($targetAgent);

        if ($reason) {
            $handoff->because($reason);
        }

        return $handoff->transfer();
    }

    public function delegate(string $task): Orchestration\Delegation
    {
        return new Orchestration\Delegation($this, $task);
    }

    /**
     * Clear all tools from this agent.
     */
    public function clearTools(): self
    {
        $this->tools = [];

        return $this;
    }

    /**
     * Clear all guards from this agent.
     */
    public function clearGuards(): self
    {
        $this->guards = [];
        $this->fallback = null;

        return $this;
    }

    /**
     * Clear all middleware from this agent.
     */
    public function clearMiddleware(): self
    {
        $this->middleware = [];

        return $this;
    }

    /**
     * Reset agent to initial state (clear history, tools, guards, middleware).
     */
    public function reset(): self
    {
        $this->messages = [];
        $this->tools = [];
        $this->guards = [];
        $this->middleware = [];
        $this->fallback = null;
        $this->memory = null;
        $this->sessionId = null;
        $this->contextManager = null;

        return $this;
    }

    /**
     * Clone this agent with a new name.
     */
    public function clone(string $newName): Agent
    {
        $clone = new Agent($newName);
        $clone->provider = $this->provider;
        $clone->config = $this->config;
        $clone->tools = $this->tools;
        $clone->guards = $this->guards;
        $clone->middleware = $this->middleware;
        $clone->fallback = $this->fallback;
        $clone->memory = $this->memory;
        $clone->contextManager = $this->contextManager;
        // Don't copy messages or sessionId - fresh conversation

        return $clone;
    }

    /**
     * Export conversation history as JSON string.
     */
    public function exportConversation(): string
    {
        return json_encode([
            'agent' => $this->name,
            'messages' => $this->messages,
            'exported_at' => date('c'),
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Import conversation history from JSON string.
     */
    public function importConversation(string $json): self
    {
        $data = json_decode($json, true);

        if (! isset($data['messages']) || ! is_array($data['messages'])) {
            throw new RuntimeException('Invalid conversation data format');
        }

        $this->messages = $data['messages'];

        return $this;
    }

    /**
     * Get usage statistics for this agent.
     */
    public function getStats(): array
    {
        $totalMessages = count($this->messages);
        $userMessages = count(array_filter($this->messages, fn ($m) => $m['role'] === 'user'));
        $assistantMessages = count(array_filter($this->messages, fn ($m) => $m['role'] === 'assistant'));

        return [
            'agent' => $this->name,
            'total_messages' => $totalMessages,
            'user_messages' => $userMessages,
            'assistant_messages' => $assistantMessages,
            'tools_registered' => count($this->tools),
            'guards_active' => count($this->guards),
            'middleware_active' => count($this->middleware),
        ];
    }

    /**
     * Get guard statistics (how many times guards were checked).
     */
    public function getGuardStats(): array
    {
        return array_map(fn ($guard) => [
            'name' => $guard->getName(),
            'active' => true,
        ], $this->guards);
    }

    private function runGuards(string $input, string $output): void
    {
        foreach ($this->guards as $guard) {
            if (! $guard->check($input, $output)) {
                throw new GuardException(
                    $guard->getViolationMessage(),
                    $guard->getName(),
                    $input,
                    $output,
                );
            }
        }
    }

    private function getToolSchemas(): array
    {
        $provider = get_class($this->provider);

        if (str_contains($provider, 'Anthropic')) {
            return array_map(fn ($tool) => $tool->toAnthropicSchema(), $this->tools);
        }

        if (str_contains($provider, 'OpenAI')) {
            return array_map(fn ($tool) => $tool->toOpenAISchema(), $this->tools);
        }

        return [];
    }

    private function handleToolCalls(object $response): object
    {
        // Add assistant message with tool calls to history
        $assistantMessage = ['role' => 'assistant', 'content' => $response->content ?? ''];

        // For Anthropic, we need to include the full content blocks
        if (isset($response->raw_content)) {
            $assistantMessage['content'] = $response->raw_content;
        } elseif (! empty($response->tool_calls)) {
            // For OpenAI, add tool_calls
            $assistantMessage['tool_calls'] = array_map(fn ($call) => [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => json_encode($call['arguments']),
                ],
            ], $response->tool_calls);
        }

        $this->messages[] = $assistantMessage;

        // Execute each tool call
        foreach ($response->tool_calls as $toolCall) {
            $result = $this->executeTool($toolCall['name'], $toolCall['arguments']);

            // Add tool result to messages
            $provider = get_class($this->provider);

            if (str_contains($provider, 'Anthropic')) {
                // Anthropic format
                $this->messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolCall['id'],
                            'content' => is_string($result) ? $result : json_encode($result),
                        ],
                    ],
                ];
            } else {
                // OpenAI format
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => is_string($result) ? $result : json_encode($result),
                ];
            }
        }

        // Make another API call with tool results
        $options = $this->config;
        $options['messages'] = $this->messages;

        if (! empty($this->tools)) {
            $options['tools'] = $this->getToolSchemas();
        }

        return $this->provider->prompt('', $options);
    }

    /**
     * Find similar tool names using Levenshtein distance.
     *
     * @param  string  $needle  The tool name to find
     * @param  array  $haystack  Available tool names
     * @return array Similar tool names
     */
    private function findSimilarToolNames(string $needle, array $haystack): array
    {
        if (empty($haystack)) {
            return [];
        }

        $distances = [];
        foreach ($haystack as $toolName) {
            $distances[$toolName] = levenshtein($needle, $toolName);
        }

        asort($distances);
        $closest = array_slice($distances, 0, 3, true);

        // Only return suggestions with distance <= 3
        return array_keys(array_filter($closest, fn ($dist) => $dist <= 3));
    }
}
