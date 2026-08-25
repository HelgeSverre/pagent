<?php

declare(strict_types=1);

namespace Pagent\Contracts;

use Pagent\Streaming\StreamResponse;

/**
 * A provider that can produce an incremental response for a prompt.
 */
interface StreamingProvider extends Provider
{
    public function streamPrompt(string $message, array $options = []): StreamResponse;
}
