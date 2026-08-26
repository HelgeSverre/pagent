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
    $tool = new PdfReader(allowAnyPath: true);

    expect(fn () => $tool->execute(['path' => '/nonexistent.pdf']))
        ->toThrow(RuntimeException::class, 'File not found');
});

test('pdf reader prevents path traversal', function () {
    $tempDir = sys_get_temp_dir();
    $tool = new PdfReader(baseDir: $tempDir);

    expect(fn () => $tool->execute(['path' => '../etc/passwd']))
        ->toThrow(RuntimeException::class, 'Path traversal detected');
});

// ========================================
// EXTERNAL COMMAND & ERROR HANDLING TESTS
// ========================================

test('it validates pdftotext is installed', function () {
    $tool = new PdfReader(pdftotextPath: '/nonexistent/pdftotext', allowAnyPath: true);

    $tempDir = sys_get_temp_dir();
    $tempFile = $tempDir.'/fake.pdf';
    touch($tempFile);

    expect(fn () => $tool->execute(['path' => $tempFile]))
        ->toThrow(RuntimeException::class, 'pdftotext not found');

    unlink($tempFile);
});

test('it throws on corrupted PDF files', function () {
    // Check if pdftotext is installed
    exec('pdftotext -v 2>&1', $output, $returnCode);
    if ($returnCode !== 0 && $returnCode !== 99) {
        $this->markTestSkipped('pdftotext not installed');
    }

    $tempFile = sys_get_temp_dir().'/corrupted.pdf';
    file_put_contents($tempFile, 'Not a real PDF content');

    $tool = new PdfReader(allowAnyPath: true);

    expect(fn () => $tool->execute(['path' => $tempFile]))
        ->toThrow(RuntimeException::class);

    unlink($tempFile);
});

test('it enforces file size limits', function () {
    $tempFile = sys_get_temp_dir().'/large.pdf';
    file_put_contents($tempFile, str_repeat('x', 2000));

    $tool = new PdfReader(maxSize: 1000, allowAnyPath: true);

    expect(fn () => $tool->execute(['path' => $tempFile]))
        ->toThrow(RuntimeException::class, 'File too large');

    unlink($tempFile);
});

test('it accepts files within maxSize', function () {
    $tempFile = sys_get_temp_dir().'/small.pdf';
    file_put_contents($tempFile, str_repeat('x', 500));

    $tool = new PdfReader(maxSize: 1000, allowAnyPath: true);

    // Will fail on pdftotext, but should pass size check
    try {
        $tool->execute(['path' => $tempFile]);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('File too large');
    }

    unlink($tempFile);
});

test('it uses custom pdftotext path when provided', function () {
    $tool = new PdfReader(pdftotextPath: '/custom/path/pdftotext');

    // Verify constructor accepts custom path
    expect($tool)->toBeInstanceOf(PdfReader::class);
});

test('it returns extracted text with correct metadata', function () {
    // Check if pdftotext is installed
    exec('pdftotext -v 2>&1', $output, $returnCode);
    if ($returnCode !== 0 && $returnCode !== 99) {
        $this->markTestSkipped('pdftotext not installed');
    }

    $pdfPath = __DIR__.'/../../Fixtures/sample.pdf';
    if (! file_exists($pdfPath)) {
        $this->markTestSkipped('Sample PDF fixture not found');
    }

    $tool = new PdfReader(allowAnyPath: true);
    $result = $tool->execute(['path' => $pdfPath]);

    expect($result)->toBeArray()
        ->toHaveKeys(['text', 'length', 'file'])
        ->and($result['text'])->toContain('Sample PDF')
        ->and($result['length'])->toBeGreaterThan(0)
        ->and($result['file'])->toBe($pdfPath);
});
