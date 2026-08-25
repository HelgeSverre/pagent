<?php

declare(strict_types=1);

namespace Pagent\Orchestration;

use Pagent\Agent;
use RuntimeException;

use function json_encode;
use function resolveAgent;

final class Handoff
{
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

        // Transfer conversation history
        $contextMessage = "Previous conversation with {$this->fromAgent->getName()}:\n\n";

        foreach ($this->fromAgent->messages as $message) {
            $role = $message['role'];
            $content = is_string($message['content']) ? $message['content'] : json_encode($message['content']);
            $contextMessage .= "[{$role}]: {$content}\n";
        }

        if ($this->reason) {
            $contextMessage .= "\nHandoff reason: {$this->reason}\n";
        }

        // Add context to new agent
        $this->toAgent->messages[] = [
            'role' => 'user',
            'content' => $contextMessage,
        ];

        return $this->toAgent;
    }
}
