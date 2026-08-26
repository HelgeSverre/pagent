<?php

declare(strict_types=1);

namespace Pagent\Tools;

use Pagent\Exceptions\RuntimeException;

/**
 * Write content to files.
 *
 * By default writes are confined to the current working directory. Pass an
 * explicit baseDir to confine to a different directory, or allowAnyPath: true
 * to explicitly allow writing anywhere on the filesystem.
 */
final class FileWrite extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private ?int $maxSize = null,
        bool $allowAnyPath = false,
    ) {
        $this->maxSize = $maxSize ?? 10 * 1024 * 1024; // 10MB default
        $this->baseDir = FileRead::resolveBaseDir($baseDir, $allowAnyPath);
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

            // Absolute paths are used as-is (still checked for containment below);
            // relative paths resolve against baseDir.
            $fullPath = str_starts_with($path, DIRECTORY_SEPARATOR)
                ? $path
                : $realBaseDir.DIRECTORY_SEPARATOR.$path;

            // Normalize the lexical path first, then resolve the deepest
            // existing ancestor. Resolving only dirname($path) is insufficient:
            // base/link/new/file can escape through base/link when `new` does
            // not exist yet and link points outside the base directory.
            $normalizedPath = $this->normalizePath($fullPath);
            $normalizedPath = $this->resolveExistingAncestor($normalizedPath);

            // Check for path traversal
            if ($normalizedPath !== $realBaseDir
                && ! str_starts_with($normalizedPath, $realBaseDir.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Path traversal detected: {$path}");
            }

            return $normalizedPath;
        }

        // No baseDir (allowAnyPath) - normalize absolute path
        return $this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        // Resolve symlinks when the target already exists
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

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

        $result = DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $normalized);

        return $result;
    }

    /**
     * Resolve the closest existing ancestor and append the not-yet-created
     * suffix. This exposes symlink escapes even when several trailing path
     * components do not exist yet.
     */
    private function resolveExistingAncestor(string $path): string
    {
        $ancestor = $path;
        $suffix = [];

        while (! file_exists($ancestor) && ! is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }

            array_unshift($suffix, basename($ancestor));
            $ancestor = $parent;
        }

        $resolvedAncestor = realpath($ancestor);
        if ($resolvedAncestor === false) {
            throw new RuntimeException("Cannot resolve path ancestor: {$ancestor}");
        }

        return $suffix === []
            ? $resolvedAncestor
            : $resolvedAncestor.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $suffix);
    }
}
