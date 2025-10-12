<?php

declare(strict_types=1);

namespace Pagent\Workflow;

final readonly class StepMetadata
{
    public function __construct(
        public int $tokens,
        public float $duration,
        public string $timestamp,
    ) {}

    public static function create(int $tokens, float $duration): self
    {
        return new self(
            tokens: $tokens,
            duration: $duration,
            timestamp: date('Y-m-d H:i:s'),
        );
    }

    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'duration' => $this->duration,
            'timestamp' => $this->timestamp,
        ];
    }
}
