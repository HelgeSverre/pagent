<?php

declare(strict_types=1);

namespace Pagent;

final class Registry
{
    private static array $agents = [];

    public static function set(string $name, Agent $agent): void
    {
        self::$agents[$name] = $agent;
    }

    public static function get(string $name): ?Agent
    {
        return self::$agents[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return isset(self::$agents[$name]);
    }

    public static function all(): array
    {
        return self::$agents;
    }

    public static function clear(): void
    {
        self::$agents = [];
    }
}
