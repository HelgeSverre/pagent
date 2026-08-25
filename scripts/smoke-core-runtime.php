<?php

declare(strict_types=1);
use Pagent\Agent;
use Pagent\Providers\Mock;

/**
 * Verifies that the synchronous core can boot without Composer's vendor
 * autoloader. Optional feature packages deliberately are not registered.
 */
$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Pagent\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = $root.'/src/'.str_replace('\\', '/', $relativeClass).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

$agent = new Agent('packaging-smoke');
$agent->provider(new Mock([
    'responses' => ['hello' => 'world'],
]));

$response = $agent->prompt('hello');

if ($response->content !== 'world') {
    fwrite(STDERR, "Core smoke test returned an unexpected response.\n");
    exit(1);
}

fwrite(STDOUT, "Core runtime smoke test passed without optional packages.\n");
