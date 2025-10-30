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
            // Handle Chain format (Agent|Provider directly)
            if ($step instanceof Agent && $step->telemetryEnabled) {
                return true;
            }

            // Handle Pipeline format (array with 'handler' key)
            if (is_array($step) && isset($step['handler']) && $step['handler'] instanceof Agent && $step['handler']->telemetryEnabled) {
                return true;
            }
        }

        return false;
    }
}
