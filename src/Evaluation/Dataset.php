<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use RuntimeException;

final class Dataset
{
    /** @var array<int, array{input: string, expected?: string, metadata?: array}> */
    private array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function fromJson(string $path): self
    {
        if ( ! file_exists($path)) {
            throw new RuntimeException("Dataset file not found: {$path}");
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException('Invalid JSON in dataset: ' . json_last_error_msg());
        }

        return new self($data);
    }

    public static function fromCsv(string $path, bool $hasHeader = true): self
    {
        if ( ! file_exists($path)) {
            throw new RuntimeException("Dataset file not found: {$path}");
        }

        $file = fopen($path, 'r');
        $items = [];
        $headers = null;

        while (($row = fgetcsv($file)) !== false) {
            if ($hasHeader && null === $headers) {
                $headers = $row;
                continue;
            }

            if ($hasHeader && $headers) {
                $items[] = array_combine($headers, $row);
            } else {
                $items[] = ['input' => $row[0], 'expected' => $row[1] ?? null];
            }
        }

        fclose($file);

        return new self($items);
    }

    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->items, $callback));
    }

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items));
    }
}
