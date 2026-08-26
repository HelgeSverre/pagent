<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;

use function trim;

/**
 * Frames a byte stream into complete Server-Sent Events blocks.
 *
 * Companion to LineIterator: events are separated by blank lines; the final
 * partial block (a stream ending without a trailing blank line) is yielded too.
 */
final class SseEventIterator
{
    /**
     * @param  resource|iterable<string>  $source
     * @return Generator<string> raw event blocks (one or more "field: value" lines)
     */
    public static function from(mixed $source): Generator
    {
        $buffer = '';

        foreach (LineIterator::from($source) as $line) {
            $buffer .= $line;

            if (trim($line) === '') {
                if (trim($buffer) !== '') {
                    yield $buffer;
                }
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            yield $buffer;
        }
    }
}
