<?php

declare(strict_types=1);

namespace Pagent\Workflow;

final readonly class StepResult
{
    public function __construct(
        public string $name,
        public mixed $output,
        public mixed $input,
        public string $agent,
        public StepMetadata $meta,
    ) {}

    public function json(): array
    {
        if (is_string($this->output)) {
            $decoded = json_decode($this->output, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($this->output)) {
            return $this->output;
        }

        return [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->json();

        return $data[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'output' => $this->output,
            'input' => $this->input,
            'agent' => $this->agent,
            'metadata' => $this->meta->toArray(),
        ];
    }
}
