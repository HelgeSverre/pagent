<?php

declare(strict_types=1);

namespace Pagent\Memory;

use InvalidArgumentException;

use function count;
use function is_array;
use function is_string;
use function strlen;

final class ContextManager
{
    private const CHARS_PER_TOKEN = 4;

    private const VALID_STRATEGIES = ['oldest', 'sliding'];

    public function __construct(
        private int $maxTokens = 4000,
        private string $strategy = 'oldest',
    ) {
        $this->validateStrategy($strategy);
    }

    public function prune(array $messages): array
    {
        if (empty($messages)) {
            return [];
        }
        $tokenCount = $this->countTokens($messages);
        if ($tokenCount <= $this->maxTokens) {
            return $messages;
        }

        return match ($this->strategy) {
            'oldest' => $this->removeOldest($messages),
            'sliding' => $this->slidingWindow($messages),
        };
    }

    public function countTokens(array $messages): int
    {
        $totalChars = 0;
        foreach ($messages as $message) {
            if (isset($message['role'])) {
                $totalChars += strlen($message['role']);
            }
            if (isset($message['content'])) {
                if (is_string($message['content'])) {
                    $totalChars += strlen($message['content']);
                } elseif (is_array($message['content'])) {
                    foreach ($message['content'] as $block) {
                        if (isset($block['text']) && is_string($block['text'])) {
                            $totalChars += strlen($block['text']);
                        }
                    }
                }
            }
        }

        return (int) ceil($totalChars / self::CHARS_PER_TOKEN);
    }

    public function setMaxTokens(int $tokens): self
    {
        if ($tokens < 1) {
            throw new InvalidArgumentException('maxTokens must be at least 1');
        }
        $this->maxTokens = $tokens;

        return $this;
    }

    public function setStrategy(string $strategy): self
    {
        $this->validateStrategy($strategy);
        $this->strategy = $strategy;

        return $this;
    }

    private function removeOldest(array $messages): array
    {
        $systemMessage = null;
        $otherMessages = [];
        foreach ($messages as $message) {
            if (isset($message['role']) && $message['role'] === 'system' && $systemMessage === null) {
                $systemMessage = $message;
            } else {
                $otherMessages[] = $message;
            }
        }
        while (count($otherMessages) > 1) {
            $testMessages = $systemMessage ? [$systemMessage, ...$otherMessages] : $otherMessages;
            if ($this->countTokens($testMessages) <= $this->maxTokens) {
                break;
            }
            array_shift($otherMessages);
        }

        return $systemMessage ? [$systemMessage, ...$otherMessages] : $otherMessages;
    }

    private function slidingWindow(array $messages): array
    {
        $systemMessage = null;
        $otherMessages = [];
        foreach ($messages as $message) {
            if (isset($message['role']) && $message['role'] === 'system' && $systemMessage === null) {
                $systemMessage = $message;
            } else {
                $otherMessages[] = $message;
            }
        }
        $kept = [];
        $reversedMessages = array_reverse($otherMessages);
        foreach ($reversedMessages as $message) {
            $testMessages = $systemMessage ? [$systemMessage, ...$kept, $message] : [...$kept, $message];
            if ($this->countTokens($testMessages) > $this->maxTokens) {
                break;
            }
            array_unshift($kept, $message);
        }

        return $systemMessage ? [$systemMessage, ...$kept] : $kept;
    }

    private function validateStrategy(string $strategy): void
    {
        if (! in_array($strategy, self::VALID_STRATEGIES, true)) {
            throw new InvalidArgumentException(
                "Invalid strategy '{$strategy}'. Valid strategies: ".implode(', ', self::VALID_STRATEGIES)
            );
        }
    }
}
