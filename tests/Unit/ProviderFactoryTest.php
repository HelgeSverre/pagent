<?php

declare(strict_types=1);

use Pagent\Contracts\Provider;
use Pagent\ProviderFactory;
use Pagent\Providers\Mock;

it('resolves bundled provider aliases', function (): void {
    expect(ProviderFactory::resolve('mock', ['responses' => ['hello' => 'world']]))
        ->toBeInstanceOf(Mock::class);
});

it('allows applications to register a custom provider factory', function (): void {
    $name = 'custom-'.uniqid();
    $provider = new class implements Provider
    {
        public function prompt(string $message, array $options = []): object
        {
            return (object) ['content' => "custom: {$message}"];
        }
    };

    ProviderFactory::register($name, static fn (array $config): Provider => $provider);

    expect(ProviderFactory::resolve($name))->toBe($provider);
});

it('can unregister a custom provider factory', function (): void {
    $name = 'custom-'.uniqid();
    ProviderFactory::register($name, static fn (array $config): Provider => new Mock($config));

    ProviderFactory::unregister($name);

    expect(fn () => ProviderFactory::resolve($name))
        ->toThrow(InvalidArgumentException::class, "Unknown provider: {$name}");
});

it('reset clears custom factories and re-registers defaults on demand', function (): void {
    $name = 'custom-'.uniqid();
    ProviderFactory::register($name, static fn (array $config): Provider => new Mock($config));

    ProviderFactory::reset();

    expect(fn () => ProviderFactory::resolve($name))
        ->toThrow(InvalidArgumentException::class, "Unknown provider: {$name}")
        ->and(ProviderFactory::resolve('mock'))->toBeInstanceOf(Mock::class);
});
