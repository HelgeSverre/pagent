<?php

declare(strict_types=1);

namespace Pagent;

use Closure;
use Pagent\Contracts\Guard;
use Pagent\Contracts\Middleware;
use Pagent\Contracts\Provider;
use Pagent\Exceptions\GuardException;
use Pagent\Tool\Tool;
use RuntimeException;

use function array_map;
use function array_merge;
use function class_exists;
use function get_class;
use function is_string;
use function json_encode;
use function sprintf;
use function str_contains;
use function ucfirst;

final class Agent
{
    public array $messages = [];

    private array $config = [];

    private ?Provider $provider = null;

    /** @var Tool[] */
    private array $tools = [];

    /** @var Guard[] */
    private array $guards = [];

    /** @var Middleware[] */
    private array $middleware = [];

    private ?Closure $fallback = null;

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

    public function prompt(string $message, array $options = []): object
    {
        if (! $this->provider) {
            throw new RuntimeException("No provider set for agent '{$this->name}'");
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

        return $response;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function tool(string $name, string $description, Closure $callable): self
    {
        $this->tools[] = Tool::fromClosure($name, $description, $callable);

        return $this;
    }

    /**
     * @return Tool[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function executeTool(string $name, array $arguments): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $name) {
                return $tool->execute($arguments);
            }
        }

        throw new RuntimeException("Tool '{$name}' not found");
    }

    public function guard(string|Guard $guard, ?Closure $check = null): self
    {
        if ($guard instanceof Guard) {
            $this->guards[] = $guard;

            return $this;
        }

        if ($check instanceof Closure) {
            $name = is_string($guard) ? $guard : 'closure';

            $anonymousGuard = new class ($name, $check) implements Guard {
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

        $fqcn = 'Pagent\\Guards\\' . ucfirst($guard) . 'Guard';
        if (! class_exists($fqcn)) {
            throw new RuntimeException("Guard class '{$fqcn}' not found");
        }

        $this->guards[] = new $fqcn();

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
            $middlewareClass = 'Pagent\\Middleware\\' . ucfirst($middleware) . 'Middleware';

            if (! class_exists($middlewareClass)) {
                throw new RuntimeException("Middleware class '{$middlewareClass}' not found");
            }

            $this->middleware[] = new $middlewareClass();
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
            return array_map(fn($tool) => $tool->toAnthropicSchema(), $this->tools);
        }

        if (str_contains($provider, 'OpenAI')) {
            return array_map(fn($tool) => $tool->toOpenAISchema(), $this->tools);
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
            $assistantMessage['tool_calls'] = array_map(fn($call) => [
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
}
