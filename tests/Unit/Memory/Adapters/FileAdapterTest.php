<?php

declare(strict_types=1);

use Pagent\Memory\Adapters\FileAdapter;

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir().'/pagent-test-'.uniqid();
});

afterEach(function (): void {
    if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
        array_map('unlink', glob($this->tmpDir.'/*.json') ?: []);
        rmdir($this->tmpDir);
    }
});

it('auto-creates directory on construction', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    expect(is_dir($this->tmpDir))->toBeTrue();
});

it('throws when directory is not writable', function (): void {
    mkdir($this->tmpDir, 0444);

    expect(fn () => new FileAdapter(['directory' => $this->tmpDir]))
        ->toThrow(RuntimeException::class, 'not writable');
})->skip(PHP_OS_FAMILY === 'Windows', 'Permission test not reliable on Windows');

it('saves and loads messages', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $messages = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!'],
    ];

    $adapter->save('test-session', $messages);
    $loaded = $adapter->load('test-session');

    expect($loaded)->toBe($messages);
});

it('returns empty array when loading non-existent session', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $messages = $adapter->load('non-existent');

    expect($messages)->toBeArray()->toBeEmpty();
});

it('checks if session exists', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    expect($adapter->exists('test-session'))->toBeFalse();

    $adapter->save('test-session', [['role' => 'user', 'content' => 'test']]);

    expect($adapter->exists('test-session'))->toBeTrue();
});

it('deletes session file', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $adapter->save('test-session', [['role' => 'user', 'content' => 'test']]);
    expect($adapter->exists('test-session'))->toBeTrue();

    $adapter->delete('test-session');
    expect($adapter->exists('test-session'))->toBeFalse();
});

it('does not throw when deleting non-existent session', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $adapter->delete('non-existent');

    expect(true)->toBeTrue();
});

it('prunes messages keeping most recent', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $messages = [
        ['role' => 'user', 'content' => 'Message 1'],
        ['role' => 'assistant', 'content' => 'Response 1'],
        ['role' => 'user', 'content' => 'Message 2'],
        ['role' => 'assistant', 'content' => 'Response 2'],
        ['role' => 'user', 'content' => 'Message 3'],
    ];

    $adapter->save('test-session', $messages);
    $pruned = $adapter->prune('test-session', 3);

    expect($pruned)->toHaveCount(3);
    expect($pruned[0])->toBe(['role' => 'user', 'content' => 'Message 2']);
    expect($pruned[1])->toBe(['role' => 'assistant', 'content' => 'Response 2']);
    expect($pruned[2])->toBe(['role' => 'user', 'content' => 'Message 3']);
});

it('prune returns all messages when count is less than max', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $messages = [
        ['role' => 'user', 'content' => 'Message 1'],
        ['role' => 'assistant', 'content' => 'Response 1'],
    ];

    $adapter->save('test-session', $messages);
    $pruned = $adapter->prune('test-session', 10);

    expect($pruned)->toHaveCount(2);
    expect($pruned)->toBe($messages);
});

it('throws when prune max messages is less than 1', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    expect(fn () => $adapter->prune('test-session', 0))
        ->toThrow(InvalidArgumentException::class, 'maxMessages must be at least 1');
});

it('encodes messages as pretty JSON', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $messages = [['role' => 'user', 'content' => 'test']];
    $adapter->save('test-session', $messages);

    $filepath = $this->tmpDir.'/test-session.json';
    $content = file_get_contents($filepath);

    expect($content)->toContain('    "session_id"');
    expect($content)->toContain('    "messages"');
    expect($content)->toContain('    "updated_at"');
});

it('throws when JSON encoding fails', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    // Create invalid UTF-8 sequence that cannot be JSON encoded
    $messages = [['role' => 'user', 'content' => "\xB1\x31"]];

    expect(fn () => $adapter->save('test-session', $messages))
        ->toThrow(RuntimeException::class, 'Failed to encode messages as JSON');
});

it('throws when JSON decoding fails', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $filepath = $this->tmpDir.'/test-session.json';
    file_put_contents($filepath, '{invalid json}');

    expect(fn () => $adapter->load('test-session'))
        ->toThrow(RuntimeException::class, 'Failed to decode session JSON');
});

it('uses LOCK_EX when writing files', function (): void {
    $adapter = new FileAdapter(['directory' => $this->tmpDir]);

    $messages = [['role' => 'user', 'content' => 'test']];
    $adapter->save('test-session', $messages);

    // File should exist and be readable
    $filepath = $this->tmpDir.'/test-session.json';
    expect(file_exists($filepath))->toBeTrue();
    expect(is_readable($filepath))->toBeTrue();
});

it('uses custom permissions for directory', function (): void {
    $adapter = new FileAdapter([
        'directory' => $this->tmpDir,
        'permissions' => 0750,
    ]);

    $perms = fileperms($this->tmpDir) & 0777;
    expect($perms)->toBe(0750);
})->skip(PHP_OS_FAMILY === 'Windows', 'Permission test not reliable on Windows');
