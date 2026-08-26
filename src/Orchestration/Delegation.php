<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

use Closure;
use Pagent\Agent;
use Pagent\Exceptions\RuntimeException;

use function resolveAgent;

final class Delegation
{
    private Agent $manager;

    private Agent $worker;

    private string $task;

    private ?Closure $supervisor = null;

    private ?Closure $onComplete = null;

    private bool $review = false;

    public function __construct(Agent $manager, string $task)
    {
        $this->manager = $manager;
        $this->task = $task;
    }

    public function to(string|Agent $worker): self
    {
        $resolved = resolveAgent($worker);

        if ($resolved === null) {
            $workerName = $worker instanceof Agent ? $worker->getName() : $worker;

            throw new RuntimeException("Worker agent '{$workerName}' not found");
        }

        $this->worker = $resolved;

        return $this;
    }

    public function supervise(?Closure $supervisor = null): self
    {
        $this->supervisor = $supervisor;

        return $this;
    }

    public function onComplete(Closure $callback): self
    {
        $this->onComplete = $callback;

        return $this;
    }

    /**
     * Have the manager summarize the worker's output with an extra LLM call.
     * Off by default: the worker output is returned directly.
     */
    public function review(bool $review = true): self
    {
        $this->review = $review;

        return $this;
    }

    public function execute(): DelegationResult
    {
        if (! isset($this->worker)) {
            throw new RuntimeException('No worker agent assigned for delegation');
        }

        // Run on an ephemeral clone so the registered worker's conversation
        // history is not polluted by delegated tasks.
        $worker = $this->worker->clone($this->worker->getName());
        $workerResponse = $worker->prompt($this->task);

        // Supervisor reviews if provided
        if ($this->supervisor) {
            $review = ($this->supervisor)($workerResponse->content, $this->task);

            if ($review === false) {
                throw new RuntimeException("Supervisor rejected worker output for task: {$this->task}");
            }

            if (is_string($review)) {
                // Supervisor provided feedback, ask worker to revise
                $workerResponse = $worker->prompt("Please revise based on this feedback: {$review}");
            }
        }

        $workerOutput = $workerResponse->content;
        $managerReview = null;

        if ($this->review) {
            // Manager summarizes on an ephemeral clone as well
            $managerPrompt = "Task: {$this->task}\n\nWorker ({$this->worker->getName()}) completed it with:\n{$workerOutput}\n\nProvide a brief summary.";
            $managerReview = $this->manager->clone($this->manager->getName())->prompt($managerPrompt)->content;
        }

        $result = new DelegationResult(
            task: $this->task,
            output: $managerReview ?? $workerOutput,
            reviewed: $this->review,
            workerAgent: $this->worker->getName(),
            managerAgent: $this->manager->getName(),
            workerOutput: $workerOutput,
            managerReview: $managerReview,
            supervised: $this->supervisor !== null,
        );

        // Call completion callback if provided
        if ($this->onComplete) {
            ($this->onComplete)($result);
        }

        return $result;
    }
}
