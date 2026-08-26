<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Exceptions\ConfigurationException;
use Pagent\Exceptions\RuntimeException;

/**
 * Read file contents.
 *
 * By default reads are confined to the current working directory. Pass an
 * explicit baseDir to confine to a different directory, or allowAnyPath: true
 * to explicitly allow reading anywhere on the filesystem.
 */
final class FileRead extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private ?int $maxSize = null,
        bool $allowAnyPath = false,
    ) {
        $this->maxSize = $maxSize ?? 10 * 1024 * 1024; // 10MB default
        $this->baseDir = self::resolveBaseDir($baseDir, $allowAnyPath);
    }

    /**
     * Shared baseDir policy for the file tools: default to getcwd(),
     * allow unrestricted access only via an explicit allowAnyPath: true.
     */
    public static function resolveBaseDir(?string $baseDir, bool $allowAnyPath): ?string
    {
        if ($allowAnyPath) {
            if ($baseDir !== null) {
                throw new ConfigurationException(
                    'baseDir and allowAnyPath: true are mutually exclusive. '
                    .'Pass a baseDir to confine access, or allowAnyPath: true for unrestricted access.'
                );
            }

            return null;
        }

        if ($baseDir !== null) {
            return $baseDir;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new ConfigurationException(
                'Cannot determine the current working directory for the default baseDir. '
                .'Pass an explicit baseDir or allowAnyPath: true.'
            );
        }

        return $cwd;
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
        $path = $this->requiredString($params, 'path');

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
        if ($this->baseDir === null) {
            // allowAnyPath mode - no confinement
            $fullPath = $path;
            $realBaseDir = null;
        } else {
            $realBaseDir = realpath($this->baseDir);

            if ($realBaseDir === false) {
                throw new RuntimeException('Invalid base directory');
            }

            // Absolute paths are used as-is (still checked for containment below);
            // relative paths resolve against baseDir.
            $fullPath = str_starts_with($path, DIRECTORY_SEPARATOR)
                ? $path
                : $realBaseDir.DIRECTORY_SEPARATOR.$path;
        }

        // Normalize the path first (before checking if it exists)
        $normalizedPath = $this->normalizePath($fullPath);

        // If baseDir is set, check for path traversal before checking existence
        if ($realBaseDir !== null
            && $normalizedPath !== $realBaseDir
            && ! str_starts_with($normalizedPath, $realBaseDir.DIRECTORY_SEPARATOR)) {
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
