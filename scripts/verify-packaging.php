<?php

declare(strict_types=1);

/**
 * Guards release-policy decisions that Composer cannot express directly.
 *
 * This script intentionally uses no project autoloader so it can run before
 * dependencies are installed and on the declared minimum PHP version.
 */
$composerPath = dirname(__DIR__).'/composer.json';
$contents = file_get_contents($composerPath);

if ($contents === false) {
    fwrite(STDERR, "Unable to read composer.json.\n");
    exit(1);
}

/** @var array{require?: array<string, string>, require-dev?: array<string, string>, scripts?: array<string, string>, suggest?: array<string, string>} $composer */
$composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
$requirements = $composer['require'] ?? [];
$developmentRequirements = $composer['require-dev'] ?? [];
$scripts = $composer['scripts'] ?? [];
$suggestions = $composer['suggest'] ?? [];

$errors = [];

if (($requirements['php'] ?? null) !== '>=8.4.1 <9.0') {
    $errors[] = 'The PHP requirement must match the supported floor: >=8.4.1 <9.0.';
}

foreach (['ext-curl', 'ext-mbstring', 'psr/log'] as $coreRequirement) {
    if (! array_key_exists($coreRequirement, $requirements)) {
        $errors[] = sprintf('%s is required by the production core.', $coreRequirement);
    }
}

foreach (['guzzlehttp/guzzle'] as $unusedDependency) {
    if (array_key_exists($unusedDependency, $requirements)) {
        $errors[] = sprintf('%s is unused by the library and must not be a direct requirement.', $unusedDependency);
    }
}

$optionalFeatures = [
    'nyholm/psr7',
    'open-telemetry/api',
    'open-telemetry/exporter-otlp',
    'open-telemetry/sdk',
    'open-telemetry/sem-conv',
    'psr/http-client',
    'psr/http-factory',
    'swaggest/json-schema',
    'symfony/http-client',
    'symfony/process',
    'teamtnt/tntsearch',
];

foreach ($optionalFeatures as $dependency) {
    if (array_key_exists($dependency, $requirements)) {
        $errors[] = sprintf('%s belongs to an optional feature and must not be a production requirement.', $dependency);
    }

    if (! array_key_exists($dependency, $developmentRequirements)) {
        $errors[] = sprintf('%s must remain available to development and feature test suites.', $dependency);
    }

    if (! isset($suggestions[$dependency]) || $suggestions[$dependency] === '') {
        $errors[] = sprintf('%s must explain its optional feature through Composer suggest.', $dependency);
    }
}

foreach ([
    'test' => ['--exclude-group=live', '--exclude-group=external'],
    'test:coverage' => ['--exclude-group=live', '--exclude-group=external'],
    'test:unit' => ['--exclude-group=live', '--exclude-group=external'],
    'test:integration' => ['--exclude-group=live', '--exclude-group=external'],
    'test:live' => ['--group=live'],
    'test:external' => ['--group=external'],
    'smoke:core' => ['scripts/smoke-core-runtime.php'],
] as $script => $requiredFragments) {
    $command = $scripts[$script] ?? '';

    foreach ($requiredFragments as $fragment) {
        if (! str_contains($command, $fragment)) {
            $errors[] = sprintf('Composer script "%s" must contain "%s".', $script, $fragment);
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Packaging policy checks passed.\n");
