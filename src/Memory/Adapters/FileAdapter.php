<?php

declare(strict_types=1);

namespace Pagent\Memory\Adapters;

use InvalidArgumentException;
use Pagent\Contracts\Memory;
use RuntimeException;

use function array_slice;
use function chmod;
use function count;
use function date;
use function fclose;
use function fflush;
use function file_exists;
use function file_get_contents;
use function flock;
use function fopen;
use function fwrite;
use function hash;
use function is_array;
use function is_dir;
use function is_writable;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function mkdir;
use function rename;
use function sprintf;
use function strlen;
use function substr;
use function tempnam;
use function unlink;

final class FileAdapter implements Memory
{
    private string $directory;

    private int $permissions;

    private int $filePermissions;

    public function __construct(array $config = [])
    {
        // `path` is the documented option. Keep `directory` as a supported alias
        // for existing applications; the canonical option wins when both exist.
        $directory = $config['path'] ?? $config['directory'] ?? 'storage/sessions';
        if (! is_string($directory) || $directory === '') {
            throw new InvalidArgumentException('File memory path must be a non-empty string');
        }

        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
        $this->permissions = $config['permissions'] ?? 0755;
        $this->filePermissions = $config['file_permissions'] ?? 0600;
        $this->ensureDirectoryExists();
    }

    public function load(string $sessionId): array
    {
        $filepath = $this->existingFilepath($sessionId);
        if ($filepath === null) {
            return [];
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new RuntimeException("Failed to read session file: {$filepath}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode session JSON: '.json_last_error_msg());
        }

        if (! is_array($data) || ! isset($data['messages']) || ! is_array($data['messages'])) {
            throw new RuntimeException('Session JSON must contain a messages array');
        }

        return $data['messages'];
    }

    public function save(string $sessionId, array $messages): void
    {
        $data = [
            'session_id' => $sessionId,
            'messages' => $messages,
            'updated_at' => date('c'),
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode messages as JSON: '.json_last_error_msg());
        }

        $filepath = $this->getFilepath($sessionId);
        $this->writeAtomically($filepath, $json);

        // Migrate a legacy readable filename once it has been safely persisted
        // under the opaque filename.
        $legacy = $this->legacyFilepath($sessionId);
        if ($legacy !== null && $legacy !== $filepath && file_exists($legacy)) {
            // The opaque file is already the committed source of truth. Legacy
            // cleanup must not turn a successful atomic save into an apparent
            // failure and make the Agent roll back only its in-memory state.
            @unlink($legacy);
        }
    }

    public function delete(string $sessionId): void
    {
        foreach (array_filter([$this->getFilepath($sessionId), $this->legacyFilepath($sessionId)]) as $filepath) {
            if (file_exists($filepath) && ! unlink($filepath)) {
                throw new RuntimeException("Failed to delete session file: {$filepath}");
            }
        }
    }

    public function exists(string $sessionId): bool
    {
        return $this->existingFilepath($sessionId) !== null;
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

    private function ensureDirectoryExists(): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, $this->permissions, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Failed to create storage directory: {$this->directory}");
        }

        if (! is_writable($this->directory)) {
            throw new RuntimeException("Storage directory is not writable: {$this->directory}");
        }
    }

    private function writeAtomically(string $filepath, string $contents): void
    {
        $temporary = tempnam($this->directory, '.pagent-session-');
        if ($temporary === false) {
            throw new RuntimeException("Failed to create temporary session file in: {$this->directory}");
        }

        $handle = null;

        try {
            $handle = fopen($temporary, 'wb');
            if ($handle === false || ! flock($handle, LOCK_EX)) {
                throw new RuntimeException("Failed to lock temporary session file: {$temporary}");
            }

            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Failed to write temporary session file: {$temporary}");
                }
                $offset += $written;
            }

            if (! fflush($handle)) {
                throw new RuntimeException("Failed to flush temporary session file: {$temporary}");
            }

            // fsync() is available on all supported PHP versions, but retaining
            // the guard keeps the adapter usable in constrained runtimes.
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException("Failed to sync temporary session file: {$temporary}");
            }

            if (! flock($handle, LOCK_UN)) {
                throw new RuntimeException("Failed to unlock temporary session file: {$temporary}");
            }
            fclose($handle);
            $handle = null;

            if (! chmod($temporary, $this->filePermissions)) {
                throw new RuntimeException("Failed to set session file permissions: {$temporary}");
            }

            if (! rename($temporary, $filepath)) {
                throw new RuntimeException("Failed to replace session file: {$filepath}");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function existingFilepath(string $sessionId): ?string
    {
        $filepath = $this->getFilepath($sessionId);
        if (file_exists($filepath)) {
            return $filepath;
        }

        $legacy = $this->legacyFilepath($sessionId);

        return $legacy !== null && file_exists($legacy) ? $legacy : null;
    }

    private function getFilepath(string $sessionId): string
    {
        // Opaque, fixed-width names make every session identifier safe on every
        // supported filesystem and prevent traversal through the identifier.
        return sprintf('%s/%s.json', $this->directory, hash('sha256', $sessionId));
    }

    private function legacyFilepath(string $sessionId): ?string
    {
        // Only inspect legacy names that cannot escape the configured directory.
        if ($sessionId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $sessionId) !== 1) {
            return null;
        }

        return sprintf('%s/%s.json', $this->directory, $sessionId);
    }
}
