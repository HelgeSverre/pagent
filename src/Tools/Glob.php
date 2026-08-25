<?php

declare(strict_types=1);

namespace Pagent\Tools;

final class Glob extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private int $maxResults = 1000,
    ) {}

    public function name(): string
    {
        return 'glob';
    }

    public function description(): string
    {
        return 'Find files matching a pattern. Supports glob patterns like **/*.php, src/**/*.txt, etc.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Glob pattern to match files (e.g., **/*.php, src/**/*.txt)',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $params): mixed
    {
        $pattern = $this->requiredString($params, 'pattern');

        $baseDir = $this->baseDir ?? getcwd();

        // Use glob with GLOB_BRACE for advanced patterns
        $matches = $this->recursiveGlob($baseDir, $pattern);

        // Limit results
        if (count($matches) > $this->maxResults) {
            $matches = array_slice($matches, 0, $this->maxResults);
        }

        return [
            'matches' => $matches,
            'count' => count($matches),
            'truncated' => count($matches) >= $this->maxResults,
        ];
    }

    private function recursiveGlob(string $baseDir, string $pattern): array
    {
        // Handle ** recursive patterns
        if (str_contains($pattern, '**')) {
            return $this->handleRecursivePattern($baseDir, $pattern);
        }

        // Simple glob pattern
        $fullPattern = $baseDir.DIRECTORY_SEPARATOR.$pattern;
        $matches = glob($fullPattern, GLOB_BRACE);

        if ($matches === false) {
            return [];
        }

        return array_map(fn ($path) => str_replace($baseDir.DIRECTORY_SEPARATOR, '', $path), $matches);
    }

    private function handleRecursivePattern(string $baseDir, string $pattern): array
    {
        $results = [];

        // Split on **
        $parts = explode('**', $pattern);
        $prefix = $parts[0] ?? '';
        $suffix = $parts[1] ?? '';

        // Remove leading/trailing slashes
        $prefix = trim($prefix, '/');
        $suffix = trim($suffix, '/');

        $searchDir = $baseDir.($prefix ? DIRECTORY_SEPARATOR.$prefix : '');

        // Recursively scan directories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($searchDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = str_replace($baseDir.DIRECTORY_SEPARATOR, '', $file->getPathname());

            if ($suffix === '') {
                // Match all files
                if ($file->isFile()) {
                    $results[] = $relativePath;
                }
            } else {
                // Match suffix pattern
                $suffixPattern = $suffix;
                if (fnmatch($suffixPattern, basename($file->getPathname()))) {
                    $results[] = $relativePath;
                }
            }

            if (count($results) >= $this->maxResults) {
                break;
            }
        }

        return $results;
    }
}
