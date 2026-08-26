<?php

declare(strict_types=1);

namespace Pagent\Exceptions;

/**
 * The library was set up incorrectly (missing API key, unknown adapter name,
 * invalid option value). Distinct from runtime API failures.
 */
class ConfigurationException extends InvalidArgumentException implements PagentException {}
