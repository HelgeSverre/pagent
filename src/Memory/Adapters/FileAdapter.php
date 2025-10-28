<?php

declare(strict_types=1);

namespace Pagent\Memory\Adapters;

use InvalidArgumentException;
use Pagent\Contracts\Memory;
use RuntimeException;

use function array_slice;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_writable;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function mkdir;
use function sprintf;
use function unlink;

final class FileAdapter implements Memory
{
    private string $directory;

    private int $permissions;

    public function __construct(array $config = [])
    {
        $this->directory = $config['directory'] ?? 'storage/sessions';
        $this->permissions = $config['permissions'] ?? 0755;
        $this->ensureDirectoryExists();
    }

    public function load(string $sessionId): array
    {
        $filepath = $this->getFilepath($sessionId);
        if (! file_exists($filepath)) {
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

        return $data['messages'] ?? [];
    }

    public function save(string $sessionId, array $messages): void
    {
        $filepath = $this->getFilepath($sessionId);
        $data = [
            'session_id' => $sessionId,
            'messages' => $messages,
            'updated_at' => date('c'),
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode messages as JSON: '.json_last_error_msg());
        }
        $result = file_put_contents($filepath, $json, LOCK_EX);
        if ($result === false) {
            throw new RuntimeException("Failed to write session file: {$filepath}");
        }
    }

    public function delete(string $sessionId): void
    {
        $filepath = $this->getFilepath($sessionId);
        if (file_exists($filepath)) {
            $result = unlink($filepath);
            if ($result === false) {
                throw new RuntimeException("Failed to delete session file: {$filepath}");
            }
        }
    }

    public function exists(string $sessionId): bool
    {
        return file_exists($this->getFilepath($sessionId));
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
        if (! is_dir($this->directory)) {
            $result = mkdir($this->directory, $this->permissions, true);
            if ($result === false) {
                throw new RuntimeException("Failed to create storage directory: {$this->directory}");
            }
        }
        if (! is_writable($this->directory)) {
            throw new RuntimeException("Storage directory is not writable: {$this->directory}");
        }
    }

    private function getFilepath(string $sessionId): string
    {
        return sprintf('%s/%s.json', $this->directory, $sessionId);
    }
}
