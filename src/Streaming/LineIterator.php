<?php

declare(strict_types=1);

namespace Pagent\Streaming;

use Generator;
use Pagent\Exceptions\InvalidArgumentException;

/**
 * Turns a blocking stream resource or incremental byte chunks into complete
 * newline-delimited records without assuming transport chunk boundaries.
 */
final class LineIterator
{
    /**
     * @param  resource|iterable<mixed>  $source
     * @return Generator<string>
     */
    public static function from(mixed $source): Generator
    {
        if (is_resource($source)) {
            while (! feof($source)) {
                $line = fgets($source);
                if ($line === false) {
                    break;
                }

                yield $line;
            }

            return;
        }

        if (! is_iterable($source)) {
            throw new InvalidArgumentException('Streaming parsers require a stream resource or iterable byte chunks.');
        }

        $buffer = '';

        foreach ($source as $chunk) {
            if (! is_string($chunk)) {
                throw new InvalidArgumentException('Streaming chunk iterables must yield strings.');
            }

            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n")) !== false) {
                yield substr($buffer, 0, $position + 1);
                $buffer = substr($buffer, $position + 1);
            }
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }
}
