<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;

final class FileRead extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private ?int $maxSize = null,
    ) {
        $this->maxSize = $maxSize ?? 10 * 1024 * 1024; // 10MB default
    }

    public function name(): string
    {
        return 'file_read';
    }

    public function description(): string
    {
        return 'Read the contents of a file. Returns the full file contents as a string.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to read',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): mixed
    {
        $path = $params['path'] ?? throw new RuntimeException('Path parameter is required');

        // Resolve absolute path
        $absolutePath = $this->resolvePath($path);

        // Check if file exists
        if (! file_exists($absolutePath)) {
            throw new RuntimeException("File not found: {$path}");
        }

        // Check if it's a file (not directory)
        if (! is_file($absolutePath)) {
            throw new RuntimeException("Path is not a file: {$path}");
        }

        // Check file size
        $fileSize = filesize($absolutePath);
        if ($fileSize === false) {
            throw new RuntimeException("Cannot determine file size: {$path}");
        }

        if ($fileSize > $this->maxSize) {
            throw new RuntimeException("File too large: {$fileSize} bytes (max: {$this->maxSize})");
        }

        // Read file
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $contents;
    }

    private function resolvePath(string $path): string
    {
        // If baseDir is set, resolve relative to it
        if ($this->baseDir !== null) {
            $fullPath = $this->baseDir.DIRECTORY_SEPARATOR.$path;
            $realBaseDir = realpath($this->baseDir);

            if ($realBaseDir === false) {
                throw new RuntimeException('Invalid base directory');
            }
        } else {
            $fullPath = $path;
            $realBaseDir = null;
        }

        // Normalize the path first (before checking if it exists)
        $normalizedPath = $this->normalizePath($fullPath);

        // If baseDir is set, check for path traversal before checking existence
        if ($realBaseDir !== null && ! str_starts_with($normalizedPath, $realBaseDir)) {
            throw new RuntimeException("Path traversal detected: {$path}");
        }

        // Now check if file exists
        if (! file_exists($normalizedPath)) {
            throw new RuntimeException("File not found: {$path}");
        }

        return $normalizedPath;
    }

    private function normalizePath(string $path): string
    {
        // Try realpath first
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        // If file doesn't exist, normalize manually
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        $result = DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $normalized);

        return $result;
    }
}
