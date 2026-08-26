<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

use Pagent\Agent;
use Pagent\Exceptions\RuntimeException;

use function is_string;
use function json_encode;
use function resolveAgent;
use function str_starts_with;

final class Handoff
{
    /**
     * Marks the injected handoff-context message so a later handoff to the
     * same agent replaces it instead of appending another transcript dump.
     */
    private const CONTEXT_MARKER = "[handoff-context]\n";

    private Agent $fromAgent;

    private Agent $toAgent;

    private ?string $reason = null;

    public function __construct(Agent $fromAgent)
    {
        $this->fromAgent = $fromAgent;
    }

    public function to(string|Agent $targetAgent): self
    {
        $resolved = resolveAgent($targetAgent);

        if ($resolved === null) {
            $targetName = $targetAgent instanceof Agent ? $targetAgent->getName() : $targetAgent;

            throw new RuntimeException("Target agent '{$targetName}' not found for handoff");
        }

        $this->toAgent = $resolved;

        return $this;
    }

    public function because(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function transfer(): Agent
    {
        if (! isset($this->toAgent)) {
            throw new RuntimeException('No target agent specified for handoff');
        }

        // Build the transferred transcript
        $contextMessage = self::CONTEXT_MARKER."Previous conversation with {$this->fromAgent->getName()}:\n\n";

        foreach ($this->fromAgent->getMessages() as $message) {
            $role = $message['role'];
            $content = is_string($message['content']) ? $message['content'] : json_encode($message['content']);
            $contextMessage .= "[{$role}]: {$content}\n";
        }

        if ($this->reason) {
            $contextMessage .= "\nHandoff reason: {$this->reason}\n";
        }

        // Keep the target's own conversation, but replace any previously
        // transferred transcript instead of accumulating duplicates.
        $context = [];

        foreach ($this->toAgent->getMessages() as $message) {
            if (is_string($message['content']) && str_starts_with($message['content'], self::CONTEXT_MARKER)) {
                continue;
            }

            $context[] = $message;
        }

        $context[] = [
            'role' => 'user',
            'content' => $contextMessage,
        ];

        $this->toAgent->adoptContext($context);

        return $this->toAgent;
    }
}
