<?php

declare(strict_types=1);

namespace Pagent;

use Closure;
use InvalidArgumentException;
use Pagent\Contracts\Provider;

use function array_merge;
use function strtolower;

/**
 * Resolves the short provider names used by the fluent API.
 *
 * Applications can register their own provider factories instead of coupling
 * Agent construction to a fixed list of bundled providers.
 */
final class ProviderFactory
{
    /** @var array<string, Closure(array<string, mixed>): Provider> */
    private static array $factories = [];

    private static bool $defaultsRegistered = false;

    /**
     * @param  Closure(array<string, mixed>): Provider  $factory
     */
    public static function register(string $name, Closure $factory): void
    {
        self::$factories[strtolower($name)] = $factory;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function resolve(string|Provider $provider, array $config = []): Provider
    {
        if ($provider instanceof Provider) {
            return $provider;
        }

        $name = strtolower($provider);
        self::registerDefaults();
        $factory = self::$factories[$name] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException("Unknown provider: {$provider}");
        }

        return $factory($config);
    }

    private static function registerDefaults(): void
    {
        if (self::$defaultsRegistered) {
            return;
        }

        self::$defaultsRegistered = true;

        self::$factories['anthropic'] ??= static fn (array $config): Provider => new Providers\Anthropic($config);
        self::$factories['openai'] ??= static fn (array $config): Provider => new Providers\OpenAI($config);
        self::$factories['opencode'] ??= static fn (array $config): Provider => new Providers\OpenCode($config);
        self::$factories['opencode-zen'] ??= static fn (array $config): Provider => new Providers\OpenCode(array_merge($config, ['gateway' => 'zen']));
        self::$factories['opencode-go'] ??= static fn (array $config): Provider => new Providers\OpenCode(array_merge($config, ['gateway' => 'go']));
        self::$factories['ollama'] ??= static fn (array $config): Provider => new Providers\Ollama($config);
        self::$factories['mock'] ??= static fn (array $config): Provider => new Providers\Mock($config);
    }
}
