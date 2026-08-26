<?php

declare(strict_types=1);

namespace Pagent\Streaming;

/**
 * Represents a single chunk of streaming data from an LLM provider
 */
final class StreamChunk
{
    public function __construct(
        public readonly string $type,
        public readonly string $content,
        public readonly ?array $delta = null,
        public readonly ?array $metadata = null,
        public readonly bool $isComplete = false,
    ) {}

    /**
     * Check if this is a text chunk
     */
    public function isText(): bool
    {
        return $this->type === 'text';
    }

    /**
     * Check if this is a thinking (extended reasoning) chunk
     */
    public function isThinking(): bool
    {
        return $this->type === 'thinking_delta';
    }

    /**
     * Check if this is a tool call chunk
     */
    public function isToolCall(): bool
    {
        return $this->type === 'tool_call'
            || $this->type === 'tool_call_done'
            || $this->type === 'input_json_delta';
    }

    /**
     * Check if this is the start of the stream
     */
    public function isStart(): bool
    {
        return $this->type === 'start';
    }

    /**
     * Check if this is the end of the stream
     */
    public function isEnd(): bool
    {
        return $this->type === 'done';
    }

    /**
     * Check if this is an error chunk
     */
    public function isError(): bool
    {
        return $this->type === 'error';
    }

    /**
     * Get the text content from this chunk
     */
    public function getText(): string
    {
        return $this->content;
    }

    /**
     * Get metadata value by key
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Create a text chunk
     */
    public static function text(string $content, ?array $metadata = null): self
    {
        return new self(
            type: 'text',
            content: $content,
            metadata: $metadata,
        );
    }

    /**
     * Create a start chunk
     */
    public static function start(?array $metadata = null): self
    {
        return new self(
            type: 'start',
            content: '',
            metadata: $metadata,
        );
    }

    /**
     * Create an end chunk
     */
    public static function end(?array $metadata = null): self
    {
        return new self(
            type: 'done',
            content: '',
            metadata: $metadata,
            isComplete: true,
        );
    }

    /**
     * Create an error chunk
     */
    public static function error(string $message, ?array $metadata = null): self
    {
        return new self(
            type: 'error',
            content: $message,
            metadata: $metadata,
            isComplete: true,
        );
    }
}
