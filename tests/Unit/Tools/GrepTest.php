<?php

declare(strict_types=1);

use Pagent\Tools\Grep;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/pagent_test_'.uniqid();
    mkdir($this->tempDir);
    mkdir($this->tempDir.'/src');

    file_put_contents($this->tempDir.'/test.php', "<?php\nfunction hello() {\n    echo 'Hello, World!';\n}\n");
    file_put_contents($this->tempDir.'/src/User.php', "<?php\nclass User {\n    public function hello() {}\n}\n");
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

test('grep finds text in files', function () {
    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'hello',
        'files' => '*.php',
    ]);

    expect($result['total_matches'])->toBeGreaterThan(0);
    expect($result['results'])->not->toBeEmpty();
    expect($result['results'][0])->toHaveKey('matches');
});

test('grep finds text recursively', function () {
    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'hello',
        'files' => '**/*.php',
    ]);

    expect($result['total_matches'])->toBeGreaterThanOrEqual(2);
    expect($result['files_searched'])->toBeGreaterThanOrEqual(2);
});

test('grep supports regex patterns', function () {
    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => '/function\s+\w+/',
        'files' => '*.php',
        'regex' => true,
    ]);

    expect($result['total_matches'])->toBeGreaterThan(0);
});

test('grep returns line numbers', function () {
    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'function hello',
        'files' => 'test.php',
    ]);

    expect($result['results'][0]['matches'][0])->toHaveKey('line');
    expect($result['results'][0]['matches'][0])->toHaveKey('content');
});

test('grep limits results', function () {
    $tool = new Grep(baseDir: $this->tempDir, maxResults: 1);
    $result = $tool->execute([
        'pattern' => 'hello',
        'files' => '**/*.php',
    ]);

    expect($result['total_matches'])->toBeLessThanOrEqual(1);
});

test('grep returns empty for no matches', function () {
    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'nonexistent_text_xyz',
        'files' => '*.php',
    ]);

    expect($result['results'])->toBeEmpty();
    expect($result['total_matches'])->toBe(0);
});

test('grep has correct metadata', function () {
    $tool = new Grep;

    expect($tool->name())->toBe('grep');
    expect($tool->description())->toContain('Search for text');
    expect($tool->parameters())->toHaveKey('properties');
});

// ========================================
// ERROR HANDLING & EDGE CASE TESTS
// ========================================

test('it handles invalid regex patterns gracefully', function () {
    file_put_contents($this->tempDir.'/test.txt', 'test content');

    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => '/[invalid(regex/',  // Malformed regex
        'files' => '*.txt',
        'regex' => true,
    ]);

    // Should not crash - invalid regex returns no matches due to @ suppression
    expect($result)->toHaveKey('results');
    expect($result['total_matches'])->toBe(0);
});

test('it handles binary files safely', function () {
    // Create a binary file
    file_put_contents($this->tempDir.'/binary.dat', pack('C*', 0, 1, 2, 3, 255, 254));

    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'test',
        'files' => '*.dat',
    ]);

    // Should complete without error
    expect($result)->toHaveKey('results');
});

test('it finds patterns in very long lines', function () {
    $longLine = str_repeat('x', 100000).'FINDME'.str_repeat('y', 100000);
    file_put_contents($this->tempDir.'/long.txt', $longLine);

    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'FINDME',
        'files' => 'long.txt',
    ]);

    expect($result['total_matches'])->toBe(1);
    expect($result['results'][0]['matches'][0]['content'])->toContain('FINDME');
});

test('it respects maxResults limit per file', function () {
    // Create file with many matches
    $content = str_repeat("match\n", 200);
    file_put_contents($this->tempDir.'/many.txt', $content);

    $tool = new Grep(baseDir: $this->tempDir, maxResults: 5);
    $result = $tool->execute([
        'pattern' => 'match',
        'files' => 'many.txt',
    ]);

    expect($result['total_matches'])->toBe(5);
    expect($result['truncated'])->toBeTrue();
});

test('it stops searching after reaching maxResults', function () {
    // Create multiple files with matches
    file_put_contents($this->tempDir.'/file1.txt', str_repeat("match\n", 10));
    file_put_contents($this->tempDir.'/file2.txt', str_repeat("match\n", 10));
    file_put_contents($this->tempDir.'/file3.txt', str_repeat("match\n", 10));

    $tool = new Grep(baseDir: $this->tempDir, maxResults: 5);
    $result = $tool->execute([
        'pattern' => 'match',
        'files' => '*.txt',
    ]);

    // Should stop at 5 matches total
    expect($result['total_matches'])->toBe(5);
    expect($result['truncated'])->toBeTrue();
    // Should not have searched all files
    expect($result['files_searched'])->toBeGreaterThanOrEqual(1);
});

test('it skips unreadable files without crashing', function () {
    file_put_contents($this->tempDir.'/readable.txt', 'content');
    file_put_contents($this->tempDir.'/unreadable.txt', 'secret');
    chmod($this->tempDir.'/unreadable.txt', 0000);

    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'content',
        'files' => '*.txt',
    ]);

    // Should find matches in readable file, skip unreadable
    expect($result['files_searched'])->toBeGreaterThan(0);

    // Cleanup
    chmod($this->tempDir.'/unreadable.txt', 0644);
})->skip('Permission tests can be environment-specific');

test('it handles empty files gracefully', function () {
    file_put_contents($this->tempDir.'/empty.txt', '');

    $tool = new Grep(baseDir: $this->tempDir);
    $result = $tool->execute([
        'pattern' => 'test',
        'files' => 'empty.txt',
    ]);

    expect($result['results'])->toBeEmpty();
    expect($result['total_matches'])->toBe(0);
    expect($result['files_searched'])->toBe(1);
});
