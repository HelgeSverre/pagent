<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

final readonly class DelegationResult
{
    /** Backwards-compatible aliases for the original stdClass result shape. */
    public string $worker;

    public string $manager;

    public string $worker_output;

    public string $manager_review;

    public function __construct(
        public string $task,
        public string $output,
        public bool $reviewed,
        public string $workerAgent,
        public string $managerAgent,
        public string $workerOutput,
        public ?string $managerReview,
        public bool $supervised,
    ) {
        $this->worker = $workerAgent;
        $this->manager = $managerAgent;
        $this->worker_output = $workerOutput;
        $this->manager_review = $managerReview ?? '';
    }
}
