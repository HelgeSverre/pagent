<?php

declare(strict_types=1);

use Pagent\Tools\Glob;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/pagent_test_'.uniqid();
    mkdir($this->tempDir);

    // Create test structure
    mkdir($this->tempDir.'/src');
    mkdir($this->tempDir.'/tests');
    mkdir($this->tempDir.'/src/Models');

    file_put_contents($this->tempDir.'/README.md', 'readme');
    file_put_contents($this->tempDir.'/src/User.php', '<?php');
    file_put_contents($this->tempDir.'/src/Models/Post.php', '<?php');
    file_put_contents($this->tempDir.'/tests/UserTest.php', '<?php');
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

test('glob finds files with simple pattern', function () {
    $tool = new Glob(baseDir: $this->tempDir);
    $result = $tool->execute(['pattern' => '*.md']);

    expect($result['matches'])->toContain('README.md');
    expect($result['count'])->toBe(1);
});

test('glob finds files with directory pattern', function () {
    $tool = new Glob(baseDir: $this->tempDir);
    $result = $tool->execute(['pattern' => 'src/*.php']);

    expect($result['matches'])->toContain('src/User.php');
    expect($result['count'])->toBe(1);
});

test('glob finds files recursively with **', function () {
    $tool = new Glob(baseDir: $this->tempDir);
    $result = $tool->execute(['pattern' => '**/*.php']);

    expect($result['matches'])->toContain('src/User.php');
    expect($result['matches'])->toContain('src/Models/Post.php');
    expect($result['matches'])->toContain('tests/UserTest.php');
    expect($result['count'])->toBeGreaterThanOrEqual(3);
});

test('glob limits results', function () {
    $tool = new Glob(baseDir: $this->tempDir, maxResults: 2);
    $result = $tool->execute(['pattern' => '**/*.php']);

    expect($result['count'])->toBe(2);
    expect($result['truncated'])->toBeTrue();
});

test('glob returns empty for no matches', function () {
    $tool = new Glob(baseDir: $this->tempDir);
    $result = $tool->execute(['pattern' => '*.nonexistent']);

    expect($result['matches'])->toBeEmpty();
    expect($result['count'])->toBe(0);
});

test('glob has correct metadata', function () {
    $tool = new Glob;

    expect($tool->name())->toBe('glob');
    expect($tool->description())->toContain('Find files');
    expect($tool->parameters())->toHaveKey('properties');
});
