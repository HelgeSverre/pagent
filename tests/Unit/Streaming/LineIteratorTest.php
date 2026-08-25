<?php

declare(strict_types=1);

use Pagent\Streaming\LineIterator;

test('LineIterator preserves records split across arbitrary transport chunks', function (): void {
    $chunks = (function () {
        yield "data: one\n\n";
        yield 'data: tw';
        yield "o\n";
        yield "\n";
    })();

    expect(iterator_to_array(LineIterator::from($chunks)))->toBe([
        "data: one\n",
        "\n",
        "data: two\n",
        "\n",
    ]);
});

test('LineIterator emits a final unterminated record', function (): void {
    expect(iterator_to_array(LineIterator::from(['last record'])))->toBe(['last record']);
});
