<?php

declare(strict_types=1);

namespace Pagent\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Memory interface for persistent conversation storage
 *
 * Implementations provide storage backends for agent conversations,
 * enabling sessions to persist across requests and script executions.
 *
 * Message Format:
 * Messages should be arrays with 'role' and 'content' keys:
 * [
 *     ['role' => 'user', 'content' => 'Hello'],
 *     ['role' => 'assistant', 'content' => 'Hi there!'],
 * ]
 */
interface Memory
{
    /**
     * Load conversation messages for a session
     *
     * @param  string  $sessionId  Unique session identifier
     * @return array<int, array<string, mixed>> Array of message objects
     *
     * @throws RuntimeException If storage backend fails
     */
    public function load(string $sessionId): array;

    /**
     * Save conversation messages for a session
     *
     * Implementations should handle atomic writes to prevent data corruption.
     * System messages (role=system) should be preserved during saves.
     *
     * @param  string  $sessionId  Unique session identifier
     * @param  array<int, array<string, mixed>>  $messages  Messages to save
     *
     * @throws RuntimeException If storage backend fails
     * @throws InvalidArgumentException If messages format is invalid
     */
    public function save(string $sessionId, array $messages): void;

    /**
     * Delete a conversation session
     *
     * @param  string  $sessionId  Unique session identifier
     *
     * @throws RuntimeException If storage backend fails
     */
    public function delete(string $sessionId): void;

    /**
     * Check if a session exists
     *
     * @param  string  $sessionId  Unique session identifier
     * @return bool True if session exists, false otherwise
     *
     * @throws RuntimeException If storage backend fails
     */
    public function exists(string $sessionId): bool;

    /**
     * Prune old messages from a session
     *
     * Keeps the most recent N messages. System messages should be preserved.
     *
     * @param  string  $sessionId  Unique session identifier
     * @param  int  $maxMessages  Maximum number of messages to keep
     * @return array<int, array<string, mixed>> Pruned messages
     *
     * @throws RuntimeException If storage backend fails
     * @throws InvalidArgumentException If maxMessages is < 1
     */
    public function prune(string $sessionId, int $maxMessages): array;
}
