<?php

declare(strict_types=1);

namespace Pagent\Memory;

use Pagent\Exceptions\InvalidArgumentException;

use function array_slice;
use function count;
use function is_array;
use function json_encode;
use function serialize;
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

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    public function prune(array $messages): array
    {
        if (empty($messages)) {
            return [];
        }
        if ($this->countTokens($messages) <= $this->maxTokens) {
            return $messages;
        }

        return match ($this->strategy) {
            'oldest' => $this->removeOldest($messages),
            'sliding' => $this->slidingWindow($messages),
        };
    }

    /**
     * Estimate token count for a set of messages.
     *
     * Uses the serialized JSON length of each message so that tool calls,
     * tool results, JSON arguments, and other non-text content all count.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function countTokens(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $total += $this->estimateTokens($message);
        }

        return $total;
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

    /**
     * @param  array<string, mixed>  $message
     */
    private function estimateTokens(array $message): int
    {
        $json = json_encode($message);
        if ($json === false) {
            $json = serialize($message);
        }

        return (int) ceil(strlen($json) / self::CHARS_PER_TOKEN);
    }

    /**
     * Split messages into the first system message and prunable units.
     *
     * A unit is either a single message or an assistant tool-call exchange
     * (the tool_use/tool_calls message plus its tool results), which must
     * never be split apart or providers reject the message sequence.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{0: array<string, mixed>|null, 1: int, 2: list<array{messages: list<array<string, mixed>>, tokens: int, tool: bool}>}
     */
    private function partition(array $messages): array
    {
        $systemMessage = null;
        $systemTokens = 0;
        $units = [];

        foreach ($messages as $message) {
            if ($systemMessage === null && isset($message['role']) && $message['role'] === 'system') {
                $systemMessage = $message;
                $systemTokens = $this->estimateTokens($message);

                continue;
            }

            $tokens = $this->estimateTokens($message);
            $last = count($units) - 1;

            if ($last >= 0 && $units[$last]['tool'] && $this->isToolResult($message)) {
                $units[$last]['messages'][] = $message;
                $units[$last]['tokens'] += $tokens;

                continue;
            }

            $units[] = [
                'messages' => [$message],
                'tokens' => $tokens,
                'tool' => $this->hasToolCalls($message) || $this->isToolResult($message),
            ];
        }

        return [$systemMessage, $systemTokens, $units];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function hasToolCalls(array $message): bool
    {
        if (isset($message['tool_calls'])) {
            return true;
        }

        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isToolResult(array $message): bool
    {
        if (($message['role'] ?? null) === 'tool') {
            return true;
        }

        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function removeOldest(array $messages): array
    {
        [$systemMessage, $systemTokens, $units] = $this->partition($messages);

        $total = $systemTokens;
        foreach ($units as $unit) {
            $total += $unit['tokens'];
        }

        $offset = 0;
        while (count($units) - $offset > 1 && $total > $this->maxTokens) {
            $total -= $units[$offset]['tokens'];
            $offset++;
        }

        return $this->flatten($systemMessage, array_slice($units, $offset));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function slidingWindow(array $messages): array
    {
        [$systemMessage, $systemTokens, $units] = $this->partition($messages);

        $kept = [];
        $total = $systemTokens;
        for ($i = count($units) - 1; $i >= 0; $i--) {
            if ($total + $units[$i]['tokens'] > $this->maxTokens) {
                break;
            }
            $total += $units[$i]['tokens'];
            array_unshift($kept, $units[$i]);
        }

        return $this->flatten($systemMessage, $kept);
    }

    /**
     * @param  array<string, mixed>|null  $systemMessage
     * @param  list<array{messages: list<array<string, mixed>>, tokens: int, tool: bool}>  $units
     * @return array<int, array<string, mixed>>
     */
    private function flatten(?array $systemMessage, array $units): array
    {
        $result = $systemMessage !== null ? [$systemMessage] : [];
        foreach ($units as $unit) {
            foreach ($unit['messages'] as $message) {
                $result[] = $message;
            }
        }

        return $result;
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
