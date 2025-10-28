<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;

final class Grep extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private int $maxResults = 100,
        private int $contextLines = 0,
    ) {}

    public function name(): string
    {
        return 'grep';
    }

    public function description(): string
    {
        return 'Search for text or regex pattern in files. Returns matching lines with file names and line numbers.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Text or regex pattern to search for',
                ],
                'files' => [
                    'type' => 'string',
                    'description' => 'File pattern to search in (supports glob, e.g., **/*.php)',
                ],
                'regex' => [
                    'type' => 'boolean',
                    'description' => 'Whether pattern is a regex (default: false)',
                ],
            ],
            'required' => ['pattern', 'files'],
        ];
    }

    public function execute(array $params): mixed
    {
        $pattern = $params['pattern'] ?? throw new RuntimeException('Pattern parameter is required');
        $files = $params['files'] ?? throw new RuntimeException('Files parameter is required');
        $isRegex = $params['regex'] ?? false;

        $baseDir = $this->baseDir ?? getcwd();

        // Find files matching the pattern
        $fileList = $this->findFiles($baseDir, $files);

        // Search in files
        $results = [];
        $totalMatches = 0;

        foreach ($fileList as $file) {
            $filePath = $baseDir.DIRECTORY_SEPARATOR.$file;

            if (! is_file($filePath) || ! is_readable($filePath)) {
                continue;
            }

            $matches = $this->searchFile($filePath, $pattern, $isRegex);

            if (! empty($matches)) {
                $results[] = [
                    'file' => $file,
                    'matches' => $matches,
                ];

                $totalMatches += count($matches);

                if ($totalMatches >= $this->maxResults) {
                    break;
                }
            }
        }

        return [
            'results' => $results,
            'total_matches' => $totalMatches,
            'files_searched' => count($fileList),
            'truncated' => $totalMatches >= $this->maxResults,
        ];
    }

    private function findFiles(string $baseDir, string $pattern): array
    {
        // Handle ** recursive patterns
        if (str_contains($pattern, '**')) {
            return $this->recursiveGlob($baseDir, $pattern);
        }

        // Simple glob pattern
        $fullPattern = $baseDir.DIRECTORY_SEPARATOR.$pattern;
        $matches = glob($fullPattern, GLOB_BRACE);

        if ($matches === false) {
            return [];
        }

        return array_map(fn ($path) => str_replace($baseDir.DIRECTORY_SEPARATOR, '', $path), $matches);
    }

    private function recursiveGlob(string $baseDir, string $pattern): array
    {
        $results = [];
        $parts = explode('**', $pattern);
        $suffix = trim($parts[1] ?? '', '/');

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relativePath = str_replace($baseDir.DIRECTORY_SEPARATOR, '', $file->getPathname());

                if ($suffix === '' || fnmatch($suffix, basename($file->getPathname()))) {
                    $results[] = $relativePath;
                }
            }
        } catch (\Exception $e) {
            return [];
        }

        return $results;
    }

    private function searchFile(string $filePath, string $pattern, bool $isRegex): array
    {
        $matches = [];
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $lineNum => $line) {
            $found = false;

            if ($isRegex) {
                // Suppress errors for invalid regex patterns
                $previousErrorHandler = set_error_handler(function () {});
                $matchResult = preg_match($pattern, $line);
                restore_error_handler();

                // Only consider it a match if preg_match returns exactly 1
                // Returns false on error, 0 on no match, 1 on match
                if ($matchResult === 1) {
                    $found = true;
                }
                // If matchResult is false (invalid regex), silently skip - no match
            } else {
                if (str_contains($line, $pattern)) {
                    $found = true;
                }
            }

            if ($found) {
                $matches[] = [
                    'line' => $lineNum + 1,
                    'content' => $line,
                ];

                if (count($matches) >= $this->maxResults) {
                    break;
                }
            }
        }

        return $matches;
    }
}
