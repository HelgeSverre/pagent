<?php

declare(strict_types=1);

namespace Pagent\Memory\Adapters;

use InvalidArgumentException;
use Pagent\Contracts\Memory;
use PDO;
use PDOException;
use RuntimeException;

use function array_slice;
use function count;
use function dirname;
use function is_dir;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function mkdir;

final class SqliteAdapter implements Memory
{
    private PDO $pdo;

    public function __construct(array $config = [])
    {
        $path = $config['path'] ?? 'storage/sessions.db';

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            $this->pdo = new PDO('sqlite:'.$path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->createSchema();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to initialize SQLite database: '.$e->getMessage());
        }
    }

    public function load(string $sessionId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT messages FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return [];
            }

            $messages = json_decode($row['messages'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode messages JSON: '.json_last_error_msg());
            }

            return $messages ?? [];
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to load session: '.$e->getMessage());
        }
    }

    public function save(string $sessionId, array $messages): void
    {
        $json = json_encode($messages);
        if ($json === false) {
            throw new RuntimeException('Failed to encode messages as JSON: '.json_last_error_msg());
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare('SELECT created_at FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $createdAt = $existing ? $existing['created_at'] : date('c');
            $updatedAt = date('c');

            $stmt = $this->pdo->prepare('
                INSERT OR REPLACE INTO sessions (session_id, messages, created_at, updated_at)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$sessionId, $json, $createdAt, $updatedAt]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Failed to save session: '.$e->getMessage());
        }
    }

    public function delete(string $sessionId): void
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to delete session: '.$e->getMessage());
        }
    }

    public function exists(string $sessionId): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);

            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to check session existence: '.$e->getMessage());
        }
    }

    public function prune(string $sessionId, int $maxMessages): array
    {
        if ($maxMessages < 1) {
            throw new InvalidArgumentException('maxMessages must be at least 1');
        }

        $messages = $this->load($sessionId);

        if (count($messages) <= $maxMessages) {
            return $messages;
        }

        $pruned = array_slice($messages, -$maxMessages);
        $this->save($sessionId, $pruned);

        return $pruned;
    }

    private function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS sessions (
                session_id TEXT PRIMARY KEY,
                messages TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_updated_at ON sessions(updated_at)');
    }
}
