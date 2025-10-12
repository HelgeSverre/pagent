<?php

declare(strict_types=1);

namespace Pagent\Evaluation;

use RuntimeException;

use function array_combine;
use function array_filter;
use function array_map;
use function count;
use function fclose;
use function fgetcsv;
use function file_exists;
use function file_get_contents;
use function fopen;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;

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
        if (! file_exists($path)) {
            throw new RuntimeException("Dataset file not found: {$path}");
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in dataset: '.json_last_error_msg());
        }

        return new self($data);
    }

    public static function fromCsv(string $path, bool $hasHeader = true): self
    {
        if (! file_exists($path)) {
            throw new RuntimeException("Dataset file not found: {$path}");
        }

        $file = fopen($path, 'r');
        $items = [];
        $headers = null;

        while (($row = fgetcsv($file)) !== false) {
            if ($hasHeader && $headers === null) {
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
