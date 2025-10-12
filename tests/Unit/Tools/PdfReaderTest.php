<?php

declare(strict_types=1);

use Pagent\Tools\PdfReader;

test('pdf reader has correct metadata', function () {
    $tool = new PdfReader;

    expect($tool->name())->toBe('pdf_reader');
    expect($tool->description())->toContain('Extract text');
    expect($tool->parameters())->toHaveKey('properties');
});

test('pdf reader throws when path missing', function () {
    $tool = new PdfReader;

    expect(fn () => $tool->execute([]))
        ->toThrow(RuntimeException::class, 'Path parameter is required');
});

test('pdf reader throws when file not found', function () {
    $tool = new PdfReader;

    expect(fn () => $tool->execute(['path' => '/nonexistent.pdf']))
        ->toThrow(RuntimeException::class, 'File not found');
});

test('pdf reader prevents path traversal', function () {
    $tempDir = sys_get_temp_dir();
    $tool = new PdfReader(baseDir: $tempDir);

    expect(fn () => $tool->execute(['path' => '../etc/passwd']))
        ->toThrow(RuntimeException::class, 'Path traversal detected');
});
