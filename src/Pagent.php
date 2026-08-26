<?php

declare(strict_types=1);

namespace Pagent;

use Pagent\Events\EventManager;
use Pagent\Observability\TelemetryEventBridge;
use Pagent\Observability\TelemetryManager;
use Pagent\Usage\UsageTracker;

/**
 * Process-global lifecycle for the library's static state.
 *
 * The library keeps several process-wide registries (agents, events,
 * telemetry, usage tracking, provider factories). This facade resets them all
 * in one call so tests and long-running workers do not have to know each one.
 *
 * This deliberately does not introduce a stateful "framework context" object:
 * Registry already accepts an AgentRegistry for scoped composition, while the
 * remaining legacy services are static APIs. Wrapping those statics in another
 * object would add indirection without creating isolation. If they gain
 * injectable instances later, that composition root should replace this facade.
 */
final class Pagent
{
    /**
     * Reset every process-global registry the library maintains.
     */
    public static function reset(): void
    {
        Registry::clear();
        EventManager::reset();
        TelemetryManager::reset();
        UsageTracker::resetGlobal();
        TelemetryEventBridge::resetGlobal();
        ProviderFactory::reset();
    }
}
