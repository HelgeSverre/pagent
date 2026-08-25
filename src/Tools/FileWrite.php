<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;

final class FileWrite extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private ?int $maxSize = null,
    ) {
        $this->maxSize = $maxSize ?? 10 * 1024 * 1024; // 10MB default
    }

    public function name(): string
    {
        return 'file_write';
    }

    public function description(): string
    {
        return 'Write content to a file. Creates the file if it doesn\'t exist, overwrites if it does.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to write',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content to write to the file',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $params): mixed
    {
        $path = $this->requiredString($params, 'path');
        $content = $this->requiredString($params, 'content');

        // Check content size
        $contentSize = strlen($content);
        if ($contentSize > $this->maxSize) {
            throw new RuntimeException("Content too large: {$contentSize} bytes (max: {$this->maxSize})");
        }

        // Resolve and validate path
        $absolutePath = $this->resolvePath($path);

        // Create directory if it doesn't exist
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            if (! mkdir($dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        // Write file
        $result = file_put_contents($absolutePath, $content);
        if ($result === false) {
            throw new RuntimeException("Failed to write file: {$path}");
        }

        return [
            'path' => $absolutePath,
            'bytes_written' => $result,
        ];
    }

    private function resolvePath(string $path): string
    {
        // If baseDir is set, resolve relative to it
        if ($this->baseDir !== null) {
            $realBaseDir = realpath($this->baseDir);

            if ($realBaseDir === false) {
                throw new RuntimeException('Invalid base directory');
            }

            // Build full path from base + relative path
            $fullPath = $realBaseDir.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);

            // Normalize it
            $normalizedPath = $this->normalizePath($fullPath, exists: false);

            // Check for path traversal
            if (! str_starts_with($normalizedPath, $realBaseDir)) {
                throw new RuntimeException("Path traversal detected: {$path}");
            }

            return $normalizedPath;
        }

        // No baseDir - normalize absolute path
        return $this->normalizePath($path, exists: false);
    }

    private function normalizePath(string $path, bool $exists = true): string
    {
        // Manual normalization (works for non-existent paths too)
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

        return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $normalized);
    }
}
