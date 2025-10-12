<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

use Closure;
use Pagent\Agent;
use RuntimeException;

final class Delegation
{
    private Agent $manager;
    private Agent $worker;
    private string $task;
    private ?Closure $supervisor = null;
    private ?Closure $onComplete = null;

    public function __construct(Agent $manager, string $task)
    {
        $this->manager = $manager;
        $this->task = $task;
    }

    public function to(string|Agent $worker): self
    {
        $this->worker = resolveAgent($worker);

        if ( ! $this->worker instanceof Agent) {
            $name = is_string($worker) ? $worker : 'unknown';

            throw new RuntimeException("Worker agent '{$name}' not found");
        }

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

    public function execute(): object
    {
        if ( ! isset($this->worker)) {
            throw new RuntimeException('No worker agent assigned for delegation');
        }

        // Worker executes the task
        $workerResponse = $this->worker->prompt($this->task);

        // Supervisor reviews if provided
        if ($this->supervisor) {
            $review = ($this->supervisor)($workerResponse->content, $this->task);

            if (false === $review) {
                throw new RuntimeException("Supervisor rejected worker output for task: {$this->task}");
            }

            if (is_string($review)) {
                // Supervisor provided feedback, ask worker to revise
                $workerResponse = $this->worker->prompt("Please revise based on this feedback: {$review}");
            }
        }

        // Manager reviews the result
        $managerPrompt = "Task: {$this->task}\n\nWorker ({$this->worker->getName()}) completed it with:\n{$workerResponse->content}\n\nProvide a brief summary.";
        $managerReview = $this->manager->prompt($managerPrompt);

        $result = (object) [
            'task' => $this->task,
            'worker' => $this->worker->getName(),
            'worker_output' => $workerResponse->content,
            'manager' => $this->manager->getName(),
            'manager_review' => $managerReview->content,
            'supervised' => null !== $this->supervisor,
        ];

        // Call completion callback if provided
        if ($this->onComplete) {
            ($this->onComplete)($result);
        }

        return $result;
    }
}
