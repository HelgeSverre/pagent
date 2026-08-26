<?php

declare(strict_types=1);

namespace Pagent\Workflow;

use Pagent\Exceptions\PagentException;
use Pagent\Exceptions\RuntimeException;
use Throwable;

/**
 * A workflow step failed. Carries the results of the steps that completed
 * before the failure so callers can inspect (or bill for) partial progress.
 */
final class WorkflowException extends RuntimeException implements PagentException
{
    /**
     * @param  list<StepResult>  $partialResults
     */
    public function __construct(
        string $message,
        public readonly array $partialResults,
        public readonly string $failedStep,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
