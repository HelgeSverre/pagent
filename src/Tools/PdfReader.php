<?php

declare(strict_types=1);

namespace Pagent\Tools;

use RuntimeException;

final class PdfReader extends Tool
{
    public function __construct(
        private ?string $baseDir = null,
        private ?int $maxSize = null,
        private string $pdftotextPath = 'pdftotext',
    ) {
        $this->maxSize = $maxSize ?? 50 * 1024 * 1024; // 50MB default
    }

    public function name(): string
    {
        return 'pdf_reader';
    }

    public function description(): string
    {
        return 'Extract text content from PDF files using pdftotext. Requires pdftotext to be installed.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the PDF file',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): mixed
    {
        $path = $this->requiredString($params, 'path');

        // Resolve path
        $absolutePath = $this->resolvePath($path);

        // Check if file exists
        if (! file_exists($absolutePath)) {
            throw new RuntimeException("File not found: {$path}");
        }

        // Check file size
        $fileSize = filesize($absolutePath);
        if ($fileSize === false) {
            throw new RuntimeException("Cannot determine file size: {$path}");
        }

        if ($fileSize > $this->maxSize) {
            throw new RuntimeException("File too large: {$fileSize} bytes (max: {$this->maxSize})");
        }

        // Check if pdftotext is available
        $checkCommand = sprintf('%s -v 2>&1', escapeshellcmd($this->pdftotextPath));
        exec($checkCommand, $output, $returnCode);

        if ($returnCode !== 0 && $returnCode !== 99) { // 99 is success for some versions
            throw new RuntimeException('pdftotext not found. Please install poppler-utils.');
        }

        // Extract text using pdftotext
        $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_');

        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellcmd($this->pdftotextPath),
            escapeshellarg($absolutePath),
            escapeshellarg($tempOutput)
        );

        exec($command, $execOutput, $exitCode);

        if ($exitCode !== 0) {
            @unlink($tempOutput);
            throw new RuntimeException('Failed to extract text from PDF: '.implode("\n", $execOutput));
        }

        // Read extracted text
        $text = file_get_contents($tempOutput);
        @unlink($tempOutput);

        if ($text === false) {
            throw new RuntimeException('Failed to read extracted text');
        }

        return [
            'text' => $text,
            'length' => strlen($text),
            'file' => $absolutePath,
        ];
    }

    private function resolvePath(string $path): string
    {
        if ($this->baseDir !== null) {
            $realBaseDir = realpath($this->baseDir);
            if ($realBaseDir === false) {
                throw new RuntimeException('Invalid base directory');
            }

            $fullPath = $realBaseDir.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
            $normalizedPath = $this->normalizePath($fullPath);

            if (! str_starts_with($normalizedPath, $realBaseDir)) {
                throw new RuntimeException("Path traversal detected: {$path}");
            }

            return $normalizedPath;
        }

        return $path;
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

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
