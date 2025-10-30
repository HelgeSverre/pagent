<?php

declare(strict_types=1);

namespace Pagent\Workflow\Concerns;

use Pagent\Agent;

trait HasTelemetry
{
    /**
     * Check if telemetry should be enabled based on agents in the workflow.
     * Returns true if any agent has telemetry enabled.
     */
    protected function shouldEnableTelemetry(): bool
    {
        foreach ($this->steps as $step) {
            $agent = $this->extractAgentFromStep($step);

            if ($agent !== null && $agent->telemetryEnabled) {
                return true;
            }
        }

        return false;
    }

    private function extractAgentFromStep(mixed $step): ?Agent
    {
        if ($step instanceof Agent) {
            return $step;
        }

        if (is_array($step) && isset($step['handler']) && $step['handler'] instanceof Agent) {
            return $step['handler'];
        }

        return null;
    }
}
