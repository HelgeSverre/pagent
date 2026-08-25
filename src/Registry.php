<?php

declare(strict_types=1);

namespace Pagent;

use Closure;

final class Registry
{
    private static ?AgentRegistry $registry = null;

    public static function instance(): AgentRegistry
    {
        return self::$registry ??= new AgentRegistry;
    }

    /**
     * Replace the registry used by the static convenience API.
     *
     * This is useful when a worker handles multiple isolated applications.
     */
    public static function use(AgentRegistry $registry): AgentRegistry
    {
        $previous = self::instance();
        self::$registry = $registry;

        return $previous;
    }

    /**
     * Run a callback with an isolated registry, restoring the previous context
     * even when the callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function scoped(AgentRegistry $registry, Closure $callback): mixed
    {
        $previous = self::instance();
        self::$registry = $registry;

        try {
            return $callback();
        } finally {
            self::$registry = $previous;
        }
    }

    public static function set(string $name, Agent $agent): void
    {
        self::instance()->set($name, $agent);
    }

    public static function get(string $name): ?Agent
    {
        return self::instance()->get($name);
    }

    public static function has(string $name): bool
    {
        return self::instance()->has($name);
    }

    /**
     * @return array<string, Agent>
     */
    public static function all(): array
    {
        return self::instance()->all();
    }

    /**
     * @param  Closure(string): Agent  $factory
     */
    public static function getOrCreate(string $name, Closure $factory): Agent
    {
        return self::instance()->getOrCreate($name, $factory);
    }

    public static function clear(): void
    {
        self::instance()->clear();
    }
}
