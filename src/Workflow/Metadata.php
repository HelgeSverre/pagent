<?php

declare(strict_types=1);

namespace Pagent\Workflow;

final readonly class Metadata
{
    public function __construct(
        public int $totalTokens,
        public float $duration,
        public int $stepsExecuted,
        public string $startedAt,
        public string $completedAt,
    ) {}

    public static function create(
        int $totalTokens,
        float $duration,
        int $stepsExecuted,
        ?string $startedAt = null,
        ?string $completedAt = null,
    ): self {
        $now = date('Y-m-d H:i:s');

        return new self(
            totalTokens: $totalTokens,
            duration: $duration,
            stepsExecuted: $stepsExecuted,
            startedAt: $startedAt ?? $now,
            completedAt: $completedAt ?? $now,
        );
    }

    public function toArray(): array
    {
        return [
            'total_tokens' => $this->totalTokens,
            'duration' => $this->duration,
            'steps_executed' => $this->stepsExecuted,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
        ];
    }
}
