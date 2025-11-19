<?php

declare(strict_types=1);

namespace Pagent\Usage\Storage;

use Pagent\Usage\UsageData;

/**
 * In-memory storage for usage data.
 *
 * Stores usage records in memory (lost when process ends).
 * Suitable for development and testing.
 */
final class InMemoryUsageStorage implements UsageStorage
{
    /**
     * @var array<int, UsageData>
     */
    private array $records = [];

    public function save(UsageData $data): void
    {
        $this->records[] = $data;
    }

    public function getAll(): array
    {
        return $this->records;
    }

    public function byAgent(string $agentName): array
    {
        return array_values(array_filter(
            $this->records,
            fn (UsageData $data) => $data->agentName === $agentName
        ));
    }

    public function bySession(string $sessionId): array
    {
        return array_values(array_filter(
            $this->records,
            fn (UsageData $data) => $data->sessionId === $sessionId
        ));
    }

    public function getTotalCost(): float
    {
        return array_reduce(
            $this->records,
            fn (float $total, UsageData $data) => $total + $data->cost,
            0.0
        );
    }

    public function getTotalCostByAgent(string $agentName): float
    {
        $records = $this->byAgent($agentName);

        return array_reduce(
            $records,
            fn (float $total, UsageData $data) => $total + $data->cost,
            0.0
        );
    }

    public function getTotalCostBySession(string $sessionId): float
    {
        $records = $this->bySession($sessionId);

        return array_reduce(
            $records,
            fn (float $total, UsageData $data) => $total + $data->cost,
            0.0
        );
    }

    public function exportToArray(): array
    {
        return array_map(
            fn (UsageData $data) => $data->toArray(),
            $this->records
        );
    }

    public function clear(): void
    {
        $this->records = [];
    }

    /**
     * Get count of usage records.
     */
    public function count(): int
    {
        return count($this->records);
    }

    /**
     * Get latest usage record.
     */
    public function getLatest(): ?UsageData
    {
        if (empty($this->records)) {
            return null;
        }

        return $this->records[array_key_last($this->records)];
    }

    /**
     * Get latest usage record for a specific agent.
     */
    public function getLatestByAgent(string $agentName): ?UsageData
    {
        $records = $this->byAgent($agentName);

        if (empty($records)) {
            return null;
        }

        return $records[array_key_last($records)];
    }
}
