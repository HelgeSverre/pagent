<?php

declare(strict_types=1);

use Pagent\Memory\Adapters\SqliteAdapter;

beforeEach(function (): void {
    $this->tmpDb = sys_get_temp_dir().'/pagent-test-'.uniqid().'.db';
});

afterEach(function (): void {
    if (isset($this->tmpDb) && file_exists($this->tmpDb)) {
        unlink($this->tmpDb);
        // Clean up WAL files
        if (file_exists($this->tmpDb.'-shm')) {
            unlink($this->tmpDb.'-shm');
        }
        if (file_exists($this->tmpDb.'-wal')) {
            unlink($this->tmpDb.'-wal');
        }
    }
});

it('auto-creates database on construction', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    expect(file_exists($this->tmpDb))->toBeTrue();
});

it('auto-creates directory for database', function (): void {
    $tmpDir = sys_get_temp_dir().'/pagent-test-'.uniqid();
    $dbPath = $tmpDir.'/sessions.db';

    $adapter = new SqliteAdapter(['path' => $dbPath]);

    expect(is_dir($tmpDir))->toBeTrue();
    expect(file_exists($dbPath))->toBeTrue();

    // Cleanup
    unlink($dbPath);
    if (file_exists($dbPath.'-shm')) {
        unlink($dbPath.'-shm');
    }
    if (file_exists($dbPath.'-wal')) {
        unlink($dbPath.'-wal');
    }
    rmdir($tmpDir);
});

it('creates schema on construction', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $pdo = new PDO('sqlite:'.$this->tmpDb);
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'");

    expect($result->fetch())->not->toBeFalse();
});

it('creates index on updated_at column', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $pdo = new PDO('sqlite:'.$this->tmpDb);
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_updated_at'");

    expect($result->fetch())->not->toBeFalse();
});

it('enables WAL mode', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $pdo = new PDO('sqlite:'.$this->tmpDb);
    $result = $pdo->query('PRAGMA journal_mode')->fetch();

    expect($result[0])->toBe('wal');
});

it('saves and loads messages', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $messages = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!'],
    ];

    $adapter->save('test-session', $messages);
    $loaded = $adapter->load('test-session');

    expect($loaded)->toBe($messages);
});

it('returns empty array when loading non-existent session', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $messages = $adapter->load('non-existent');

    expect($messages)->toBeArray()->toBeEmpty();
});

it('uses INSERT OR REPLACE for updates', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $messages1 = [['role' => 'user', 'content' => 'First']];
    $messages2 = [
        ['role' => 'user', 'content' => 'First'],
        ['role' => 'assistant', 'content' => 'Second'],
    ];

    $adapter->save('test-session', $messages1);
    $adapter->save('test-session', $messages2);

    $loaded = $adapter->load('test-session');

    expect($loaded)->toBe($messages2);
    expect($loaded)->toHaveCount(2);
});

it('preserves created_at on update', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $adapter->save('test-session', [['role' => 'user', 'content' => 'First']]);

    $pdo = new PDO('sqlite:'.$this->tmpDb);
    $stmt = $pdo->prepare('SELECT created_at FROM sessions WHERE session_id = ?');
    $stmt->execute(['test-session']);
    $originalCreatedAt = $stmt->fetchColumn();

    sleep(1);
    $adapter->save('test-session', [['role' => 'user', 'content' => 'Updated']]);

    $stmt->execute(['test-session']);
    $newCreatedAt = $stmt->fetchColumn();

    expect($newCreatedAt)->toBe($originalCreatedAt);
});

it('checks if session exists', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    expect($adapter->exists('test-session'))->toBeFalse();

    $adapter->save('test-session', [['role' => 'user', 'content' => 'test']]);

    expect($adapter->exists('test-session'))->toBeTrue();
});

it('deletes session', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $adapter->save('test-session', [['role' => 'user', 'content' => 'test']]);
    expect($adapter->exists('test-session'))->toBeTrue();

    $adapter->delete('test-session');

    expect($adapter->exists('test-session'))->toBeFalse();
});

it('does not throw when deleting non-existent session', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $adapter->delete('non-existent');

    expect(true)->toBeTrue();
});

it('prunes messages keeping most recent', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

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
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

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
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    expect(fn () => $adapter->prune('test-session', 0))
        ->toThrow(InvalidArgumentException::class, 'maxMessages must be at least 1');
});

it('uses transactions for save operations', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $messages = [['role' => 'user', 'content' => 'test']];
    $adapter->save('test-session', $messages);

    // Verify transaction was committed by loading
    expect($adapter->load('test-session'))->toBe($messages);
});

it('throws when JSON encoding fails', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    // Create invalid UTF-8 sequence that cannot be JSON encoded
    $messages = [['role' => 'user', 'content' => "\xB1\x31"]];

    expect(fn () => $adapter->save('test-session', $messages))
        ->toThrow(RuntimeException::class, 'Failed to encode messages as JSON');
});

it('throws when JSON decoding fails', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $pdo = new PDO('sqlite:'.$this->tmpDb);
    $pdo->prepare('INSERT INTO sessions (session_id, messages, created_at, updated_at) VALUES (?, ?, ?, ?)')
        ->execute(['test-session', '{invalid json}', date('c'), date('c')]);

    expect(fn () => $adapter->load('test-session'))
        ->toThrow(RuntimeException::class, 'Failed to decode messages JSON');
});

it('handles multiple sessions independently', function (): void {
    $adapter = new SqliteAdapter(['path' => $this->tmpDb]);

    $session1Messages = [['role' => 'user', 'content' => 'Session 1']];
    $session2Messages = [['role' => 'user', 'content' => 'Session 2']];

    $adapter->save('session-1', $session1Messages);
    $adapter->save('session-2', $session2Messages);

    expect($adapter->load('session-1'))->toBe($session1Messages);
    expect($adapter->load('session-2'))->toBe($session2Messages);
    expect($adapter->exists('session-1'))->toBeTrue();
    expect($adapter->exists('session-2'))->toBeTrue();
});
