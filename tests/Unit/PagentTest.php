<?php

declare(strict_types=1);

use Pagent\Events\EventManager;
use Pagent\Observability\TelemetryEventBridge;
use Pagent\Observability\TelemetryManager;
use Pagent\Pagent as PagentRuntime;
use Pagent\ProviderFactory;
use Pagent\Registry;
use Pagent\Usage\UsageTracker;

test('process lifecycle reset clears every global framework service', function () {
    agent('registered')->provider('mock');
    ProviderFactory::register('temporary', fn () => mock());

    $events = EventManager::instance();
    $usage = UsageTracker::global();
    $bridge = TelemetryEventBridge::global();
    $telemetry = TelemetryManager::instance()->initialize([
        'enabled' => true,
        'exporter' => 'inmemory',
    ]);

    PagentRuntime::reset();

    try {
        expect(Registry::all())->toBeEmpty()
            ->and(EventManager::instance())->not->toBe($events)
            ->and(UsageTracker::global())->not->toBe($usage)
            ->and(TelemetryEventBridge::global())->not->toBe($bridge)
            ->and(TelemetryManager::instance())->not->toBe($telemetry)
            ->and(TelemetryManager::instance()->isEnabled())->toBeFalse()
            ->and(fn () => ProviderFactory::resolve('temporary'))
            ->toThrow(InvalidArgumentException::class, 'Unknown provider');
    } finally {
        PagentRuntime::reset();
    }
});
