<?php

declare(strict_types=1);

namespace Pagent;

/**
 * Provider-adapter features, rather than a claim about every model a provider
 * may expose. Protocol is informational and stable enough for telemetry and
 * diagnostics; callers should make feature decisions through the booleans.
 */
final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $supportsStreaming = false,
        public bool $supportsTools = false,
        public bool $supportsSystemMessages = false,
        public bool $supportsStructuredOutput = false,
        public string $protocol = 'unknown',
        /** Canonical tool-schema format accepted at the provider boundary. */
        public string $toolProtocol = 'none',
    ) {}
}
