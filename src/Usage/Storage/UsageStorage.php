<?php

declare(strict_types=1);

namespace Pagent\Usage\Storage;

use Pagent\Usage\UsageData;

/**
 * Interface for usage data storage backends.
 */
interface UsageStorage
{
    /**
     * Save a usage record.
     */
    public function save(UsageData $data): void;

    /**
     * Get all usage records.
     *
     * @return array<int, UsageData>
     */
    public function getAll(): array;

    /**
     * Get usage records for a specific agent.
     *
     * @return array<int, UsageData>
     */
    public function byAgent(string $agentName): array;

    /**
     * Get usage records for a specific session.
     *
     * @return array<int, UsageData>
     */
    public function bySession(string $sessionId): array;

    /**
     * Get total cost across all records.
     *
     * Records with unknown pricing (null cost) are skipped.
     */
    public function getTotalCost(): float;

    /**
     * Get total cost for a specific agent.
     */
    public function getTotalCostByAgent(string $agentName): float;

    /**
     * Get total cost for a specific session.
     */
    public function getTotalCostBySession(string $sessionId): float;

    /**
     * Export all usage data to array format.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportToArray(): array;

    /**
     * Clear all usage records.
     */
    public function clear(): void;
}
