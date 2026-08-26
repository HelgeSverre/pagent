<?php

declare(strict_types=1);

use Pagent\Exceptions\ConfigurationException;
use Pagent\Tools\FileRead;

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

test('file read returns file contents', function () {
    $file = $this->tempDir.'/test.txt';
    file_put_contents($file, 'Hello, World!');

    $tool = new FileRead(allowAnyPath: true);
    $result = $tool->execute(['path' => $file]);

    expect($result)->toBe('Hello, World!');
});

test('file read with base directory', function () {
    file_put_contents($this->tempDir.'/test.txt', 'Content');

    $tool = new FileRead(baseDir: $this->tempDir);
    $result = $tool->execute(['path' => 'test.txt']);

    expect($result)->toBe('Content');
});

test('file read prevents directory traversal', function () {
    $tool = new FileRead(baseDir: $this->tempDir);

    expect(fn () => $tool->execute(['path' => '../etc/passwd']))
        ->toThrow(RuntimeException::class, 'Path traversal detected');
});

test('file read throws when file not found', function () {
    $tool = new FileRead(allowAnyPath: true);

    expect(fn () => $tool->execute(['path' => '/nonexistent/file.txt']))
        ->toThrow(RuntimeException::class, 'File not found');
});

test('file read throws when path is directory', function () {
    $tool = new FileRead(allowAnyPath: true);

    expect(fn () => $tool->execute(['path' => $this->tempDir]))
        ->toThrow(RuntimeException::class, 'Path is not a file');
});

test('file read defaults to confining reads to the current working directory', function () {
    $file = $this->tempDir.'/secret.txt';
    file_put_contents($file, 'secret');

    $tool = new FileRead;

    expect(fn () => $tool->execute(['path' => $file]))
        ->toThrow(RuntimeException::class, 'Path traversal detected');
});

test('file read rejects baseDir combined with allowAnyPath', function () {
    expect(fn () => new FileRead(baseDir: '/tmp', allowAnyPath: true))
        ->toThrow(ConfigurationException::class, 'mutually exclusive');
});

test('file read throws when file too large', function () {
    $file = $this->tempDir.'/large.txt';
    file_put_contents($file, str_repeat('x', 1000));

    $tool = new FileRead(maxSize: 100, allowAnyPath: true);

    expect(fn () => $tool->execute(['path' => $file]))
        ->toThrow(RuntimeException::class, 'File too large');
});

test('file read throws when path parameter missing', function () {
    $tool = new FileRead;

    expect(fn () => $tool->execute([]))
        ->toThrow(RuntimeException::class, 'Path parameter is required');
});

test('file read has correct metadata', function () {
    $tool = new FileRead;

    expect($tool->name())->toBe('file_read');
    expect($tool->description())->toContain('Read the contents');
    expect($tool->parameters())->toHaveKey('properties');
});
