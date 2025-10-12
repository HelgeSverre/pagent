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
