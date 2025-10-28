<?php

declare(strict_types=1);

namespace Pagent\Memory\Adapters;

use Pagent\Contracts\Memory;

/**
 * Null memory adapter - no persistence
 *
 * This adapter does nothing and is used as the default when
 * no memory persistence is configured. Useful for testing
 * or when conversation persistence is not needed.
 */
final class NullAdapter implements Memory
{
    /**
     * Create a null adapter
     *
     * @param  array<string, mixed>  $config  Configuration (ignored)
     */
    public function __construct(array $config = [])
    {
        // No configuration needed
    }

    /**
     * Load messages (always returns empty)
     *
     * @param  string  $sessionId  Session identifier
     * @return array<int, array<string, mixed>> Empty array
     */
    public function load(string $sessionId): array
    {
        return [];
    }

    /**
     * Save messages (does nothing)
     *
     * @param  string  $sessionId  Session identifier
     * @param  array<int, array<string, mixed>>  $messages  Messages to save
     */
    public function save(string $sessionId, array $messages): void
    {
        // Do nothing
    }

    /**
     * Delete session (does nothing)
     *
     * @param  string  $sessionId  Session identifier
     */
    public function delete(string $sessionId): void
    {
        // Do nothing
    }

    /**
     * Check if session exists (always returns false)
     *
     * @param  string  $sessionId  Session identifier
     * @return bool Always false
     */
    public function exists(string $sessionId): bool
    {
        return false;
    }

    /**
     * Prune messages (returns empty array)
     *
     * @param  string  $sessionId  Session identifier
     * @param  int  $maxMessages  Maximum messages to keep
     * @return array<int, array<string, mixed>> Empty array
     */
    public function prune(string $sessionId, int $maxMessages): array
    {
        return [];
    }
}
