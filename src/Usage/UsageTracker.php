<?php

declare(strict_types=1);

namespace Pagent\Usage;

use Pagent\Agent;
use Pagent\Events\Event;
use Pagent\Events\EventListener;
use Pagent\Events\Events\LLM\AfterLLMResponseEvent;
use Pagent\Events\Events\Stream\StreamCompletedEvent;
use Pagent\Usage\Storage\InMemoryUsageStorage;
use Pagent\Usage\Storage\UsageStorage;

/**
 * Usage tracker that listens to LLM events and records usage/cost data.
 *
 * Implements EventListener interface to automatically track LLM usage via events.
 * Follow the same pattern as TelemetryEventBridge for consistency.
 */
final class UsageTracker implements EventListener
{
    /**
     * Global singleton instance.
     */
    private static ?self $globalInstance = null;

    /**
     * Configuration for the tracker.
     *
     * @var array{enabled: bool, track_llm: bool, track_streaming: bool, storage?: UsageStorage, pricing?: array<string, array<string, array{input: float, output: float, cached_input?: float}>>}
     */
    private array $config;

    /**
     * Storage backend for usage records.
     */
    private UsageStorage $storage;

    /**
     * Pricing calculator.
     */
    private PricingCalculator $pricingCalculator;

    /**
     * @param  array{enabled?: bool, track_llm?: bool, track_streaming?: bool, storage?: UsageStorage, pricing?: array<string, array<string, array{input: float, output: float, cached_input?: float}>>}  $config
     */
    public function __construct(array $config = [])
    {
        $defaults = [
            'enabled' => true,
            'track_llm' => true,
            'track_streaming' => true,
        ];

        $this->config = array_merge($defaults, $config);
        $this->storage = $config['storage'] ?? new InMemoryUsageStorage;
        $this->pricingCalculator = new PricingCalculator($config['pricing'] ?? []);
    }

    /**
     * Get list of event names this tracker listens to.
     *
     * @return array<int, string>
     */
    public function listensTo(): array
    {
        $events = [];

        if ($this->config['track_llm']) {
            $events[] = 'after_llm_response';
        }

        if ($this->config['track_streaming']) {
            $events[] = 'stream_completed';
        }

        return $events;
    }

    /**
     * Handle incoming events and track usage.
     */
    public function handle(Event $event): void
    {
        if (! $this->config['enabled']) {
            return;
        }

        match (true) {
            $event instanceof AfterLLMResponseEvent => $this->handleLLMResponse($event),
            $event instanceof StreamCompletedEvent => $this->handleStreamCompleted($event),
            default => null,
        };
    }

    /**
     * Handle LLM response event - track usage and cost.
     */
    private function handleLLMResponse(AfterLLMResponseEvent $event): void
    {
        // Check if LLM tracking is enabled
        if (! $this->config['track_llm']) {
            return;
        }

        // Extract usage data from response
        $usage = $event->responseData['usage'] ?? [];

        if (empty($usage)) {
            return;  // No usage data to track
        }

        // Calculate cost using provider-specific pricing
        $cost = $this->pricingCalculator->calculate(
            $event->provider,
            $event->model,
            $usage
        );

        // Create usage data object
        $usageData = UsageData::fromResponse(
            agentName: $event->agent->getName(),
            provider: $event->provider,
            model: $event->model,
            usage: $usage,
            cost: $cost,
            sessionId: $this->getSessionId($event->agent)
        );

        // Store usage data
        $this->storage->save($usageData);
    }

    /**
     * Handle stream completed event - track streaming usage and cost.
     */
    private function handleStreamCompleted(StreamCompletedEvent $event): void
    {
        // Check if streaming tracking is enabled
        if (! $this->config['track_streaming']) {
            return;
        }

        // Check if event has usage data
        if (empty($event->usage) || empty($event->provider) || empty($event->model)) {
            return;
        }

        // Calculate cost using provider-specific pricing
        $cost = $this->pricingCalculator->calculate(
            $event->provider,
            $event->model,
            $event->usage
        );

        // Create usage data object
        $usageData = UsageData::fromResponse(
            agentName: $event->agent->getName(),
            provider: $event->provider,
            model: $event->model,
            usage: $event->usage,
            cost: $cost,
            sessionId: $this->getSessionId($event->agent)
        );

        // Store usage data
        $this->storage->save($usageData);
    }

    /**
     * Get session ID from agent if available.
     */
    private function getSessionId(Agent $agent): ?string
    {
        // Use reflection to check if sessionId property exists and get its value
        try {
            $reflection = new \ReflectionClass($agent);

            if ($reflection->hasProperty('sessionId')) {
                $property = $reflection->getProperty('sessionId');
                $property->setAccessible(true);

                $value = $property->getValue($agent);

                return is_string($value) ? $value : null;
            }
        } catch (\ReflectionException) {
            // Property doesn't exist or can't be accessed
        }

        return null;
    }

    /**
     * Get the storage backend.
     */
    public function storage(): UsageStorage
    {
        return $this->storage;
    }

    /**
     * Get total cost across all tracked usage.
     */
    public function getTotalCost(): float
    {
        return $this->storage->getTotalCost();
    }

    /**
     * Get total cost for a specific agent.
     */
    public function getCostByAgent(string $agentName): float
    {
        return $this->storage->getTotalCostByAgent($agentName);
    }

    /**
     * Get total cost for a specific session.
     */
    public function getCostBySession(string $sessionId): float
    {
        return $this->storage->getTotalCostBySession($sessionId);
    }

    /**
     * Get all usage records.
     *
     * @return array<int, UsageData>
     */
    public function getAll(): array
    {
        return $this->storage->getAll();
    }

    /**
     * Get usage records for a specific agent.
     *
     * @return array<int, UsageData>
     */
    public function byAgent(string $agentName): array
    {
        return $this->storage->byAgent($agentName);
    }

    /**
     * Get usage records for a specific session.
     *
     * @return array<int, UsageData>
     */
    public function bySession(string $sessionId): array
    {
        return $this->storage->bySession($sessionId);
    }

    /**
     * Clear all usage records.
     */
    public function clear(): void
    {
        $this->storage->clear();
    }

    /**
     * Get global tracker instance (singleton pattern).
     *
     * @param  array{enabled?: bool, track_llm?: bool, track_streaming?: bool, storage?: UsageStorage, pricing?: array<string, array<string, array{input: float, output: float, cached_input?: float}>>}  $config
     */
    public static function global(array $config = []): self
    {
        if (self::$globalInstance === null) {
            self::$globalInstance = new self($config);

            // Auto-register with EventManager
            \Pagent\Events\EventManager::instance()->listen(self::$globalInstance);
        }

        return self::$globalInstance;
    }

    /**
     * Reset global instance (for testing).
     *
     * @internal
     */
    public static function resetGlobal(): void
    {
        self::$globalInstance = null;
    }
}
