<?php

declare(strict_types=1);

namespace Pagent\Http;

use UnexpectedValueException;

final readonly class HttpResponse
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $info
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public array $info = [],
    ) {}

    public function json(): array
    {
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('JSON response did not decode to an array.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * @return array<string, float|int>
     */
    public function timing(): array
    {
        return [
            'namelookup' => $this->infoFloat('namelookup_time'),
            'connect' => $this->infoFloat('connect_time'),
            'starttransfer' => $this->infoFloat('starttransfer_time'),
            'total' => $this->infoFloat('total_time'),
        ];
    }

    private function infoFloat(string $key): float
    {
        $value = $this->info[$key] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
