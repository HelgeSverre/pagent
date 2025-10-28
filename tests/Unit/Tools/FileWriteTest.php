<?php

declare(strict_types=1);

use Pagent\Tools\FileWrite;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/pagent_test_'.uniqid();
    mkdir($this->tempDir);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->tempDir);
    }
});

test('file write creates new file', function () {
    $tool = new FileWrite(baseDir: $this->tempDir);
    $result = $tool->execute([
        'path' => 'test.txt',
        'content' => 'Hello, World!',
    ]);

    expect($result)->toHaveKey('bytes_written');
    expect($result['bytes_written'])->toBe(13);
    expect(file_get_contents($this->tempDir.'/test.txt'))->toBe('Hello, World!');
});

test('file write overwrites existing file', function () {
    $file = $this->tempDir.'/test.txt';
    file_put_contents($file, 'Old content');

    $tool = new FileWrite(baseDir: $this->tempDir);
    $tool->execute([
        'path' => 'test.txt',
        'content' => 'New content',
    ]);

    expect(file_get_contents($file))->toBe('New content');
});

test('file write creates nested directories', function () {
    $tool = new FileWrite(baseDir: $this->tempDir);
    $tool->execute([
        'path' => 'nested/dir/file.txt',
        'content' => 'Content',
    ]);

    expect(file_exists($this->tempDir.'/nested/dir/file.txt'))->toBeTrue();
    expect(file_get_contents($this->tempDir.'/nested/dir/file.txt'))->toBe('Content');
});

test('file write prevents path traversal', function () {
    $tool = new FileWrite(baseDir: $this->tempDir);

    expect(fn () => $tool->execute([
        'path' => '../outside.txt',
        'content' => 'Bad',
    ]))->toThrow(RuntimeException::class, 'Path traversal detected');
});

test('file write throws when content too large', function () {
    $tool = new FileWrite(baseDir: $this->tempDir, maxSize: 100);

    expect(fn () => $tool->execute([
        'path' => 'large.txt',
        'content' => str_repeat('x', 1000),
    ]))->toThrow(RuntimeException::class, 'Content too large');
});

test('file write throws when path missing', function () {
    $tool = new FileWrite;

    expect(fn () => $tool->execute(['content' => 'test']))
        ->toThrow(RuntimeException::class, 'Path parameter is required');
});

test('file write throws when content missing', function () {
    $tool = new FileWrite;

    expect(fn () => $tool->execute(['path' => 'test.txt']))
        ->toThrow(RuntimeException::class, 'Content parameter is required');
});

test('file write has correct metadata', function () {
    $tool = new FileWrite;

    expect($tool->name())->toBe('file_write');
    expect($tool->description())->toContain('Write content');
    expect($tool->parameters())->toHaveKey('properties');
});
